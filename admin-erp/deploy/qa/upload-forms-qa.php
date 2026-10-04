<?php
/**
 * QA harness for the four upload forms that moved off Server Actions (committee registration, committee
 * correction, membership application, member profile) — the data half of institutional/scripts/upload-forms-qa.mjs.
 * CLI only. Run it where the Laravel app lives (locally: `php deploy/qa/upload-forms-qa.php setup`; on production
 * through a one-shot cron, as the other one-shot scripts are).
 *
 *   setup [--local-admin]   creates the disposable fixtures every scenario needs and prints them as JSON:
 *                           a committee with two positions and a registration link, seven correction submissions
 *                           (A-E for upload-forms-qa.mjs, F for native-post-probe.mjs, G for upload-forms-nojs-qa.mjs; each with its
 *                           own single-use token and a photo already on file), a membership type and an
 *                           open season (it closes itself after 45 minutes), a member with a known password and a
 *                           membership row so the admin screens list them. --local-admin also creates a staff
 *                           account with a random password — LOCAL ONLY; production QA uses the owner's own login.
 *   inspect                 reads back everything the scenarios created: each row, and for every stored photo whether
 *                           it exists, its size and its sha256 — "the file actually persists, unchanged".
 *   cleanup                 deletes every record and file this harness or the scenarios created (only things hanging
 *                           off the ids recorded in the state file), then proves nothing is left.
 *   sweep                   lists anything still labelled "QA UPLOAD TEST " (the state file got lost); read-only.
 *
 * Everything is labelled "QA UPLOAD TEST <suffix>". The state file is storage/app/qa-upload-forms-state.json.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
ini_set('display_errors', 'stderr');
set_time_limit(0);

$mode = $argv[1] ?? 'inspect';
$APP = getenv('QA_APP') ?: (is_dir('/home/u951246149/domains/provatferi.org/laravel-admin') ? '/home/u951246149/domains/provatferi.org/laravel-admin' : dirname(__DIR__, 2));
define('LARAVEL_START', microtime(true));
define('LARAVEL_PUBLIC_PATH_OVERRIDE', $APP.'/public');
require $APP.'/vendor/autoload.php';
$app = require $APP.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\ApprovalHistory;
use App\Models\Committee;
use App\Models\CommitteeMember;
use App\Models\CommitteePosition;
use App\Models\CommitteeRegistrationLink;
use App\Models\CommitteeSubmission;
use App\Models\Member;
use App\Models\Membership;
use App\Models\MembershipApplication;
use App\Models\MembershipSeason;
use App\Models\MembershipType;
use App\Models\OrganizationalUnit;
use App\Models\PublicMemberProfileVersion;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

const TAG = 'QA UPLOAD TEST ';
$STATE = $APP.'/storage/app/qa-upload-forms-state.json';

/** A 1x1 PNG, the "photo already on file" the correction scenarios start from. */
const TINY_PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

function out(array $data): void { echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), "\n"; }
function loadState(string $file): array {
    if (!is_file($file)) { fwrite(STDERR, "no state file ($file) — run setup first, or sweep\n"); exit(2); }
    return json_decode(file_get_contents($file), true);
}
function info(string $disk, ?string $path): ?array {
    if (!$path) return null;
    $d = Storage::disk($disk);
    if (!$d->exists($path)) return ['path' => $path, 'exists' => false];
    $bytes = $d->get($path);
    return ['path' => $path, 'exists' => true, 'bytes' => strlen($bytes), 'sha256' => hash('sha256', $bytes)];
}
function history(string $class, int $id): array {
    return ApprovalHistory::query()->where('subject_type', $class)->where('subject_id', $id)->orderBy('id')->pluck('action')->all();
}

