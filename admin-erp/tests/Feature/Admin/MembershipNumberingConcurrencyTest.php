<?php

namespace Tests\Feature\Admin;

use App\Models\ApprovalHistory;
use App\Models\Member;
use App\Models\MemberSeasonHistory;
use App\Models\Membership;
use App\Models\MembershipApplication;
use App\Models\MembershipFeePolicy;
use App\Models\MembershipSeason;
use App\Models\MembershipType;
use App\Models\User;
use App\Services\MembershipFeePolicyService;
use App\Services\NumberSequence;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\MakesMembershipTypes;
use Tests\TestCase;

/**
 * Membership task 3 (2026-10-07): numbers under REAL concurrency — tests F and K of the owner's list, plus the approval
 * retry race.
 *
 * Each case starts several separate PHP processes (tests/Support/numbering-worker.php), each with its own database
 * connection, all released at the same instant, and checks what they were given. A single test process cannot race
 * itself, so this is the only honest test of "two approvals / submissions at the same moment".
 *
 * The workers only see COMMITTED rows, so this class cannot use RefreshDatabase's wrapping transaction: it commits its
 * fixtures to the test database and removes everything it made in tearDown — the test database is left as empty as it
 * found it, as every other test expects.
 */
class MembershipNumberingConcurrencyTest extends TestCase
{
    use MakesMembershipTypes;

    private const EMAIL = 'numbering-concurrency-';

    private MembershipType $type;

    private MembershipSeason $season;

    private User $admin;

    /** @var array<int, string> */
    private array $sequenceKeysBefore = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (! RefreshDatabaseState::$migrated) {
            $this->artisan('migrate:fresh');
            RefreshDatabaseState::$migrated = true;
        }

