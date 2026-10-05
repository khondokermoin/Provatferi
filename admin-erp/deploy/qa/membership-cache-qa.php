<?php
/**
 * Server-side half of the production QA for how fast the public membership page follows a membership change (task of
 * 2026-10-05, "membership cache invalidation") — the data and proofs a browser cannot see. CLI only. Run it where the
 * Laravel app lives: on production through a cron (as the other one-shot scripts are), locally with
 * `php deploy/qa/membership-cache-qa.php <mode>`.
 *
 *   snapshot    READ-ONLY. The QA leftovers (counts) and the evidence that nothing REAL was touched: every real season
 *               (id, name, status, dates — campaign names are public, no personal data), and a SHA-256 over every real
 *               (non-QA) row of seasons, season-type links, types, fee policies, applications, memberships and payments.
 *               Run it before and after the whole QA: the checksums must be identical.
 *   seed-type   creates ONE disposable membership type — code QZ, "QA CACHE TEST type", HIDDEN from the public site —
 *               whose first fee policy (registration 111, monthly 22) is dated YESTERDAY. The service refuses to backdate,
 *               and a type created today can never have its fee changed today (two policies cannot start on one day), so
 *               without this the Admin could not be asked to change TODAY's fee. The Admin UI does everything else.
 *               Idempotent. Tells the public site (a signed revalidation) like any save would.
 *   cleanup     removes everything this QA created and nothing else, strictly by marker: applications whose applicant
 *               name starts "QA CACHE TEST ", seasons whose name starts "QA CACHE TEST" (soft-deleted ones too — the
 *               Admin's delete is a soft delete), and the type with slug qa-cache-test-type together with its policies.
 *               A QA season or type that a NON-QA application refers to is kept, closed/hidden and reported (it fails).
 *               Then proves it, and tells the public site.
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
use App\Services\PublicSiteRevalidator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

const QA_SEASON_PREFIX = 'QA CACHE TEST';
const QA_APPLICANT_PREFIX = 'QA CACHE TEST ';
const QA_TYPE_CODE = 'QZ';
const QA_TYPE_SLUG = 'qa-cache-test-type';

function out(array $data, int $exit = 0): never
{
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE), "\n";
    exit($exit);
}

/** Row count and a SHA-256 over the given rows (all columns, in id order). */
function fingerprint(iterable $rows): array
{
    $rows = collect($rows)->values();

    return ['rows' => $rows->count(), 'sha256' => hash('sha256', json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE))];
}

/** A script has no request to terminate, so the pending public-site invalidation is sent here, once, explicitly. */
function tellThePublicSite(): array
{
    $revalidator = app(PublicSiteRevalidator::class);
    $configured = is_string(config('services.public_site.revalidate_secret')) && config('services.public_site.revalidate_secret') !== '';
    $revalidator->flush();

    return ['configured' => $configured];
}

$fees = app(MembershipFeePolicyService::class);

$qaTypeIds = fn () => MembershipType::query()->where('slug', QA_TYPE_SLUG)->pluck('id')->all();
$qaSeasonIds = fn () => MembershipSeason::withTrashed()->where('name', 'like', QA_SEASON_PREFIX.'%')->pluck('id')->all();
$qaApplicationIds = fn () => MembershipApplication::query()->where('applicant_name', 'like', QA_APPLICANT_PREFIX.'%')->pluck('id')->all();

// ------------------------------------------------------------------------------------------------ snapshot
if ($mode === 'snapshot') {
    $typeIds = $qaTypeIds();
    $seasonIds = $qaSeasonIds();
    $applicationIds = $qaApplicationIds();
    $notIn = fn (string $table, string $column, array $ids) => DB::table($table)->when($ids !== [], fn ($q) => $q->whereNotIn($column, $ids))->orderBy('id')->get();

    out([
        'mode' => 'snapshot',
        'utc_now' => gmdate('c'),
        'today_on_the_organisations_calendar' => $fees->today(),
        'real_seasons' => MembershipSeason::withTrashed()->whereNotIn('id', $seasonIds ?: [0])->orderBy('id')->get(['id', 'name', 'status', 'opens_at', 'closes_at', 'deleted_at'])->map(fn ($s) => [
            'id' => $s->id, 'name' => $s->name, 'status' => $s->status, 'opens_at' => $s->opens_at?->toIso8601String(), 'closes_at' => $s->closes_at?->toIso8601String(), 'deleted' => $s->deleted_at !== null,
        ])->all(),
        'fingerprints_of_real_rows' => [
            'membership_seasons' => fingerprint($notIn('membership_seasons', 'id', $seasonIds)),
            'membership_season_types' => fingerprint(DB::table('membership_season_types')->when($seasonIds !== [], fn ($q) => $q->whereNotIn('membership_season_id', $seasonIds))->orderBy('membership_season_id')->orderBy('membership_type_id')->get()),
            'membership_types' => fingerprint($notIn('membership_types', 'id', $typeIds)),
            'membership_fee_policies' => fingerprint(DB::table('membership_fee_policies')->when($typeIds !== [], fn ($q) => $q->whereNotIn('membership_type_id', $typeIds))->orderBy('id')->get()),
            'membership_applications' => fingerprint($notIn('membership_applications', 'id', $applicationIds)),
            'memberships' => fingerprint(DB::table('memberships')->orderBy('id')->get()),
            'payments' => fingerprint(DB::table('payments')->orderBy('id')->get()),
        ],
        'qa_leftovers' => [
            'applications' => count($applicationIds),
            'seasons' => count($seasonIds),
            'types' => count($typeIds),
            'policies' => $typeIds === [] ? 0 : MembershipFeePolicy::query()->whereIn('membership_type_id', $typeIds)->count(),
        ],
        'public_site_notification_configured' => is_string(config('services.public_site.revalidate_secret')) && config('services.public_site.revalidate_secret') !== '',
    ]);
}

