<?php

namespace App\Services;

use App\Models\ApprovalHistory;
use App\Models\Membership;
use App\Models\MembershipDue;
use App\Models\MembershipDueAllocation;
use App\Models\Payment;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * THE MONTHLY DUES LEDGER (Membership task 4; docs/MEMBERSHIP_DUES.md): generating dues, and the money against them.
 *
 * Every write here runs in a transaction that FIRST locks the membership's row (SELECT … FOR UPDATE), so everything
 * about one membership's ledger — generation, verification, credit, waivers — happens one operation at a time, however
 * many admins, crons and commands act at once. The database backs it up: one due per membership per month (UNIQUE), and
 * amounts that can never go negative or exceed what was assessed (CHECK).
 *
 *  - generateFor() creates every due the membership owes (MembershipDueSchedule) that does not exist yet — never a
 *    second one for a month, never a future month, never a zero one — and applies waiting credit. Run it once or ten
 *    times, alone or concurrently: the same ledger.
 *  - Money counts only once VERIFIED. recordPayment() changes no due; verifyPayment() applies the payment to the month
 *    it was recorded for (never more than that month still owes) and the rest becomes ADVANCE CREDIT.
 *  - Advance credit — verified monthly money not yet applied — is applied automatically to the OLDEST outstanding month
 *    first, oldest money first: whenever a payment is verified and whenever dues are generated. Credit therefore never
 *    waits while a month is owed, and an advance pays future months as their dues are created. Nothing is ever lost:
 *    every taka of a verified monthly payment is either on a due or still credit.
 *  - A waiver (an admin, a reason) reduces what a month owes; it is not a payment and creates none.
 *  - An unverified entry recorded in error is cancelled, never deleted. Voluntary contributions are recorded and
 *    verified like the rest but never applied to dues.
 */
final class MembershipDueLedger
{
    public function __construct(
        private readonly MembershipDueSchedule $schedule,
        private readonly MembershipFeePolicyService $fees,
    ) {
    }

    // ------------------------------------------------------------------ generation

    /**
     * Creates every due the membership owes from its joining month through the current month that does not exist yet,
     * then applies waiting credit. Returns the dues it created (none on a repeat).
     *
     * @return array<int, MembershipDue>
     */
    public function generateFor(Membership|int $membership, ?User $by = null): array
    {
        $id = $membership instanceof Membership ? (int) $membership->getKey() : $membership;

        return DB::transaction(function () use ($id, $by): array {
            $locked = Membership::query()->whereKey($id)->lockForUpdate()->first();
            if ($locked === null) {
                return [];
            }

            $existing = $locked->dues()->lockForUpdate()->get(['id', 'period_year', 'period_month'])
                ->mapWithKeys(fn (MembershipDue $d) => [MembershipDueSchedule::key($d->period_year, $d->period_month) => true]);

            $created = [];
            foreach ($this->schedule->accruingPeriods($locked) as [$year, $month]) {
                if (isset($existing[MembershipDueSchedule::key($year, $month)])) {
                    continue; // already assessed — never reassessed
                }
                $policy = $this->schedule->policyFor($locked, $year, $month);
                if ($policy === null || ! Money::isPositive((string) $policy->monthly_contribution)) {
                    continue; // nothing is owed for this month: no due at all, never a fake unpaid one
                }
                $created[] = MembershipDue::query()->create([
                    'membership_id' => $locked->id,
                    'membership_fee_policy_id' => $policy->id,
                    'period_year' => $year,
                    'period_month' => $month,
                    'due_date' => MembershipDueSchedule::lastDay($year, $month),
                    'amount' => Money::parse((string) $policy->monthly_contribution),
                    'status' => 'due',
                    'generated_at' => now(),
                ]);
            }

            if ($created !== []) {
                ApprovalHistory::record($locked, 'dues_generated', $by, self::json([
                    'dues' => array_map(fn (MembershipDue $d) => [$d->period(), Money::parse((string) $d->amount)], $created),
                ]), $by ? 'admin' : 'system');
            }
            $this->applyCredit($locked, $by);

            return $created;
        }, 3);
    }

