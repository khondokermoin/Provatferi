<?php

namespace Tests\Feature\Admin;

use App\Models\ApprovalHistory;
use App\Models\Member;
use App\Models\Membership;
use App\Models\MembershipApplication;
use App\Models\MembershipDue;
use App\Models\MembershipDueAllocation;
use App\Models\MembershipFeePolicy;
use App\Models\MembershipType;
use App\Models\Payment;
use App\Models\PaymentReceipt;
use App\Models\User;
use App\Services\MembershipDueLedger;
use App\Support\AdminTime;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\MakesMembershipTypes;
use Tests\TestCase;

/**
 * Membership task 5 (2026-10-08): receipts under REAL concurrency — two or more admins verifying at the same instant, on
 * both verification paths (the registration fee, the monthly contributions). Each case starts separate PHP processes
 * (tests/Support/dues-worker.php) with their own database connections, released together.
 *
 * Expected, every time: exactly ONE receipt per payment, exactly ONE number consumed per receipt, no number twice, no gap
 * and the same facts. The workers see only COMMITTED rows, so this class commits a small fixture and removes all of it —
 * receipts, payments, counters — in tearDown, leaving the test database as empty as it found it.
 */
class PaymentReceiptConcurrencyTest extends TestCase
{
    use MakesMembershipTypes;

    private MembershipType $type;

    private User $admin;

    /** @var array<int, int> */
    private array $applicationIds = [];

    /** @var array<int, int> */
    private array $membershipIds = [];

    /** @var array<int, int> */
    private array $memberIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (! RefreshDatabaseState::$migrated) {
            $this->artisan('migrate:fresh');
            RefreshDatabaseState::$migrated = true;
        }

