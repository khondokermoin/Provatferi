<?php

namespace Tests\Feature\Admin;

use App\Models\ApprovalHistory;
use App\Models\Membership;
use App\Models\MembershipDue;
use App\Models\MembershipDueAllocation;
use App\Models\MembershipFeePolicy;
use App\Models\MembershipType;
use App\Models\Payment;
use App\Models\User;
use App\Services\MembershipDueLedger;
use App\Services\MembershipDueSchedule;
use App\Services\MembershipFeePolicyService;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\MakesMembershipTypes;
use Tests\TestCase;

/**
 * Membership task 4 (2026-10-08): the dues ledger under REAL concurrency — section 15 of the owner's order. Each case
 * starts separate PHP processes (tests/Support/dues-worker.php), each with its own database connection, released at
 * the same instant, all acting on one membership's ledger.
 *
 * The workers see only COMMITTED rows, so this class does not use RefreshDatabase's wrapping transaction: it commits a
 * small fixture (a type, a membership that joined six months ago, an admin) and removes all of it in tearDown, leaving
 * the test database as empty as it found it.
 */
class MembershipDuesConcurrencyTest extends TestCase
{
    use MakesMembershipTypes;

    private MembershipType $type;

    private Membership $membership;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        if (! RefreshDatabaseState::$migrated) {
            $this->artisan('migrate:fresh');
            RefreshDatabaseState::$migrated = true;
        }

        $this->type = $this->makeMembershipType(['name' => 'চাঁদা সমকালীন পরীক্ষা', 'code' => 'DQ', 'slug' => 'dues-concurrency-'.uniqid()], ['registration' => '0', 'monthly' => '200']);
        $this->admin = User::factory()->create(['email' => 'dues-concurrency-'.uniqid().'@example.test', 'status' => 'active']);