    /**
     * generateFor() for every membership — the daily cron (membership:generate-dues) and the registry's button. One
     * transaction per membership, so one failure never undoes the others' work.
     *
     * @return array{memberships: int, created: int}
     */
    public function generateAll(?User $by = null): array
    {
        $report = ['memberships' => 0, 'created' => 0];
        foreach (Membership::query()->orderBy('id')->pluck('id') as $id) {
            $report['memberships']++;
            $report['created'] += count($this->generateFor((int) $id, $by));
        }

        return $report;
    }

    // ------------------------------------------------------------------ payments

    /**
     * Records money received towards a membership: for one month (`purpose` due + `due_id`), as an advance, or as a
     * voluntary contribution. It changes no due — only verification does. More than a month still owes is accepted only
     * when the admin says the rest is advance credit (`keep_rest_as_credit`).
     *
     * @param  array<string, mixed>  $input  amount, received_at (Y-m-d), method, reference, purpose, due_id, keep_rest_as_credit
     *
     * @throws ValidationException
     */
    public function recordPayment(Membership $membership, User $by, array $input): Payment
    {
        $amount = Money::parse($input['amount'] ?? null);
        if (! Money::isPositive($amount)) {
            throw ValidationException::withMessages(['amount' => __('admin.dues.errors.amount')]);
        }
        $purpose = in_array($input['purpose'] ?? null, ['due', 'advance', 'voluntary'], true) ? $input['purpose'] : 'due';
        $method = in_array($input['method'] ?? null, Payment::METHODS, true) ? $input['method'] : 'cash';
        $reference = trim((string) ($input['reference'] ?? '')) ?: null;
        $receivedAt = (string) ($input['received_at'] ?? $this->fees->today());

        $due = null;
        if ($purpose === 'due') {
            $due = $membership->dues()->whereKey($input['due_id'] ?? 0)->first();
            if ($due === null) {
                throw ValidationException::withMessages(['due_id' => __('admin.dues.errors.due_required')]);
            }
            if ($due->outstandingPaisa() === 0) {
                throw ValidationException::withMessages(['due_id' => __('admin.dues.errors.due_settled')]);
            }
            if (Money::toPaisa($amount) > $due->outstandingPaisa() && empty($input['keep_rest_as_credit'])) {
                throw ValidationException::withMessages(['amount' => __('admin.dues.errors.more_than_owed', ['owed' => bn_money($due->outstanding())])]);
            }
        }

        return DB::transaction(function () use ($membership, $by, $amount, $purpose, $method, $reference, $receivedAt, $due): Payment {
            $payment = $membership->payments()->create([
                'category' => $purpose === 'voluntary' ? Payment::CATEGORY_VOLUNTARY : Payment::CATEGORY_MONTHLY,
                'membership_due_id' => $due?->id,
                'membership_type_id' => $membership->membership_type_id,
                'amount_expected' => $due !== null ? $due->outstanding() : $amount,
                'amount_received' => $amount,
                'method' => $method,
                'received_at' => $receivedAt,
                'received_by' => $by->id,
                'reference' => $reference,
                'status' => 'paid', // received — it counts only once verified (verified_at)
            ]);
            ApprovalHistory::record($membership, 'monthly_payment_recorded', $by, self::json(['monthly_payment' => [
                'amount' => $amount, 'purpose' => $purpose, 'period' => $due?->period(), 'method' => $method, 'reference' => $reference,
            ]]));

            return $payment;
        });
    }