// ---------------------------------------------------------------------------------------------------------------
if ($mode === 'setup') {
    $suffix = 'qa'.substr(bin2hex(random_bytes(4)), 0, 6);
    $tag = TAG.$suffix;
    $state = ['suffix' => $suffix, 'createdAt' => now()->toIso8601String(), 'ids' => [], 'files' => []];

    // A failed earlier setup rolls its rows back but cannot un-write the photo files it had already put on disk: sweep those
    // (only this harness's own names, only when no row refers to them) before starting.
    $swept = 0;
    foreach (Storage::disk('uploads_private')->files('committee-submissions') as $file) {
        if (preg_match('~^committee-submissions/qa-qa[0-9a-f]{6}-[a-g]\.png$~', $file) && !CommitteeSubmission::query()->where('photo_path', $file)->exists()) {
            Storage::disk('uploads_private')->delete($file);
            $swept++;
        }
    }

    try {
    DB::transaction(function () use (&$state, $suffix, $tag, $argv) {
        // A committee needs an organisation unit; reuse one that exists, create (and later remove) one only when there is none.
        $unit = OrganizationalUnit::query()->orderBy('id')->first();
        if (!$unit) {
            $unit = OrganizationalUnit::query()->create(['name' => $tag.' unit', 'slug' => 'qa-upload-unit-'.$suffix, 'unit_type' => 'head_office', 'status' => 'active']);
            $state['ids']['unit_created'] = $unit->id;
        }

        $committee = Committee::query()->create([
            'organization_unit_id' => $unit->id, 'name' => $tag.' (ignore)', 'name_en' => $tag.' (ignore)', 'slug' => 'qa-upload-test-'.$suffix,
            'status' => 'upcoming', 'description' => 'Disposable QA data — ignore.',
        ]);
        $state['ids']['committee'] = $committee->id;
        $state['committee'] = ['id' => $committee->id, 'name' => $committee->name, 'slug' => $committee->slug];

        $positions = [];
        foreach ([1 => 'QA পদ এক', 2 => 'QA পদ দুই'] as $order => $name) {
            $p = CommitteePosition::query()->create(['committee_id' => $committee->id, 'name' => $name, 'name_en' => 'QA position '.$order, 'slug' => 'qa-pos-'.$order.'-'.$suffix, 'display_order' => $order, 'allow_duplicates' => true, 'status' => 'active']);
            $positions[] = ['id' => $p->id, 'name' => $p->name];
        }
        $state['ids']['positions'] = array_column($positions, 'id');
        $state['positions'] = $positions;

        [$link, $rawLink] = CommitteeRegistrationLink::issue($committee, now()->addHours(3), null);
        $state['ids']['link'] = $link->id;
        $state['registrationToken'] = $rawLink;

        // Four correction submissions, one per scenario that consumes a single-use token; each already has a photo on file.
        $state['corrections'] = [];
        foreach (['A', 'B', 'C', 'D', 'E', 'F', 'G'] as $label) {
            $path = 'committee-submissions/qa-'.$suffix.'-'.strtolower($label).'.png';
            Storage::disk('uploads_private')->put($path, base64_decode(TINY_PNG));
            $state['files'][] = ['disk' => 'uploads_private', 'path' => $path];
            $s = CommitteeSubmission::query()->create([
                'committee_id' => $committee->id, 'committee_registration_link_id' => $link->id, 'committee_position_id' => $positions[0]['id'],
                'full_name' => "QA Correction $label $suffix", 'email' => "khondokermoin2k23+$suffix-c".strtolower($label).'@gmail.com', 'phone' => '01700000000',
                'photo_path' => $path, 'provatferi_comment' => 'Disposable QA data.', 'publishing_consent' => true, 'accuracy_declaration' => true,
                'status' => 'correction_requested', 'admin_note' => 'QA: please resubmit — this is a test.', 'submitted_at' => now(),
            ]);
            $rawCorrection = $s->issueCorrectionToken(now()->addHours(3));
            $state['corrections'][$label] = ['id' => $s->id, 'token' => $rawCorrection, 'photoPath' => $path, 'photoSha256' => hash('sha256', base64_decode(TINY_PNG))];
        }

        $type = MembershipType::query()->create(['name' => $tag.' type', 'name_en' => $tag.' type', 'slug' => 'qa-upload-type-'.$suffix, 'fee' => 0, 'is_student' => false, 'is_public_self_apply' => true, 'status' => 'active', 'sort_order' => 999]);
        $season = MembershipSeason::query()->create([
            'name' => $tag.' (ignore)', 'name_en' => $tag.' (ignore)', 'slug' => 'qa-upload-season-'.$suffix, 'campaign_type' => 'regular',
            'opens_at' => now()->subMinute(), 'closes_at' => now()->addMinutes(45), 'membership_period_months' => 12, 'status' => 'open',
            'cash_payment_instructions' => 'QA — ignore.', 'public_profile_opt_in' => false, 'display_order' => 999,
        ]);
        $season->membershipTypes()->attach($type->id);
        $state['ids']['type'] = $type->id;
        $state['ids']['season'] = $season->id;
        $state['membership'] = ['seasonId' => $season->id, 'typeId' => $type->id, 'typeName' => $type->name, 'closesAt' => $season->closes_at->toIso8601String()];

        $password = bin2hex(random_bytes(9));
        $member = Member::query()->create([
            'member_code' => 'QA-'.$suffix, 'name' => "QA Member $suffix", 'email' => "khondokermoin2k23+$suffix-m@gmail.com", 'phone' => '0199'.str_pad((string) random_int(0, 9999999), 7, '0', STR_PAD_LEFT),
            'password' => $password, 'status' => 'active', 'public_profile_enabled' => false, 'public_profile_approved' => false,
        ]);
        $registry = Membership::query()->create(['member_id' => $member->id, 'membership_type_id' => $type->id, 'member_code' => 'QA-'.$suffix, 'start_date' => now()->toDateString(), 'status' => 'active']);
        $state['ids']['member'] = $member->id;
        $state['ids']['registry'] = $registry->id;
        $state['member'] = ['id' => $member->id, 'email' => $member->email, 'password' => $password, 'registryId' => $registry->id, 'code' => $member->member_code];

        if (in_array('--local-admin', $argv, true)) {
            $adminPassword = bin2hex(random_bytes(9));
            $admin = User::query()->create(['name' => "QA Upload Admin $suffix", 'email' => "qa-upload-$suffix@provatferi.invalid", 'password' => $adminPassword, 'status' => 'active']);
            $role = Role::query()->where('slug', 'super-admin')->first() ?? Role::query()->orderBy('id')->first();
            $admin->roles()->attach($role->id);
            $state['ids']['admin'] = $admin->id;
            $state['admin'] = ['email' => $admin->email, 'password' => $adminPassword];
        }
    });

    } catch (Throwable $e) {
        // The rows rolled back; take this run's files with them, then report the real error.
        foreach ($state['files'] as $f) Storage::disk($f['disk'])->delete($f['path']);
        throw $e;
    }

    $state['sweptOrphans'] = $swept;
    file_put_contents($STATE, json_encode($state, JSON_PRETTY_PRINT));
    out($state);
    exit(0);
}