// ------------------------------------------------------------------------------------------------ seed-type
if ($mode === 'seed-type') {
    $existing = MembershipType::query()->where('slug', QA_TYPE_SLUG)->first();
    if ($existing !== null) {
        out(['mode' => $mode, 'ok' => true, 'already_there' => true, 'type_id' => $existing->id, 'code' => $existing->code, 'public_visible' => (bool) $existing->is_public_visible]);
    }
    if (MembershipType::query()->where('code', QA_TYPE_CODE)->exists()) {
        out(['mode' => $mode, 'ok' => false, 'error' => 'the code '.QA_TYPE_CODE.' already belongs to another type — nothing was created'], 1);
    }

    $yesterday = Carbon::parse($fees->today())->subDay()->toDateString();
    $type = MembershipType::query()->create([
        'name' => 'QA CACHE TEST type', 'name_en' => 'QA CACHE TEST type', 'slug' => QA_TYPE_SLUG, 'code' => QA_TYPE_CODE,
        'status' => 'active', 'is_public_visible' => false, 'is_public_self_apply' => true, 'is_student' => false, 'sort_order' => 98,
    ]);
    $policy = MembershipFeePolicy::query()->create([
        'membership_type_id' => $type->id, 'registration_fee' => '111', 'monthly_contribution' => '22', 'effective_from' => $yesterday,
        'active' => true, 'note' => 'QA CACHE TEST policy — dated yesterday by the QA script so the Admin can be asked to change TODAY\'s fee',
    ]);
    $site = tellThePublicSite();

    out(['mode' => $mode, 'ok' => true, 'type_id' => $type->id, 'code' => $type->code, 'public_visible' => false, 'policy_id' => $policy->id, 'policy_from' => $yesterday, 'public_site' => $site]);
}

// ------------------------------------------------------------------------------------------------ cleanup
if ($mode === 'cleanup') {
    $removed = ['applications' => 0, 'photos' => 0, 'seasons' => [], 'policies' => 0, 'types' => []];
    $problems = [];

    // applications (and their private photo), strictly by the QA name marker
    foreach (MembershipApplication::query()->where('applicant_name', 'like', QA_APPLICANT_PREFIX.'%')->get() as $application) {
        $photo = $application->application_data['photo_path'] ?? null;
        if ($photo && Storage::disk('uploads_private')->exists($photo) && Storage::disk('uploads_private')->delete($photo)) {
            $removed['photos']++;
        }
        $application->delete();
        $removed['applications']++;
    }

    // seasons: the Admin's delete is a soft delete, so look at trashed ones too
    foreach (MembershipSeason::withTrashed()->where('name', 'like', QA_SEASON_PREFIX.'%')->get() as $season) {
        if (MembershipApplication::query()->where('membership_season_id', $season->id)->exists()) {
            // a REAL application reached the disposable season while it was open: keep it, close it, say so
            $season->forceFill(['status' => 'closed'])->save();
            $problems[] = "QA season #{$season->id} has a non-QA application attached: it was CLOSED but kept — review it by hand";
            continue;
        }
        $season->membershipTypes()->detach();
        $season->forceDelete();
        $removed['seasons'][] = $season->id;
    }

    // the disposable type and its policies
    foreach (MembershipType::query()->where('slug', QA_TYPE_SLUG)->get() as $type) {
        if (MembershipApplication::query()->where('membership_type_id', $type->id)->exists()) {
            $type->forceFill(['is_public_visible' => false])->save();
            $problems[] = "QA type #{$type->id} has a non-QA application attached: it was HIDDEN but kept — review it by hand";
            continue;
        }
        $removed['policies'] += MembershipFeePolicy::query()->where('membership_type_id', $type->id)->count();
        MembershipFeePolicy::query()->where('membership_type_id', $type->id)->get()->each->delete();
        DB::table('membership_season_types')->where('membership_type_id', $type->id)->delete();
        $type->delete();
        $removed['types'][] = $type->id;
    }

    $site = tellThePublicSite();

    $left = [
        'applications' => count($qaApplicationIds()),
        'seasons' => count($qaSeasonIds()),
        'types' => count($qaTypeIds()),
        'policies' => MembershipFeePolicy::query()->where('note', 'like', 'QA CACHE TEST%')->count(),
    ];
    $clean = array_sum($left) === 0 && $problems === [];
    out(['mode' => $mode, 'ok' => $clean, 'removed' => $removed, 'left' => $left, 'problems' => $problems, 'public_site' => $site], $clean ? 0 : 1);
}

fwrite(STDERR, "usage: membership-cache-qa.php snapshot | seed-type | cleanup\n");
exit(2);
