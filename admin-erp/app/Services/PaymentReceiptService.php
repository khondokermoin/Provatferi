<?php

namespace App\Services;

use App\Models\ApprovalHistory;
use App\Models\Member;
use App\Models\Membership;
use App\Models\MembershipApplication;
use App\Models\MembershipDueAllocation;
use App\Models\MembershipType;
use App\Models\Payment;
use App\Models\PaymentReceipt;
use App\Models\Setting;
use App\Models\User;
use App\Support\AdminTime;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use LogicException;

/**
 * THE OFFICIAL RECEIPT (Membership task 5, 2026-10-08; docs/MEMBERSHIP_RECEIPTS.md).
 *
 * A receipt exists for a payment that is VERIFIED money — status `paid`, verified, an amount above zero — and for
 * nothing else: not a recorded-but-unverified payment, not a cancelled one, never a waiver (a waiver is not money;
 * the registration waiver is stored as a payment row with status `waived` and a verification stamp, which is exactly why
 * "verified" alone is not the test), not a payment of nothing.
 *
 * issueFor() is called by BOTH verification paths (the registration fee: ApplicationPaymentService; the monthly
 * contributions, advances and voluntary gifts: MembershipDueLedger) inside the transaction that verifies the payment,
 * with the payment's row locked. Consequently:
 *   - a receipt number is taken from the `receipt:{year}` counter only when the verification commits; a failed or rolled
 *     back verification consumes nothing, a pending or cancelled payment never reaches this code;
 *   - verifying twice, two admins at once, a retried request: the payment is already verified, nothing calls this a
 *     second time — and if it were called again it returns the existing receipt (UNIQUE payment_id), no new number;
 *   - the number never derives from an id or a count: App\Services\MembershipNumbering::issueReceiptNumber().
 *
 * What the receipt shows is stored in the receipt row AT THAT MOMENT (the snapshot) — see PaymentReceipt.
 */
final class PaymentReceiptService
{
    public function __construct(private readonly MembershipNumbering $numbering)
    {
    }

    /** Verified money above zero, towards a membership or an application: the only thing a receipt is issued for. */
    public function isReceiptable(Payment $payment): bool
    {
        return $payment->status === 'paid'
            && $payment->verified_at !== null
            && Money::isPositive($payment->amount_received === null ? null : (string) $payment->amount_received)
            && in_array($payment->payable_type, [Membership::class, MembershipApplication::class], true);
    }

    /** registration | monthly | advance | voluntary | other — what the money was for (see PaymentReceipt). */
    public function purposeOf(Payment $payment): string
    {
        return match ($payment->category) {
            Payment::CATEGORY_REGISTRATION => 'registration',
            Payment::CATEGORY_MONTHLY => $payment->membership_due_id !== null ? 'monthly' : 'advance',
            Payment::CATEGORY_VOLUNTARY => 'voluntary',
            default => 'other',
        };
    }