        // Joined five months before this month: six months owed, nothing generated yet.
        $fees = app(MembershipFeePolicyService::class);
        $joined = Carbon::parse($fees->today())->startOfMonth()->subMonths(5)->addDays(4)->toDateString();
        $this->membership = Membership::query()->create([
            'membership_type_id' => $this->type->id, 'member_code' => 'PLCC-DQ-TEST-'.uniqid(), 'start_date' => $joined, 'status' => 'active',
        ]);
        ApprovalHistory::record($this->membership, 'created', $this->admin, 'test')
            ->forceFill(['created_at' => Carbon::parse($joined.' 10:00:00', $fees->timezone())->utc()])->save();
    }

    protected function tearDown(): void
    {
        try {
            $dueIds = MembershipDue::query()->where('membership_id', $this->membership->id)->pluck('id');
            $paymentIds = Payment::query()->where('payable_type', Membership::class)->where('payable_id', $this->membership->id)->pluck('id');
            // Verification now issues an official receipt (task 5); the model refuses to delete one on purpose, so the
            // fixture goes straight through the query builder — receipts first (restrict FK), and the counter they advanced.
            DB::table('payment_receipts')->whereIn('payment_id', $paymentIds)->delete();
            DB::table('number_sequences')->where('sequence_key', 'like', 'receipt:%')->delete();
            MembershipDueAllocation::query()->where('membership_id', $this->membership->id)->delete();
            Payment::query()->whereIn('id', $paymentIds)->delete();
            ApprovalHistory::query()->where('subject_type', MembershipDue::class)->whereIn('subject_id', $dueIds)->delete();
            MembershipDue::query()->where('membership_id', $this->membership->id)->delete();
            ApprovalHistory::query()->where('subject_type', Membership::class)->where('subject_id', $this->membership->id)->delete();
            $this->membership->delete();
            MembershipFeePolicy::query()->where('membership_type_id', $this->type->id)->delete();
            $this->type->delete();
            $this->admin->forceDelete();
        } finally {
            parent::tearDown();
        }
    }

    public function test_two_generators_at_the_same_moment_create_each_month_once(): void
    {
        $expected = count(app(MembershipDueSchedule::class)->accruingPeriods($this->membership));

        $results = $this->race([['generate', (string) $this->membership->id], ['generate', (string) $this->membership->id]]);

        $this->assertCount(2, array_filter($results, fn ($r) => $r['ok'] === true), json_encode($results));
        $this->assertSame($expected, array_sum(array_column($results, 'created')), 'between them, every month created exactly once');
        $this->assertLedgerHasEachMonthOnce($expected);
    }

    public function test_an_admins_generation_and_the_daily_command_at_the_same_moment_create_each_month_once(): void
    {
        $expected = count(app(MembershipDueSchedule::class)->accruingPeriods($this->membership));

        $results = $this->race([['generate', (string) $this->membership->id, (string) $this->admin->id], ['command', '-']]);

        $this->assertCount(2, array_filter($results, fn ($r) => $r['ok'] === true), json_encode($results));
        $this->assertLedgerHasEachMonthOnce($expected);
    }

    public function test_one_payment_verified_by_three_admins_at_once_is_applied_once(): void
    {
        $ledger = app(MembershipDueLedger::class);
        $ledger->generateFor($this->membership);
        $oldest = $this->membership->dues()->orderBy('period_year')->orderBy('period_month')->firstOrFail();
        $payment = $ledger->recordPayment($this->membership, $this->admin, ['purpose' => 'due', 'due_id' => $oldest->id, 'amount' => '200', 'method' => 'cash']);

        $results = $this->race(array_fill(0, 3, ['verify', (string) $payment->id, (string) $this->admin->id]));

        $this->assertSame(['already', 'already', 'verified'], collect($results)->pluck('outcome')->sort()->values()->all(), json_encode($results));
        $this->assertSame(['paid', '200.00'], [$oldest->fresh()->status, $oldest->fresh()->paid_amount]);
        $this->assertSame(1, MembershipDueAllocation::query()->where('payment_id', $payment->id)->count());
        $this->assertSame(1, ApprovalHistory::query()->where('subject_id', $this->membership->id)->where('action', 'monthly_payment_verified')->count());
        $this->assertLedgerAddsUp();
    }

    public function test_four_payments_for_one_month_verified_at_once_never_overpay_it(): void
    {
        $ledger = app(MembershipDueLedger::class);
        $ledger->generateFor($this->membership);
        $oldest = $this->membership->dues()->orderBy('period_year')->orderBy('period_month')->firstOrFail();
        $payments = [];
        foreach (range(1, 4) as $i) {
            $payments[] = $ledger->recordPayment($this->membership, $this->admin, ['purpose' => 'due', 'due_id' => $oldest->id, 'amount' => '100', 'method' => 'cash']);
        }

        $results = $this->race(array_map(fn (Payment $p) => ['verify', (string) $p->id, (string) $this->admin->id], $payments));

        $this->assertSame(['verified', 'verified', 'verified', 'verified'], array_column($results, 'outcome'), json_encode($results));
        $this->assertSame(['paid', '200.00', '0.00'], [$oldest->fresh()->status, $oldest->fresh()->paid_amount, $oldest->fresh()->outstanding()], 'the month took exactly what it owed — never 400');
        $allocated = (int) MembershipDueAllocation::query()->where('membership_id', $this->membership->id)->get()->sum(fn ($a) => Money::toPaisa((string) $a->amount));
        $this->assertSame(40000, $allocated, 'every taka of the four payments went somewhere: 200 to that month, 200 as credit to the next month owed');
        $this->assertSame('200.00', $this->membership->dues()->orderBy('period_year')->orderBy('period_month')->skip(1)->firstOrFail()->paid_amount);
        $this->assertLedgerAddsUp();
    }

    /* ---------------------------------------------------------------- helpers */

    /** Every due's paid amount is exactly what was allocated to it (no lost update), and nothing is negative. */
    private function assertLedgerAddsUp(): void
    {
        foreach (MembershipDue::query()->where('membership_id', $this->membership->id)->get() as $due) {
            $allocated = (int) MembershipDueAllocation::query()->where('membership_due_id', $due->id)->get()->sum(fn ($a) => Money::toPaisa((string) $a->amount));
            $this->assertSame($allocated, $due->paidPaisa(), "due {$due->period()}: paid amount equals its allocations");
            $this->assertGreaterThanOrEqual(0, $due->outstandingPaisa(), 'no outstanding balance is ever negative');
        }
    }

    private function assertLedgerHasEachMonthOnce(int $expected): void
    {
        $periods = MembershipDue::query()->where('membership_id', $this->membership->id)->get()->map(fn (MembershipDue $d) => $d->period());
        $this->assertSame($expected, $periods->count(), $periods->implode(', '));
        $this->assertSame($expected, $periods->unique()->count(), 'no month twice');
    }

    /**
     * Starts one worker per job, all released at the same instant, and returns what each printed.
     *
     * @param  array<int, array<int, string>>  $jobs  [mode, argument, (admin id)]
     * @return array<int, array<string, mixed>>
     */
    private function race(array $jobs): array
    {
        $worker = base_path('tests/Support/dues-worker.php');
        $environment = array_merge(array_map('strval', getenv()), [
            'APP_ENV' => 'testing', 'DB_DATABASE' => (string) DB::connection()->getDatabaseName(),
            'MAIL_MAILER' => 'array', 'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync', 'SESSION_DRIVER' => 'array',
        ]);
        $startAt = sprintf('%.4F', microtime(true) + 6);

        $running = [];
        foreach ($jobs as $job) {
            $command = [PHP_BINARY, $worker, $job[0], $job[1], $startAt, ...array_slice($job, 2)];
            $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), $environment);
            $this->assertIsResource($process, 'could not start a dues worker');
            fclose($pipes[0]);
            $running[] = [$process, $pipes];
        }

        $results = [];
        foreach ($running as [$process, $pipes]) {
            $stdout = (string) stream_get_contents($pipes[1]);
            $stderr = (string) stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
            $lines = array_values(array_filter(explode("\n", trim($stdout)), fn (string $l) => str_starts_with(trim($l), '{')));
            $results[] = json_decode((string) end($lines), true) ?? ['ok' => false, 'stdout' => $stdout, 'stderr' => $stderr];
        }

        return $results;
    }
}
