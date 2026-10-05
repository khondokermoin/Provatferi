<?php
/**
 * Server-side half of the acceptance of membership types + fee policies (Membership Registry, task 1, 2026-10-05) — the
 * data and proofs a browser cannot see. CLI only. Run it where the Laravel app lives: on production through a cron
 * (as the other one-shot scripts are), locally with `php deploy/qa/membership-fees-qa.php <mode>`.
 *
 *   snapshot             READ-ONLY. The membership types (code, English name, flags, order), every fee policy row, the
 *                        policy in force today for each type, and — the "nothing else changed" evidence — the row count
 *                        and a SHA-256 over every row of membership_applications, memberships and payments. Run it
 *                        before and after the browser run: the checksums must be identical.
 *   season-open [min]    creates ONE disposable registration season, open for [min] minutes (default 20, it closes
 *                        itself: closes_at), offering the three owner types (LM, GM, ST), so the public application
 *                        form has something to show. Labelled "QA FEES TEST".
 *   applications         READ-ONLY. The applications whose name starts with "QA FEES TEST ": each one's quoted fee
 *                        columns (policy id, amounts, source, the day), and the policy row it points at.
 *   cleanup              removes everything this QA created and nothing else, then proves it: the "QA FEES TEST"
 *                        applications (and the private photo of each), the disposable season, and any fee policy whose
 *                        note starts with "QA disposable fee policy" that has NOT started yet and that no application
 *                        refers to (a hard delete of the QA's own future row — it never applied to a single day — followed
 *                        by re-deriving the timeline so the previous policy is open-ended again). A QA policy that is
 *                        already in force is never touched: cleanup reports it and fails.
 *
 * Prints JSON. No names, e-mail addresses or phone numbers of real people are ever printed.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
ini_set('display_errors', 'stderr');
set_time_limit(0);

$mode = $argv[1] ?? 'snapshot';
$APP = getenv('QA_APP') ?: (is_dir('/home/u951246149/domains/provatferi.org/laravel-admin') ? '/home/u951246149/domains/provatferi.org/laravel-admin' : dirname(__DIR__, 2));
define('LARAVEL_START', microtime(true));
define('LARAVEL_PUBLIC_PATH_OVERRIDE', $APP.'/public');
require $APP.'/vendor/autoload.php';
$app = require $APP.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\MembershipApplication;
use App\Models\MembershipFeePolicy;
use App\Models\MembershipSeason;
use App\Models\MembershipType;
use App\Services\MembershipFeePolicyService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

const QA_NAME = 'QA FEES TEST ';
const QA_SEASON_SLUG = 'qa-fees-test-season';
const QA_POLICY_NOTE = 'QA disposable fee policy';

function out(array $data, int $exit = 0): never
{
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE), "\n";
    exit($exit);
}

/** Row count and a SHA-256 over every row (all columns, in id order) of a table. */
function fingerprint(string $table): array
{
    $rows = DB::table($table)->orderBy('id')->get();

    return ['rows' => $rows->count(), 'sha256' => hash('sha256', json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE))];
}

$fees = app(MembershipFeePolicyService::class);