    /**
     * Verifies a recorded monthly or voluntary payment — once: a repeat, a double click or a second admin at the same
     * moment finds it verified and changes nothing. A monthly payment then goes to its own month (at most what that month
     * still owes); the rest is advance credit, applied to the oldest outstanding month first.
     *
     * @return string 'verified' | 'already' | 'not_allowed'
     */
    public function verifyPayment(Payment $payment, User $by): string
    {
        if ($payment->payable_type !== Membership::class || ! in_array($payment->category, [Payment::CATEGORY_MONTHLY, Payment::CATEGORY_VOLUNTARY], true)) {
            return 'not_allowed';
        }

        return DB::transaction(function () use ($payment, $by): string {
            $membership = Membership::query()->whereKey($payment->payable_id)->lockForUpdate()->firstOrFail();
            $locked = Payment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();
            if ($locked->verified_at !== null) {
                return 'already';
            }
            if ($locked->status !== 'paid') {
                return 'not_allowed'; // cancelled
            }
            $locked->forceFill(['verified_at' => now(), 'verified_by' => $by->id])->save();

            $applied = null;
            $due = null;
            if ($locked->category === Payment::CATEGORY_MONTHLY && $locked->membership_due_id !== null) {
                $due = MembershipDue::query()->whereKey($locked->membership_due_id)->where('membership_id', $membership->id)->lockForUpdate()->first();
                $take = $due === null ? 0 : min(Money::toPaisa((string) $locked->amount_received) ?? 0, $due->outstandingPaisa());
                if ($take > 0) {
                    $this->allocate($membership, $due, $locked, $take, 'payment', $by);
                    $applied = Money::fromPaisa($take);
                }
            }
            ApprovalHistory::record($membership, 'monthly_payment_verified', $by, self::json(['monthly_payment' => [
                'amount' => Money::parse((string) $locked->amount_received),
                'purpose' => $locked->category === Payment::CATEGORY_VOLUNTARY ? 'voluntary' : ($locked->membership_due_id ? 'due' : 'advance'),
                'period' => $due?->period(),
                'applied' => $applied,
                'reference' => $locked->reference,
            ]]));

            if ($locked->category === Payment::CATEGORY_MONTHLY) {
                $this->applyCredit($membership, $by);
            }

            return 'verified';
        }, 3);
    }