        $this->sequenceKeysBefore = DB::table('number_sequences')->pluck('sequence_key')->all();
        $this->type = $this->makeMembershipType(['name' => 'সমকালীন পরীক্ষা', 'code' => 'CQ', 'slug' => 'numbering-concurrency-'.uniqid(), 'is_public_self_apply' => true, 'is_public_visible' => true]);
        $this->season = MembershipSeason::query()->create([
            'name' => 'সমকালীন পরীক্ষার সিজন', 'slug' => 'numbering-concurrency-'.uniqid(), 'campaign_type' => 'regular', 'status' => 'open', 'display_order' => 0,
        ]);
        $this->season->membershipTypes()->sync([$this->type->id]);
        $this->admin = User::factory()->create(['email' => self::EMAIL.uniqid().'@example.test', 'status' => 'active']);
    }

    protected function tearDown(): void
    {
        try {
            $applications = MembershipApplication::query()->where('membership_type_id', $this->type->id)->pluck('id');
            $memberships = Membership::query()->whereIn('membership_application_id', $applications)->pluck('id');
            $members = Member::withTrashed()->where('email', 'like', self::EMAIL.'%')->pluck('id');

            foreach ([[MembershipApplication::class, $applications], [Membership::class, $memberships], [Member::class, $members]] as [$subject, $ids]) {
                ApprovalHistory::query()->where('subject_type', $subject)->whereIn('subject_id', $ids)->delete();
            }
            MemberSeasonHistory::query()->whereIn('member_id', $members)->delete();
            Membership::query()->whereIn('id', $memberships)->delete();
            Member::withTrashed()->whereIn('id', $members)->forceDelete();
            MembershipApplication::query()->whereIn('id', $applications)->delete();
            $this->season->membershipTypes()->detach();
            $this->season->forceDelete();
            MembershipFeePolicy::query()->where('membership_type_id', $this->type->id)->delete();
            $this->type->delete();
            $this->admin->forceDelete();
            DB::table('number_sequences')->whereNotIn('sequence_key', $this->sequenceKeysBefore)->delete();
        } finally {
            parent::tearDown();
        }
    }

    public function test_f_simultaneous_approvals_of_one_type_receive_different_consecutive_numbers(): void
    {
        $applications = collect(range(1, 6))->map(fn (int $i) => $this->application($i));

        $results = $this->race($applications->map(fn (MembershipApplication $a) => ['approve', (string) $a->id, (string) $this->admin->id])->all());

        $this->assertCount(6, array_filter($results, fn (array $r) => $r['ok'] === true && ($r['already'] ?? null) === false), json_encode($results));
        $numbers = array_column($results, 'number');
        sort($numbers);
        $year = $this->year();
        $this->assertSame(array_map(fn (int $n) => sprintf('PLCC-CQ-%s-%04d', $year, $n), range(1, 6)), $numbers,
            'six simultaneous approvals: six different numbers, 0001-0006, none repeated, none skipped');
        $this->assertSame(6, Membership::query()->whereIn('member_code', $numbers)->count());
        $this->assertSame(6, app(NumberSequence::class)->current("member:CQ:{$year}"));
    }

    public function test_g_the_same_application_approved_by_several_admins_at_once_gets_one_number_and_takes_one(): void
    {
        $application = $this->application(1);

        $results = $this->race(array_fill(0, 4, ['approve', (string) $application->id, (string) $this->admin->id]));

        $this->assertCount(4, array_filter($results, fn (array $r) => $r['ok'] === true), json_encode($results));
        $this->assertCount(1, array_unique(array_column($results, 'number')), 'every request reports the one number issued');
        $this->assertCount(1, array_filter($results, fn (array $r) => ($r['already'] ?? null) === false), 'exactly one of them approved it');
        $this->assertSame(1, Membership::query()->where('membership_application_id', $application->id)->count());
        $this->assertSame(1, app(NumberSequence::class)->current("member:CQ:{$this->year()}"), 'the retries took nothing from the counter');
    }

    public function test_k_simultaneous_public_submissions_receive_different_application_numbers(): void
    {
        $before = app(NumberSequence::class)->current("application:{$this->year()}");
        $payload = fn (int $i) => base64_encode((string) json_encode([
            'applicant_name' => "সমকালীন আবেদনকারী {$i}",
            'applicant_email' => self::EMAIL."apply-{$i}-".uniqid().'@example.test',
            'applicant_phone' => '0181'.str_pad((string) $i, 7, '0', STR_PAD_LEFT),
            'membership_type_id' => $this->type->id,
            'membership_season_id' => $this->season->id,
        ]));

        $results = $this->race(array_map(fn (int $i) => ['apply', $payload($i)], range(1, 6)));

        $this->assertCount(6, array_filter($results, fn (array $r) => $r['ok'] === true), json_encode($results));
        $numbers = array_column($results, 'number');
        sort($numbers);
        $this->assertSame(array_map(fn (int $n) => sprintf('APP-%s-%04d', $this->year(), $n), range($before + 1, $before + 6)), $numbers,
            'six simultaneous submissions: six different, consecutive application numbers');
        $this->assertSame(6, MembershipApplication::query()->whereIn('application_no', $numbers)->count());
    }

    /* ---------------------------------------------------------------- helpers */

    private function application(int $i): MembershipApplication
    {
        return DB::transaction(fn () => MembershipApplication::query()->create([
            'applicant_name' => "সমকালীন সদস্য {$i}",
            'applicant_email' => self::EMAIL."{$i}-".uniqid().'@example.test',
            'applicant_phone' => '0191'.str_pad((string) random_int(0, 9999999), 7, '0', STR_PAD_LEFT),
            'membership_type_id' => $this->type->id,
            'membership_season_id' => $this->season->id,
            'status' => 'under_review',
        ]));
    }

    private function year(): string
    {
        return substr(app(MembershipFeePolicyService::class)->today(), 0, 4);
    }

    /**
     * Starts one worker per job, all released at the same instant, and returns what each printed.
     *
     * @param  array<int, array<int, string>>  $jobs  worker arguments (mode, argument, …)
     * @return array<int, array<string, mixed>>
     */
    private function race(array $jobs): array
    {
        $worker = base_path('tests/Support/numbering-worker.php');
        $environment = array_merge(array_map('strval', getenv()), [
            'APP_ENV' => 'testing',
            'DB_DATABASE' => (string) DB::connection()->getDatabaseName(),
            'MAIL_MAILER' => 'array', 'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync', 'SESSION_DRIVER' => 'array',
        ]);
        // Far enough ahead for every process to boot first; then they all act within the same millisecond or so.
        $startAt = sprintf('%.4F', microtime(true) + 6);

        $running = [];
        foreach ($jobs as $job) {
            [$mode, $argument] = $job;
            $command = array_merge([PHP_BINARY, $worker, $mode, $argument, $startAt], array_slice($job, 2));
            $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), $environment);
            $this->assertIsResource($process, 'could not start a numbering worker');
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
            // PHP prints start-up warnings on stdout on some machines: the answer is the last JSON line.
            $lines = array_values(array_filter(explode("\n", trim($stdout)), fn (string $l) => str_starts_with(trim($l), '{')));
            $results[] = json_decode((string) end($lines), true) ?? ['ok' => false, 'stdout' => $stdout, 'stderr' => $stderr];
        }

        return $results;
    }
}