// ------------------------------------------------------------------------------------------------ snapshot
if ($mode === 'snapshot') {
    $types = MembershipType::query()->orderBy('sort_order')->orderBy('id')->get();
    $inForce = $fees->effectiveForMany($types);
    $upcoming = $fees->upcomingForMany($types);
    $describe = fn (?MembershipFeePolicy $p) => $p === null ? null : ['id' => $p->id, 'registration_fee' => $p->registration_fee, 'monthly_contribution' => $p->monthly_contribution, 'from' => $p->fromDate(), 'until' => $p->untilDate()];

    out([
        'mode' => 'snapshot',
        'today_on_the_organisations_calendar' => $fees->today(),
        'utc_now' => gmdate('c'),
        'types' => $types->map(fn (MembershipType $t) => [
            'id' => $t->id, 'slug' => $t->slug, 'code' => $t->code, 'name_en' => $t->name_en, 'status' => $t->status,
            'is_public_visible' => (bool) $t->is_public_visible, 'is_public_self_apply' => (bool) $t->is_public_self_apply, 'sort_order' => $t->sort_order,
            'legacy_flat_fee_column' => (string) $t->getRawOriginal('fee'),
            'in_force_today' => $describe($inForce[$t->id] ?? null),
            'scheduled' => $describe($upcoming[$t->id] ?? null),
        ])->all(),
        'policies' => MembershipFeePolicy::query()->orderBy('membership_type_id')->orderBy('effective_from')->orderBy('id')->get()->map(fn (MembershipFeePolicy $p) => [
            'id' => $p->id, 'type_id' => $p->membership_type_id, 'registration_fee' => $p->registration_fee, 'monthly_contribution' => $p->monthly_contribution,
            'from' => $p->fromDate(), 'until' => $p->untilDate(), 'active' => $p->active, 'created_by' => $p->created_by, 'note' => $p->note,
            'cancelled' => $p->active ? null : ['by' => $p->cancelled_by, 'at' => $p->cancelled_at?->toIso8601String(), 'reason' => $p->cancellation_reason],
        ])->all(),
        'fingerprints' => ['membership_applications' => fingerprint('membership_applications'), 'memberships' => fingerprint('memberships'), 'payments' => fingerprint('payments')],
        'qa_leftovers' => [
            'applications' => MembershipApplication::query()->where('applicant_name', 'like', QA_NAME.'%')->count(),
            'seasons' => MembershipSeason::withTrashed()->where('slug', QA_SEASON_SLUG)->count(),
            'policies' => MembershipFeePolicy::query()->where('note', 'like', QA_POLICY_NOTE.'%')->count(),
        ],
    ]);
}

// ------------------------------------------------------------------------------------------------ season-open
if ($mode === 'season-open') {
    $minutes = max(5, min(60, (int) ($argv[2] ?? 20)));
    // Idempotent, so it is safe from a `* * * * *` probe cron: a second run reports the season the first one made.
    $existing = MembershipSeason::withTrashed()->where('slug', QA_SEASON_SLUG)->first();
    if ($existing !== null) {
        out(['mode' => $mode, 'ok' => true, 'already_open' => true, 'season_id' => $existing->id, 'closes_at' => $existing->closes_at?->toIso8601String(), 'types' => $existing->membershipTypes()->pluck('code')->all()]);
    }
    $ids = MembershipType::query()->whereIn('code', ['LM', 'GM', 'ST'])->pluck('id');
    if ($ids->count() !== 3) {
        out(['mode' => $mode, 'ok' => false, 'error' => 'expected the three owner types (codes LM, GM, ST), found '.$ids->count()], 1);
    }
    $season = MembershipSeason::query()->create([
        'name' => 'QA FEES TEST ঋতু', 'name_en' => 'QA FEES TEST season', 'slug' => QA_SEASON_SLUG, 'campaign_type' => 'regular', 'status' => 'open',
        'opens_at' => now()->subMinute(), 'closes_at' => now()->addMinutes($minutes), 'display_order' => 99,
        'cash_payment_instructions' => 'QA — ignore.', 'public_profile_opt_in' => false,
    ]);
    $season->membershipTypes()->sync($ids);
    out(['mode' => $mode, 'ok' => true, 'season_id' => $season->id, 'opens_at' => $season->opens_at->toIso8601String(), 'closes_at' => $season->closes_at->toIso8601String(), 'types' => $season->membershipTypes()->pluck('code')->all()]);
}