// ---------------------------------------------------------------------------------------------------------------
if ($mode === 'inspect') {
    $s = loadState($STATE);
    $ids = $s['ids'];
    $submissions = CommitteeSubmission::query()->where('committee_id', $ids['committee'])->orderBy('id')->get();
    $corrections = array_flip(array_column($s['corrections'], 'id'));
    $original = [];
    foreach ($s['corrections'] as $c) $original[$c['id']] = $c['photoPath'];
    $report = [
        'suffix' => $s['suffix'],
        'at' => now()->toIso8601String(),
        'link' => ['lastUsedAt' => optional(CommitteeRegistrationLink::query()->find($ids['link']))->last_used_at?->toIso8601String()],
        'registrations' => [],
        'corrections' => [],
        'applications' => [],
        'profileVersions' => [],
        'member' => null,
    ];
    foreach ($submissions as $row) {
        $entry = ['id' => $row->id, 'fullName' => $row->full_name, 'email' => $row->email, 'phone' => $row->phone, 'positionId' => $row->committee_position_id, 'status' => $row->status,
            'bio' => $row->bio, 'comment' => $row->provatferi_comment, 'adminNote' => $row->admin_note,
            'correctionUsedAt' => $row->correction_used_at?->toIso8601String(), 'photo' => info('uploads_private', $row->photo_path), 'photoApproved' => info('public', $row->photo_approved_path),
            'history' => history(CommitteeSubmission::class, $row->id)];
        if (isset($corrections[$row->id])) { $entry['originalPhoto'] = info('uploads_private', $original[$row->id]); $report['corrections'][] = $entry; } else $report['registrations'][] = $entry;
    }
    foreach (MembershipApplication::query()->where('membership_season_id', $ids['season'])->orderBy('id')->get() as $a) {
        $photo = is_array($a->application_data) ? ($a->application_data['photo_path'] ?? null) : null;
        $report['applications'][] = ['id' => $a->id, 'applicationNo' => $a->application_no, 'name' => $a->applicant_name, 'email' => $a->applicant_email, 'status' => $a->status, 'photo' => info('uploads_private', $photo)];
    }
    foreach (PublicMemberProfileVersion::query()->where('member_id', $ids['member'])->orderBy('id')->get() as $v) {
        $report['profileVersions'][] = ['id' => $v->id, 'status' => $v->status, 'profession' => $v->profession, 'bio' => $v->bio, 'facebook' => $v->facebook_url, 'submittedAt' => $v->submitted_at?->toIso8601String(), 'photo' => info('uploads_private', $v->photo_path), 'photoApproved' => info('public', $v->photo_approved_path)];
    }
    $m = Member::query()->find($ids['member']);
    $report['member'] = $m ? ['publicProfileEnabled' => (bool) $m->public_profile_enabled, 'publicProfileApproved' => (bool) $m->public_profile_approved, 'publicSlug' => $m->public_slug] : null;
    out($report);
    exit(0);
}