    /**
     * Cancels an unverified monthly or voluntary entry recorded in error (kept, marked cancelled, with the reason).
     *
     * @return string 'cancelled' | 'already' | 'not_allowed'
     */
    public function cancelPayment(Payment $payment, User $by, string $reason): string
    {
        if ($payment->payable_type !== Membership::class || ! in_array($payment->category, [Payment::CATEGORY_MONTHLY, Payment::CATEGORY_VOLUNTARY], true)) {
            return 'not_allowed';
        }

        return DB::transaction(function () use ($payment, $by, $reason): string {
            $membership = Membership::query()->whereKey($payment->payable_id)->lockForUpdate()->firstOrFail();
            $locked = Payment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === 'cancelled') {
                return 'already';
            }
            if (! $locked->isAwaitingVerification()) {
                return 'not_allowed'; // verified money is never "cancelled" away
            }
            $locked->forceFill(['status' => 'cancelled', 'cancelled_at' => now(), 'cancelled_by' => $by->id, 'cancellation_reason' => $reason])->save();
            ApprovalHistory::record($membership, 'monthly_payment_cancelled', $by, self::json(['monthly_payment' => [
                'amount' => Money::parse((string) $locked->amount_received), 'period' => $locked->due?->period(), 'reason' => $reason,
            ]]));

            return 'cancelled';
        }, 3);
    }

    // ------------------------------------------------------------------ waivers

    /**
     * Waives what a month still owes ($amount null) or an explicit part of it. A reason is required; the waiver is
     * recorded with who and when. It is not a payment and creates none.
     *
     * @return string 'waived' | 'nothing_owed'
     *
     * @throws ValidationException
     */
    public function waive(MembershipDue $due, User $by, ?string $amount, string $reason): string
    {
        return DB::transaction(function () use ($due, $by, $amount, $reason): string {
            Membership::query()->whereKey($due->membership_id)->lockForUpdate()->firstOrFail();
            $locked = MembershipDue::query()->whereKey($due->id)->lockForUpdate()->firstOrFail();
            $owed = $locked->outstandingPaisa();
            if ($owed === 0) {
                return 'nothing_owed';
            }
            $paisa = $amount === null || trim($amount) === '' ? $owed : Money::toPaisa($amount);
            if ($paisa === null || $paisa <= 0 || $paisa > $owed) {
                throw ValidationException::withMessages(['waive_amount' => __('admin.dues.errors.waive_amount', ['owed' => bn_money(Money::fromPaisa($owed))])]);
            }

            $locked->waived_amount = Money::fromPaisa($locked->waivedPaisa() + $paisa);
            $locked->settleStatus();
            $locked->save();
            ApprovalHistory::record($locked, 'waived', $by, self::json(['waiver' => [
                'period' => $locked->period(), 'amount' => Money::fromPaisa($paisa), 'reason' => $reason,
            ]]));

            return 'waived';
        }, 3);
    }

    // ------------------------------------------------------------------ reading

    /**
     * Where a membership stands, for the member page and the member portal.
     *
     * @return array{today: string, current_period: array{0: int, 1: int}, current_amount: ?string, accruing: bool,
     *   current_due: ?MembershipDue, month_state: string, standing: string, outstanding: string, overdue_count: int,
     *   credit: string, next: ?array{period: array{0: int, 1: int}, amount: ?string}, dues: Collection<int, MembershipDue>}
     */
    public function summary(Membership $membership): array
    {
        $today = $this->fees->today();
        $current = MembershipDueSchedule::periodOf($today);
        $dues = $membership->dues()->orderByDesc('period_year')->orderByDesc('period_month')->get();
        $currentDue = $dues->first(fn (MembershipDue $d) => [$d->period_year, $d->period_month] === $current);
        $accruing = $membership->status === 'active';
        $next = MembershipDueSchedule::next(...$current);
        $joining = $this->schedule->joiningPeriod($membership);

        return [
            'today' => $today,
            'current_period' => $current,
            'current_amount' => $joining !== null && $joining <= $current ? $this->schedule->amountFor($membership, ...$current) : null,
            'accruing' => $accruing,
            'current_due' => $currentDue,
            'month_state' => $currentDue?->displayState($today) ?? 'not_required',
            'standing' => self::standing($dues, $today, $current),
            'outstanding' => Money::fromPaisa((int) $dues->sum(fn (MembershipDue $d) => $d->outstandingPaisa())),
            'overdue_count' => $dues->filter(fn (MembershipDue $d) => $d->isOverdue($today))->count(),
            'credit' => Money::fromPaisa(array_sum($this->unallocated($membership, lock: false))),
            'next' => $accruing ? ['period' => $next, 'amount' => $this->schedule->amountFor($membership, ...$next)] : null,
            'dues' => $dues,
        ];
    }

    /**
     * One word for where a membership stands, most urgent first: overdue (a past month still owed) · partially_paid /
     * due (this month, part or nothing paid) · current (this month settled, nothing overdue) · not_required (no due this
     * month and nothing owed — a zero monthly contribution, a paused membership).
     *
     * @param  Collection<int, MembershipDue>  $dues
     * @param  array{0: int, 1: int}  $current
     */
    public static function standing(Collection $dues, string $today, array $current): string
    {
        if ($dues->contains(fn (MembershipDue $d) => $d->isOverdue($today))) {
            return 'overdue';
        }
        $thisMonth = $dues->first(fn (MembershipDue $d) => [$d->period_year, $d->period_month] === $current);
        if ($thisMonth === null) {
            return 'not_required';
        }
        if ($thisMonth->outstandingPaisa() > 0) {
            return $thisMonth->paidPaisa() > 0 ? 'partially_paid' : 'due';
        }

        return 'current';
    }

    // ------------------------------------------------------------------ internals

    /**
     * Applies advance credit to outstanding dues, oldest month first, oldest money first. Called inside a transaction
     * holding the membership's row lock.
     *
     * @return array<int, array{0: string, 1: string}> [month, amount] applied
     */
    private function applyCredit(Membership $membership, ?User $by): array
    {
        $available = $this->unallocated($membership, lock: true);
        if (array_sum($available) === 0) {
            return [];
        }
        $payments = Payment::query()->whereKey(array_keys($available))->lockForUpdate()->get()->keyBy('id');
        $dues = $membership->dues()->where('outstanding_amount', '>', 0)
            ->orderBy('period_year')->orderBy('period_month')->lockForUpdate()->get();

        $applied = [];
        foreach ($dues as $due) {
            foreach ($available as $paymentId => $left) {
                $need = $due->outstandingPaisa();
                if ($need === 0) {
                    break;
                }
                if ($left === 0) {
                    continue;
                }
                $take = min($need, $left);
                $this->allocate($membership, $due, $payments[$paymentId], $take, 'credit', $by);
                $available[$paymentId] -= $take;
                $applied[$due->period()] = ($applied[$due->period()] ?? 0) + $take;
            }
        }

        if ($applied !== []) {
            ApprovalHistory::record($membership, 'credit_applied', $by, self::json([
                'credit' => array_map(fn ($period, $paisa) => [$period, Money::fromPaisa($paisa)], array_keys($applied), $applied),
            ]), $by ? 'admin' : 'system');
        }

        return array_map(fn ($period, $paisa) => [$period, Money::fromPaisa($paisa)], array_keys($applied), $applied);
    }

    /**
     * What is left of each verified monthly payment of the membership, oldest first: [payment id => paisa], only those
     * with something left. The sum is the membership's advance credit.
     *
     * @return array<int, int>
     */
    private function unallocated(Membership $membership, bool $lock): array
    {
        $payments = Payment::query()
            ->where('payable_type', Membership::class)->where('payable_id', $membership->id)
            ->where('category', Payment::CATEGORY_MONTHLY)->where('status', 'paid')->whereNotNull('verified_at')
            ->orderBy('verified_at')->orderBy('id')
            ->when($lock, fn ($q) => $q->lockForUpdate())
            ->get(['id', 'amount_received']);
        if ($payments->isEmpty()) {
            return [];
        }

        $allocated = MembershipDueAllocation::query()->whereIn('payment_id', $payments->pluck('id'))
            ->when($lock, fn ($q) => $q->lockForUpdate())
            ->get(['payment_id', 'amount'])
            ->groupBy('payment_id')
            ->map(fn (Collection $rows) => (int) $rows->sum(fn ($r) => Money::toPaisa((string) $r->amount) ?? 0));

        $left = [];
        foreach ($payments as $payment) {
            $remaining = (Money::toPaisa((string) $payment->amount_received) ?? 0) - (int) ($allocated[$payment->id] ?? 0);
            if ($remaining > 0) {
                $left[$payment->id] = $remaining;
            }
        }

        return $left;
    }

    private function allocate(Membership $membership, MembershipDue $due, Payment $payment, int $paisa, string $kind, ?User $by): void
    {
        MembershipDueAllocation::query()->create([
            'membership_id' => $membership->id,
            'membership_due_id' => $due->id,
            'payment_id' => $payment->id,
            'amount' => Money::fromPaisa($paisa),
            'kind' => $kind,
            'allocated_by' => $by?->id,
            'allocated_at' => now(),
        ]);
        $due->paid_amount = Money::fromPaisa($due->paidPaisa() + $paisa);
        $due->settleStatus();
        $due->save();
    }

    /** @param array<string, mixed> $data */
    private static function json(array $data): string
    {
        return (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