// ------------------------------------------------------------------------------------------------ applications
if ($mode === 'applications') {
    $rows = MembershipApplication::query()->where('applicant_name', 'like', QA_NAME.'%')->orderBy('id')->get();
    out([
        'mode' => $mode,
        'applications' => $rows->map(fn (MembershipApplication $a) => [
            'id' => $a->id, 'application_no' => $a->application_no, 'type_id' => $a->membership_type_id, 'season_id' => $a->membership_season_id,
            'fee_policy_id' => $a->fee_policy_id, 'registration_fee_amount' => $a->registration_fee_amount, 'monthly_contribution_amount' => $a->monthly_contribution_amount,
            'fee_snapshot_source' => $a->fee_snapshot_source, 'fee_effective_on' => $a->fee_effective_on?->toDateString(),
            'created_at' => $a->created_at?->toIso8601String(),
            'policy_row' => $a->feePolicy ? ['id' => $a->feePolicy->id, 'registration_fee' => $a->feePolicy->registration_fee, 'monthly_contribution' => $a->feePolicy->monthly_contribution, 'from' => $a->feePolicy->fromDate(), 'until' => $a->feePolicy->untilDate()] : null,
        ])->all(),
    ]);
}

// ------------------------------------------------------------------------------------------------ cleanup
if ($mode === 'cleanup') {
    $removed = ['applications' => 0, 'photos' => 0, 'season' => false, 'policies' => [], 'types_rechained' => []];
    $problems = [];

    // applications (and their private photo), strictly by the QA name marker
    foreach (MembershipApplication::query()->where('applicant_name', 'like', QA_NAME.'%')->get() as $application) {
        $photo = $application->application_data['photo_path'] ?? null;
        if ($photo && Storage::disk('uploads_private')->exists($photo) && Storage::disk('uploads_private')->delete($photo)) {
            $removed['photos']++;
        }
        $application->delete();
        $removed['applications']++;
    }

    // the disposable season
    $season = MembershipSeason::withTrashed()->where('slug', QA_SEASON_SLUG)->first();
    if ($season !== null) {
        if (MembershipApplication::query()->where('membership_season_id', $season->id)->exists()) {
            // a REAL application reached the disposable season while it was open: keep the season (it must not vanish from
            // under that row), close it, and say so
            $season->forceFill(['status' => 'closed', 'closes_at' => now()->subMinute()])->save();
            $problems[] = 'a non-QA application is attached to the QA season; the season was CLOSED but kept — review it by hand';
        } else {
            $season->membershipTypes()->detach();
            $season->forceDelete();
            $removed['season'] = true;
        }
    }

    // the QA's own future fee policies
    $today = $fees->today();
    foreach (MembershipFeePolicy::query()->where('note', 'like', QA_POLICY_NOTE.'%')->get() as $policy) {
        if ($policy->active && $policy->fromDate() <= $today) {
            $problems[] = "QA policy #{$policy->id} is already in force (from {$policy->fromDate()}) — NOT removed; it has applied to real days";
            continue;
        }
        if (MembershipApplication::query()->where('fee_policy_id', $policy->id)->exists()) {
            $problems[] = "QA policy #{$policy->id} is referenced by an application — NOT removed";
            continue;
        }
        $typeId = $policy->membership_type_id;
        $removed['policies'][] = ['id' => $policy->id, 'type_id' => $typeId, 'was_active' => $policy->active, 'from' => $policy->fromDate()];
        $policy->delete();
        $fees->chain($typeId);
        $removed['types_rechained'][] = $typeId;
    }

    $left = [
        'applications' => MembershipApplication::query()->where('applicant_name', 'like', QA_NAME.'%')->count(),
        'seasons' => MembershipSeason::withTrashed()->where('slug', QA_SEASON_SLUG)->count(),
        'policies' => MembershipFeePolicy::query()->where('note', 'like', QA_POLICY_NOTE.'%')->count(),
    ];
    $clean = array_sum($left) === 0 && $problems === [];
    out(['mode' => $mode, 'ok' => $clean, 'removed' => $removed, 'left' => $left, 'problems' => $problems, 'script_removed_itself' => false], $clean ? 0 : 1);
}

fwrite(STDERR, "usage: membership-fees-qa.php snapshot | season-open [minutes] | applications | cleanup\n");
exit(2);