        $this->type = $this->makeMembershipType(['name' => 'রসিদ সমকালীন পরীক্ষা', 'code' => 'RQ', 'slug' => 'receipt-concurrency-'.uniqid()], ['registration' => '500', 'monthly' => '200']);
        $this->admin = User::factory()->create(['email' => 'receipt-concurrency-'.uniqid().'@example.test', 'status' => 'active', 'name' => 'Race Admin']);
    }

    protected function tearDown(): void
    {
        try {
            $payments = Payment::query()->where(fn ($q) => $q->where('payable_type', MembershipApplication::class)->whereIn('payable_id', $this->applicationIds)
                ->orWhere(fn ($m) => $m->where('payable_type', Membership::class)->whereIn('payable_id', $this->membershipIds)))->pluck('id');
            DB::table('payment_receipts')->whereIn('payment_id', $payments)->delete(); // the model refuses deletion on purpose
            MembershipDueAllocation::query()->whereIn('membership_id', $this->membershipIds)->delete();
            $dueIds = MembershipDue::query()->whereIn('membership_id', $this->membershipIds)->pluck('id');
            Payment::query()->whereIn('id', $payments)->delete();
            ApprovalHistory::query()->where('subject_type', MembershipDue::class)->whereIn('subject_id', $dueIds)->delete();
            MembershipDue::query()->whereIn('id', $dueIds)->delete();
            ApprovalHistory::query()->where('subject_type', Membership::class)->whereIn('subject_id', $this->membershipIds)->delete();
            ApprovalHistory::query()->where('subject_type', MembershipApplication::class)->whereIn('subject_id', $this->applicationIds)->delete();
            Membership::query()->whereIn('id', $this->membershipIds)->delete();
            MembershipApplication::query()->whereIn('id', $this->applicationIds)->delete();
            Member::withTrashed()->whereIn('id', $this->memberIds)->forceDelete();
            MembershipFeePolicy::query()->where('membership_type_id', $this->type->id)->delete();
            $this->type->delete();
            $this->admin->forceDelete();
            DB::table('number_sequences')->where('sequence_key', 'like', 'receipt:%')->orWhere('sequence_key', 'like', 'application:%')->delete();
        } finally {
            parent::tearDown();
        }
    }

    /** An application with a recorded (unverified) cash payment for its registration fee. */
    private function registrationPayment(string $amount = '500'): Payment
    {
        $application = MembershipApplication::query()->create([
            'applicant_name' => 'আবেদনকারী '.uniqid(), 'applicant_email' => 'rcc-'.uniqid().'@example.test', 'applicant_phone' => '017'.random_int(10000000, 99999999),
            'membership_type_id' => $this->type->id, 'status' => 'under_review',
        ]);
        $this->applicationIds[] = $application->id;

        return $application->payments()->create([
            'membership_type_id' => $this->type->id, 'amount_expected' => '500', 'amount_received' => $amount, 'method' => 'cash',
            'received_at' => AdminTime::today(), 'received_by' => $this->admin->id, 'status' => 'paid',
        ]);
    }

    /** A membership with one monthly due and a recorded (unverified) payment for it. */
    private function monthlyPayment(): Payment
    {
        $member = Member::factory()->create(['name' => 'সদস্য '.uniqid(), 'status' => 'active']);
        $this->memberIds[] = $member->id;
        $membership = Membership::query()->create([
            'membership_type_id' => $this->type->id, 'member_id' => $member->id, 'member_code' => 'PLCC-RQ-TEST-'.uniqid(),
            'start_date' => AdminTime::today(), 'status' => 'active',
        ]);
        $this->membershipIds[] = $membership->id;
        ApprovalHistory::record($membership, 'created', $this->admin, 'test');
        $ledger = app(MembershipDueLedger::class);
        $ledger->generateFor($membership);

        return $ledger->recordPayment($membership, $this->admin, ['purpose' => 'due', 'due_id' => $membership->dues()->firstOrFail()->id, 'amount' => '200', 'method' => 'cash']);
    }

    private function year(): string
    {
        return (string) AdminTime::year();
    }

    /* ================================================================ */

    public function test_three_admins_verifying_one_registration_payment_at_once_issue_exactly_one_receipt(): void
    {
        $payment = $this->registrationPayment();

        $results = $this->race(array_fill(0, 3, ['verify-application', (string) $payment->id, (string) $this->admin->id]));

        $this->assertCount(3, array_filter($results, fn ($r) => $r['ok'] === true), json_encode($results));
        $this->assertSame([false, false, true], collect($results)->pluck('verified')->sort()->values()->all(), 'one verification won, two found it verified');
        $this->assertSame(1, PaymentReceipt::query()->where('payment_id', $payment->id)->count(), 'exactly one receipt');
        $this->assertSame("PLCC-RCT-{$this->year()}-000001", PaymentReceipt::query()->where('payment_id', $payment->id)->value('receipt_no'));
        $this->assertSame(1, (int) DB::table('number_sequences')->where('sequence_key', "receipt:{$this->year()}")->value('last_value'), 'exactly one number consumed');
        $this->assertSame(1, ApprovalHistory::query()->where('action', 'receipt_issued')->where('subject_id', $payment->payable_id)->count());
    }

    public function test_three_admins_verifying_one_monthly_payment_at_once_issue_exactly_one_receipt(): void
    {
        $payment = $this->monthlyPayment();

        $results = $this->race(array_fill(0, 3, ['verify', (string) $payment->id, (string) $this->admin->id]));

        $this->assertSame(['already', 'already', 'verified'], collect($results)->pluck('outcome')->sort()->values()->all(), json_encode($results));
        $this->assertSame(1, PaymentReceipt::query()->where('payment_id', $payment->id)->count());
        $receipt = PaymentReceipt::query()->where('payment_id', $payment->id)->sole();
        $this->assertSame("PLCC-RCT-{$this->year()}-000001", $receipt->receipt_no);
        $this->assertSame([['period' => AdminTime::today() === '' ? '' : substr(AdminTime::today(), 0, 7), 'amount' => '200.00']], $receipt->lines, 'the same facts: one allocation of ৳200, not three');
        $this->assertSame(['200.00', '0.00'], [$receipt->applied_amount, $receipt->credit_amount]);
        $this->assertSame(1, (int) DB::table('number_sequences')->where('sequence_key', "receipt:{$this->year()}")->value('last_value'));
        $this->assertSame(1, MembershipDueAllocation::query()->where('payment_id', $payment->id)->count());
    }

    public function test_different_payments_verified_at_once_take_distinct_consecutive_numbers_without_a_gap(): void
    {
        $payments = [$this->registrationPayment(), $this->registrationPayment(), $this->monthlyPayment(), $this->monthlyPayment()];
        $jobs = array_map(fn (Payment $p) => [$p->payable_type === MembershipApplication::class ? 'verify-application' : 'verify', (string) $p->id, (string) $this->admin->id], $payments);

        $results = $this->race($jobs);

        $this->assertCount(4, array_filter($results, fn ($r) => $r['ok'] === true), json_encode($results));
        $numbers = PaymentReceipt::query()->orderBy('receipt_no')->pluck('receipt_no')->all();
        $this->assertSame(array_map(fn (int $n) => sprintf('PLCC-RCT-%s-%06d', $this->year(), $n), [1, 2, 3, 4]), $numbers, 'four receipts, four consecutive numbers: none twice, none skipped');
        $this->assertSame(4, (int) DB::table('number_sequences')->where('sequence_key', "receipt:{$this->year()}")->value('last_value'));
        $this->assertSame(4, PaymentReceipt::query()->distinct('payment_id')->count('payment_id'), 'one receipt per payment');
    }

    public function test_a_retry_after_the_race_returns_the_same_receipt_and_takes_no_number(): void
    {
        $payment = $this->registrationPayment();
        $this->race([['verify-application', (string) $payment->id, (string) $this->admin->id], ['verify-application', (string) $payment->id, (string) $this->admin->id]]);
        $receipt = PaymentReceipt::query()->where('payment_id', $payment->id)->sole();

        $retry = $this->race([['verify-application', (string) $payment->id, (string) $this->admin->id]]);

        $this->assertSame([false], array_column($retry, 'verified'));
        $this->assertSame($receipt->receipt_no, PaymentReceipt::query()->where('payment_id', $payment->id)->sole()->receipt_no);
        $this->assertSame(1, (int) DB::table('number_sequences')->where('sequence_key', "receipt:{$this->year()}")->value('last_value'));
    }

    /* ---------------------------------------------------------------- helpers */

    /**
     * Starts one worker per job, all released at the same instant, and returns what each printed.
     *
     * @param  array<int, array<int, string>>  $jobs  [mode, argument, admin id]
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
            $this->assertIsResource($process, 'could not start a receipt worker');
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