// ---------------------------------------------------------------------------------------------------------------
if ($mode === 'cleanup') {
    $s = loadState($STATE);
    $ids = $s['ids'];
    $removedFiles = 0;
    $pending = []; // files are removed only AFTER the transaction commits: a rollback must not leave rows without their files
    $deleteFile = function (string $disk, ?string $path) use (&$pending) {
        if ($path) $pending[] = [$disk, $path];
    };

    DB::transaction(function () use ($ids, $deleteFile) {
        // Committee side: every submission under the QA committee (the scenarios' own and the seeded ones), what approving one could have made, their trail and photos.
        $submissionIds = CommitteeSubmission::query()->where('committee_id', $ids['committee'])->pluck('id')->all();
        foreach (CommitteeSubmission::query()->whereIn('id', $submissionIds)->get() as $row) {
            $deleteFile('uploads_private', $row->photo_path);
            $deleteFile('public', $row->photo_approved_path);
        }
        if ($submissionIds) ApprovalHistory::query()->where('subject_type', CommitteeSubmission::class)->whereIn('subject_id', $submissionIds)->delete();
        CommitteeMember::query()->where('committee_id', $ids['committee'])->delete();
        CommitteeSubmission::query()->whereIn('id', $submissionIds)->delete();
        CommitteeRegistrationLink::query()->where('committee_id', $ids['committee'])->delete();
        CommitteePosition::query()->where('committee_id', $ids['committee'])->delete();
        Committee::query()->where('id', $ids['committee'])->delete();

        // Member side (the registry row references the membership type, so it goes before the type).
        foreach (PublicMemberProfileVersion::query()->where('member_id', $ids['member'])->get() as $v) {
            $deleteFile('uploads_private', $v->photo_path);
            $deleteFile('public', $v->photo_approved_path);
            $v->delete();
        }
        Membership::query()->where('id', $ids['registry'])->delete();
        DB::table('personal_access_tokens')->where('tokenable_type', Member::class)->where('tokenable_id', $ids['member'])->delete();
        Member::withTrashed()->where('id', $ids['member'])->forceDelete();

        // Membership side.
        foreach (MembershipApplication::query()->where('membership_season_id', $ids['season'])->get() as $a) {
            $deleteFile('uploads_private', is_array($a->application_data) ? ($a->application_data['photo_path'] ?? null) : null);
            ApprovalHistory::query()->where('subject_type', MembershipApplication::class)->where('subject_id', $a->id)->delete();
            $a->delete();
        }
        DB::table('membership_season_types')->where('membership_season_id', $ids['season'])->delete();
        MembershipSeason::withTrashed()->where('id', $ids['season'])->forceDelete();
        MembershipType::query()->where('id', $ids['type'])->delete();

        if (!empty($ids['admin'])) {
            DB::table('user_roles')->where('user_id', $ids['admin'])->delete();
            User::query()->where('id', $ids['admin'])->delete();
        }
        if (!empty($ids['unit_created'])) OrganizationalUnit::withTrashed()->where('id', $ids['unit_created'])->forceDelete();
    });
    foreach ($s['files'] as $f) $deleteFile($f['disk'], $f['path']);
    foreach ($pending as [$disk, $path]) {
        if (Storage::disk($disk)->exists($path)) { Storage::disk($disk)->delete($path); $removedFiles++; }
    }

    $left = [
        'committees' => Committee::query()->where('id', $ids['committee'])->count(),
        'submissions' => CommitteeSubmission::query()->where('committee_id', $ids['committee'])->count(),
        'links' => CommitteeRegistrationLink::query()->where('committee_id', $ids['committee'])->count(),
        'positions' => CommitteePosition::query()->where('committee_id', $ids['committee'])->count(),
        'applications' => MembershipApplication::query()->where('membership_season_id', $ids['season'])->count(),
        'seasons' => MembershipSeason::withTrashed()->where('id', $ids['season'])->count(),
        'types' => MembershipType::query()->where('id', $ids['type'])->count(),
        'members' => Member::withTrashed()->where('id', $ids['member'])->count(),
        'profileVersions' => PublicMemberProfileVersion::query()->where('member_id', $ids['member'])->count(),
        'registryRows' => Membership::query()->where('id', $ids['registry'])->count(),
        'tagged' => Committee::query()->where('name', 'like', TAG.'%')->count() + MembershipSeason::withTrashed()->where('name', 'like', TAG.'%')->count() + MembershipType::query()->where('name', 'like', TAG.'%')->count() + Member::withTrashed()->where('name', 'like', 'QA Member '.$s['suffix'])->count(),
        'admins' => empty($ids['admin']) ? 0 : User::query()->where('id', $ids['admin'])->count(),
    ];
    $clean = array_sum($left) === 0;
    if ($clean) @unlink($STATE);
    out(['suffix' => $s['suffix'], 'removedFiles' => $removedFiles, 'leftover' => $left, 'clean' => $clean]);
    exit($clean ? 0 : 1);
}

// ---------------------------------------------------------------------------------------------------------------
if ($mode === 'sweep') {
    out([
        'committees' => Committee::query()->where('name', 'like', TAG.'%')->get(['id', 'name', 'status'])->toArray(),
        'seasons' => MembershipSeason::withTrashed()->where('name', 'like', TAG.'%')->get(['id', 'name', 'status'])->toArray(),
        'types' => MembershipType::query()->where('name', 'like', TAG.'%')->get(['id', 'name'])->toArray(),
        'members' => Member::withTrashed()->where('name', 'like', 'QA Member qa%')->get(['id', 'name', 'email'])->toArray(),
        'stateFile' => is_file($STATE),
    ]);
    exit(0);
}

fwrite(STDERR, "usage: php upload-forms-qa.php setup [--local-admin] | inspect | cleanup | sweep\n");
exit(2);