    /**
     * Issues the receipt of a payment that has JUST been verified. Call it inside the verifying transaction, last (so the
     * counter row is locked as briefly as possible), with the payment row locked and its allocations final.
     * Returns the receipt — the existing one if there already is one — or null when the payment is not receiptable.
     */
    public function issueFor(Payment $payment, User $by): ?PaymentReceipt
    {
        if (! $this->isReceiptable($payment)) {
            return null;
        }
        $existing = PaymentReceipt::query()->where('payment_id', $payment->id)->first();
        if ($existing !== null) {
            return $existing;
        }

        $payable = $payment->payable;
        if ($payable instanceof MembershipApplication) {
            $application = $payable;
            $membership = $payable->membership;
        } elseif ($payable instanceof Membership) {
            $membership = $payable;
            $application = $payable->application;
        } else {
            throw new LogicException("Payment {$payment->id} belongs to nothing a receipt can be issued for.");
        }

        $purpose = $this->purposeOf($payment);
        $amount = Money::parse((string) $payment->amount_received) ?? throw new LogicException("Payment {$payment->id} has no valid amount.");
        [$lines, $applied, $credit] = $this->applications($payment, $purpose, $amount);
        $type = MembershipType::query()->find($payment->membership_type_id ?? $membership?->membership_type_id ?? $application?->membership_type_id);
        $verifiedOn = AdminTime::local($payment->verified_at)?->toDateString() ?? AdminTime::today();

        $receipt = PaymentReceipt::query()->create([
            // Last, once everything else is ready: the counter row stays locked until this transaction ends.
            'receipt_no' => $this->numbering->issueReceiptNumber($verifiedOn),
            'payment_id' => $payment->id,
            'purpose' => $purpose,
            'amount' => $amount,
            'applied_amount' => $applied,
            'credit_amount' => $credit,
            'lines' => $lines,
            'payment_date' => $payment->received_at?->toDateString() ?? $verifiedOn,
            'method' => $payment->method,
            'reference' => $payment->reference,
            'payer_name' => $payable instanceof MembershipApplication
                ? (string) $application->applicant_name
                : ($membership->holderName() ?: (string) $application?->applicant_name),
            'member_code' => $membership?->member_code,
            'application_no' => $payable instanceof MembershipApplication ? $application->application_no : null,
            'membership_type_name' => $type?->name,
            'membership_type_name_en' => $type?->name_en,
            'received_by_name' => $payment->receivedBy?->name,
            'verified_by_name' => $by->name,
            'institution' => $this->institution(),
            'issued_by' => $by->id,
            'issued_at' => $payment->verified_at,
            'issued_via' => 'verification',
        ]);

        // One audit entry per receipt — never one per download. On the record the payment's own history is on.
        ApprovalHistory::record($payable instanceof MembershipApplication ? $application : $membership, 'receipt_issued', $by, (string) json_encode(['receipt' => [
            'no' => $receipt->receipt_no, 'payment_id' => $payment->id, 'purpose' => $purpose, 'amount' => $amount, 'via' => 'verification',
        ]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return $receipt;
    }

    /**
     * Where the money went, as of now: for a monthly payment or an advance, the months its allocations reached (summed
     * per month, oldest first) and what is still unallocated — advance credit; for a registration fee or a gift, all of it
     * went to the purpose itself. applied + credit always equals the amount.
     *
     * @return array{0: array<int, array{period: string, amount: string}>|null, 1: string, 2: string} lines, applied, credit
     */
    private function applications(Payment $payment, string $purpose, string $amount): array
    {
        if (! in_array($purpose, ['monthly', 'advance'], true)) {
            return [null, $amount, '0.00'];
        }

        $paisaByPeriod = [];
        foreach (MembershipDueAllocation::query()->where('payment_id', $payment->id)->with('due:id,period_year,period_month')->get() as $allocation) {
            $period = $allocation->due->period();
            $paisaByPeriod[$period] = ($paisaByPeriod[$period] ?? 0) + (int) Money::toPaisa((string) $allocation->amount);
        }
        ksort($paisaByPeriod);

        $appliedPaisa = array_sum($paisaByPeriod);
        $creditPaisa = (int) Money::toPaisa($amount) - $appliedPaisa;
        if ($creditPaisa < 0) {
            throw new LogicException("Payment {$payment->id}: more was applied to months ({$appliedPaisa}) than was received.");
        }

        $lines = [];
        foreach ($paisaByPeriod as $period => $paisa) {
            $lines[] = ['period' => $period, 'amount' => Money::fromPaisa($paisa)];
        }

        return [$lines === [] ? null : $lines, Money::fromPaisa($appliedPaisa), Money::fromPaisa($creditPaisa)];
    }

    /**
     * The letterhead as it is today — stored in the receipt, so a later change of the institution's address or phone
     * does not rewrite an old receipt. (The logo is a brand file, not data.) Fallbacks only for an install with no
     * settings stored.
     *
     * @return array<string, string|null>
     */
    private function institution(): array
    {
        $get = function (string $key, ?string $default = null): ?string {
            $value = Setting::get($key);

            return is_string($value) && trim($value) !== '' ? trim($value) : $default;
        };

        return [
            'name_bn' => $get('site.name_bn', 'প্রভাতফেরী সাহিত্য ও সাংস্কৃতিক কেন্দ্র'),
            'name_en' => $get('site.name_en', 'Provatferi Literary and Cultural Center'),
            'acronym' => $get('site.acronym', 'PLCC'),
            'phone' => $get('site.phone'),
            'email' => $get('site.email'),
            'address' => $get('site.address'),
            'website' => $get('site.website_url'),
        ];
    }

    /**
     * The receipts that belong to a member: those of payments towards any of their memberships, and the registration
     * fees paid on the applications those memberships came from. Nobody else's — a member reaches a receipt only
     * through this query.
     *
     * @return Builder<PaymentReceipt>
     */
    public function forMember(Member $member): Builder
    {
        $memberships = Membership::query()->where('member_id', $member->id);
        $membershipIds = (clone $memberships)->pluck('id');
        $applicationIds = (clone $memberships)->whereNotNull('membership_application_id')->pluck('membership_application_id');

        return PaymentReceipt::query()->whereHas('payment', fn (Builder $payment) => $payment->where(fn (Builder $either) => $either
            ->where(fn (Builder $own) => $own->where('payable_type', Membership::class)->whereIn('payable_id', $membershipIds))
            ->orWhere(fn (Builder $registration) => $registration->where('payable_type', MembershipApplication::class)->whereIn('payable_id', $applicationIds))));
    }
}
