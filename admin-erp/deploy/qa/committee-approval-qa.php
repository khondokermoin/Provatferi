<?php
/**
 * PRODUCTION ACCEPTANCE KIT for committee-submission review (approval, the applicant's photo). CLI only: 404 otherwise.
 * Upload it under a one-off name (`php deploy/qa/cron-guard.php new-session`) and run each mode from a cron. It prints no
 * secret and NO applicant data: an application is described by ids, status, timestamps, flags and file facts, never by name,
 * e-mail, phone, biography or notes. The browser half is institutional/scripts/committee-approval-qa.mjs.
 *
 * It touches ONLY what belongs to a QA run, recognised by markers the setup step and the browser kit put into every name:
 *   - a committee whose name starts with "QA-CMT-" (its positions, registration links, members, submissions and the audit rows
 *     of those submissions, and the unit when setup had to create one), the submissions' private photos and the public copies
 *     an approval made;
 *   - a staff user whose e-mail matches qa-cmt-*@example.test (with sessions, tokens and role links) and a role whose name
 *     starts with "QA-CMT-".
 * A real committee, application, member or file is never matched. Nothing here ever approves, rejects or edits an application.
 *
 *   inspect <submission id>
 *       Read-only. What the review page and the approval depend on, as non-private facts: the row (status, reviewer,
 *       timestamps, which fields are filled), its photo (private original and public copy: where, whether the file exists,
 *       size, type, dimensions, the first 8 hex characters of its sha256), the audit trail (actions and times, no notes), the
 *       committee seats made from it, its committee and position, the disks and the log configuration.
 *   setup
 *       Creates one disposable committee ("upcoming", so its public registration link works; never "active", which would demote
 *       the real active committee), two positions and a 3-hour registration link, and prints their ids and the raw link token.
 *   status <committee id> <draft|upcoming>
 *       Moves a QA committee between "draft" (not public) and "upcoming" (public registration works). Refuses any committee that
 *       is not a QA one, and any other status.
 *   seed <committee id>
 *       Adds five pending/terminal QA applications under a QA committee for the edge cases a form cannot make: no photo at all, a
 *       photo path whose file is gone, a malformed (path-traversal) photo path, a rejected one and an unpublished one.
 *   inventory
 *       Read-only. Counts of committees / submissions / seats (real and QA), QA users and roles, the two photo folders.
 *   snapshot <tag>
 *       Writes the state of everything that is NOT a QA row to storage/app/committee-approval-qa/<tag>.json (once per tag; a
 *       later run replays): a hash of every real committee, position, link, submission, seat and audit row, the users and
 *       roles, the two photo folders' file lists and every log file's size.
 *   diff <tag>
 *       Read-only. Compares the live state with the snapshot. `identical_to_snapshot` is true only when nothing real changed
 *       AND no QA row, user, role or stray file is left.
 *   logs <tag>
 *       Read-only. Lines written to the Laravel log since the snapshot, error level and above only, reduced to level + the
 *       first 160 characters of the message, addresses and long tokens masked.
 *   purge <tag> go
 *       Deletes the QA data in one transaction, then the photo files. Without the word "go" it only lists what it would delete.
 *       On the live host it runs only from a kit named _qa_<hex>.php made for this session.
 *   forget <tag>
 *       Deletes the snapshot file (and the folder once empty).
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
ini_set('display_errors', 'stderr');

const QA_DOMAIN_ROOT = '/home/u951246149/domains/provatferi.org';
const QA_COMMITTEE_MARK = 'QA-CMT-';
const QA_NAME_MARK = 'QA-CMT-';
const QA_EMAIL_MARK = 'qacmt';
const STRAY_MIN_AGE_SECONDS = 1800;
const PRIVATE_FOLDER = 'committee-submissions';
const PUBLIC_FOLDER = 'committee';

$APP = getenv('QA_APP') ?: (is_dir(QA_DOMAIN_ROOT.'/laravel-admin') ? QA_DOMAIN_ROOT.'/laravel-admin' : dirname(__DIR__, 2));
$STATE = $APP.'/storage/app/committee-approval-qa';
$mode = $argv[1] ?? '';
$arg = (string) ($argv[2] ?? '');

function out(array $data, int $exit = 0): never
{
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE), "\n";
    exit($exit);
}

const MODES = ['inspect', 'setup', 'status', 'seed', 'inventory', 'snapshot', 'diff', 'logs', 'purge', 'forget'];
if (! in_array($mode, MODES, true)) {
    fwrite(STDERR, "modes: inspect <id> | setup | inventory | snapshot <tag> | diff <tag> | logs <tag> | purge <tag> go | forget <tag>\n");
    exit(2);
}
if (in_array($mode, ['inspect', 'seed'], true) && preg_match('/^[0-9]{1,9}$/', $arg) !== 1) {
    out(['error' => "usage: {$mode} <id>"], 2);
}
if ($mode === 'status' && (preg_match('/^[0-9]{1,9}$/', $arg) !== 1 || ! in_array($argv[3] ?? '', ['draft', 'upcoming'], true))) {
    out(['error' => 'usage: status <committee id> <draft|upcoming>'], 2);
}
if (in_array($mode, ['snapshot', 'diff', 'logs', 'purge', 'forget'], true) && preg_match('/^[a-z0-9]{6,24}$/', $arg) !== 1) {
    out(['error' => "usage: {$mode} <tag: 6-24 of a-z0-9>"], 2);
}

// PRODUCTION SAFETY: `purge` deletes data, so on the live host it runs only from a kit uploaded under a one-off name made for
// THIS session (_qa_<hex>.php), never from a fixed name an old cron could still point at (deploy/README.md, "Cron jobs").
if ($mode === 'purge' && (is_dir(QA_DOMAIN_ROOT) || getenv('QA_FORCE_PRODUCTION_GUARDS') === '1')
    && ! preg_match('/^_qa_(?:[a-z]+_)?[0-9a-f]{8,}\.php$/', basename(__FILE__))) {
    out(['mode' => 'purge', 'ok' => false, 'error' => 'refusing on production: purge runs only from a kit named _qa_<hex>.php made for this session (this file is '.basename(__FILE__).') — nothing was removed'], 2);
}

define('LARAVEL_START', microtime(true));
define('LARAVEL_PUBLIC_PATH_OVERRIDE', $APP.'/public');
require $APP.'/vendor/autoload.php';
$laravel = require $APP.'/bootstrap/app.php';
$laravel->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\ApprovalHistory;
use App\Models\Committee;
use App\Models\CommitteeMember;
use App\Models\CommitteePosition;
use App\Models\CommitteeRegistrationLink;
use App\Models\CommitteeSubmission;
use App\Models\OrganizationalUnit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** @return list<int> */
function qaCommitteeIds(): array
{
    return DB::table('committees')->where('name', 'like', QA_COMMITTEE_MARK.'%')->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
}

/** QA submissions: under a QA committee, OR carrying both markers (a QA application sent to a real committee would still be ours). @return list<int> */
function qaSubmissionIds(): array
{
    $committees = qaCommitteeIds();

    return DB::table('committee_submissions')
        ->where(function ($q) use ($committees) {
            if ($committees !== []) {
                $q->whereIn('committee_id', $committees)->orWhere(fn ($w) => $w->where('full_name', 'like', QA_NAME_MARK.'%')->where('email', 'like', '%'.QA_EMAIL_MARK.'%'));
            } else {
                $q->where('full_name', 'like', QA_NAME_MARK.'%')->where('email', 'like', '%'.QA_EMAIL_MARK.'%');
            }
        })
        ->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
}

/** @return list<int> */
function qaUserIds(): array
{
    return DB::table('users')->where('email', 'like', 'qa-cmt-%@example.test')->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
}

/** @return list<int> */
function qaRoleIds(): array
{
    return DB::table('roles')->where('name', 'like', QA_COMMITTEE_MARK.'%')->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
}

/** @return list<int> */
function qaUnitIds(): array
{
    return DB::table('organizational_units')->where('slug', 'like', 'qa-cmt-unit-%')->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
}

/** @return array<string, int> path => size for a folder of a disk */
function folderFiles(string $disk, string $folder): array
{
    $storage = Storage::disk($disk);
    $files = [];
    foreach ($storage->allFiles($folder) as $path) {
        $files[$path] = (int) $storage->size($path);
    }
    ksort($files);

    return $files;
}

/** @return array<string, string> id => hash of the row, for every row of $table not matching $skip (a list of ids) */
function rowHashes(string $table, array $skip = [], string $key = 'id'): array
{
    if (! Schema::hasTable($table)) {
        return [];
    }
    $query = DB::table($table)->orderBy($key);
    if ($skip !== []) {
        $query->whereNotIn($key, $skip);
    }
    $hashes = [];
    foreach ($query->get() as $row) {
        $hashes[(string) $row->{$key}] = sha1((string) json_encode($row));
    }

    return $hashes;
}

/** @return array<string, int> */
function logSizes(): array
{
    $sizes = [];
    foreach (glob(storage_path('logs/*.log')) ?: [] as $file) {
        $sizes[basename($file)] = (int) filesize($file);
    }
    ksort($sizes);

    return $sizes;
}

/** Masks anything that could identify a person or carry a secret in a log line. */
function mask(string $line): string
{
    $line = preg_replace('/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+/', '<address>', $line) ?? $line;
    $line = preg_replace('~https?://\S+~', '<url>', $line) ?? $line;
    $line = preg_replace('/[A-Za-z0-9_\-+\/=]{32,}/', '<long-token>', $line) ?? $line;

    return mb_substr($line, 0, 160);
}

/** Facts about a stored file, none of them its content. @return array<string, mixed> */
function fileFacts(string $disk, ?string $path): array
{
    if ($path === null || $path === '') {
        return ['recorded' => false];
    }
    $storage = Storage::disk($disk);
    try {
        $exists = $storage->exists($path);
    } catch (Throwable) {
        $exists = false; // a path the disk refuses to even look at (a '../..' one) is "not there"
    }
    $facts = [
        'recorded' => true,
        'folder' => dirname($path),
        'extension' => strtolower(pathinfo($path, PATHINFO_EXTENSION)),
        'name_shape_ok' => preg_match('~^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\.(jpg|jpeg|png|webp)$~', basename($path)) === 1,
        'exists' => $exists,
    ];
    if ($facts['exists']) {
        $absolute = $storage->path($path);
        $bytes = (string) $storage->get($path);
        $size = @getimagesizefromstring($bytes);
        $facts += [
            'bytes' => strlen($bytes),
            'sha256_8' => substr(hash('sha256', $bytes), 0, 8),
            'mime' => (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes),
            'image' => $size === false ? null : ['width' => $size[0], 'height' => $size[1], 'type' => $size['mime'] ?? null],
            'decodes' => @imagecreatefromstring($bytes) !== false,
            'mtime' => gmdate('Y-m-d H:i:s', (int) filemtime($absolute)).'Z',
            'perms' => substr(sprintf('%o', fileperms($absolute)), -4),
        ];
    }

    return $facts;
}

/** What a diff compares: everything that is not a QA row. */
function state(): array
{
    $committees = qaCommitteeIds();
    $submissions = qaSubmissionIds();
    $users = qaUserIds();
    $roles = qaRoleIds();
    $units = qaUnitIds();

    $positionsSkip = $committees === [] ? [] : DB::table('committee_positions')->whereIn('committee_id', $committees)->pluck('id')->all();
    $linksSkip = $committees === [] ? [] : DB::table('committee_registration_links')->whereIn('committee_id', $committees)->pluck('id')->all();
    $membersSkip = $committees === [] && $submissions === [] ? [] : DB::table('committee_members')
        ->where(fn ($q) => $q->whereIn('committee_id', $committees ?: [0])->orWhereIn('committee_submission_id', $submissions ?: [0]))->pluck('id')->all();

    $history = [];
    foreach (DB::table('approval_history')->where('subject_type', CommitteeSubmission::class)->orderBy('id')->get() as $row) {
        if (! in_array((int) $row->subject_id, $submissions, true)) {
            $history[(string) $row->id] = sha1((string) json_encode($row));
        }
    }

    $userRows = [];
    $userQuery = DB::table('users')->orderBy('id');
    if ($users !== []) {
        $userQuery->whereNotIn('id', $users);
    }
    foreach ($userQuery->get(['id', 'ui_locale', 'status', 'deleted_at']) as $user) {
        $userRows[(string) $user->id] = [$user->ui_locale, $user->status, $user->deleted_at];
    }
    $roleQuery = DB::table('roles')->orderBy('id');
    if ($roles !== []) {
        $roleQuery->whereNotIn('id', $roles);
    }
    $rolePermissions = DB::table('role_permissions')->orderBy('role_id')->orderBy('permission_id')->get(['role_id', 'permission_id'])
        ->reject(fn ($r) => in_array((int) $r->role_id, $roles, true))->map(fn ($r) => $r->role_id.':'.$r->permission_id)->values()->all();

    $referencedPrivate = DB::table('committee_submissions')->whereNotIn('id', $submissions ?: [0])->pluck('photo_path')->filter()->all();
    $referencedPublic = DB::table('committee_submissions')->whereNotIn('id', $submissions ?: [0])->pluck('photo_approved_path')->filter()->all();
    $ownPrivate = DB::table('committee_submissions')->whereIn('id', $submissions ?: [0])->pluck('photo_path')->filter()->all();
    $ownPublic = DB::table('committee_submissions')->whereIn('id', $submissions ?: [0])->pluck('photo_approved_path')->filter()->all();

    return [
        'committees' => rowHashes('committees', $committees),
        'positions' => rowHashes('committee_positions', $positionsSkip),
        'links' => rowHashes('committee_registration_links', $linksSkip),
        'submissions' => rowHashes('committee_submissions', $submissions),
        'members' => rowHashes('committee_members', $membersSkip),
        'history' => $history,
        'units' => rowHashes('organizational_units', $units),
        'users' => $userRows,
        'roles' => $roleQuery->pluck('slug', 'id')->all(),
        'role_permission_links' => sha1(json_encode($rolePermissions)),
        'private_files' => array_diff_key(folderFiles('uploads_private', PRIVATE_FOLDER), array_flip($ownPrivate)),
        'public_files' => array_diff_key(folderFiles('public', PUBLIC_FOLDER), array_flip($ownPublic)),
        'referenced_private' => count($referencedPrivate),
        'referenced_public' => count($referencedPublic),
        'logs' => logSizes(),
    ];
}

$file = $STATE.'/'.$arg.'.json';

switch ($mode) {
    case 'inspect':
        $submission = DB::table('committee_submissions')->where('id', (int) $arg)->first();
        if ($submission === null) {
            out(['mode' => 'inspect', 'found' => false, 'submission_id' => (int) $arg]);
        }
        $committee = DB::table('committees')->where('id', $submission->committee_id)->first();
        $position = DB::table('committee_positions')->where('id', $submission->committee_position_id)->first();
        $seats = DB::table('committee_members')->where('committee_submission_id', $submission->id)->orderBy('id')->get();
        $trail = DB::table('approval_history')->where('subject_type', CommitteeSubmission::class)->where('subject_id', $submission->id)->orderBy('id')->get();
        $seatsInPosition = DB::table('committee_members')->where('committee_position_id', $submission->committee_position_id)->where('status', 'active')->count();
        $private = fileFacts('uploads_private', $submission->photo_path);
        $public = fileFacts('public', $submission->photo_approved_path);
        $release = realpath(base_path()) ?: base_path();
        $privateRoot = Storage::disk('uploads_private')->path('');
        $publicRoot = Storage::disk('public')->path('');
        $channels = config('logging.channels.stack.channels');

        out([
            'mode' => 'inspect',
            'submission' => [
                'id' => (int) $submission->id, 'committee_id' => (int) $submission->committee_id, 'position_id' => (int) $submission->committee_position_id,
                'status' => $submission->status, 'reviewed_by_user_id' => $submission->reviewed_by,
                'submitted_at' => $submission->submitted_at, 'reviewed_at' => $submission->reviewed_at, 'created_at' => $submission->created_at, 'updated_at' => $submission->updated_at,
                'member_id' => $submission->member_id, 'registration_link_id' => $submission->committee_registration_link_id,
                'filled' => [
                    'full_name' => trim((string) $submission->full_name) !== '', 'name_en' => trim((string) $submission->name_en) !== '', 'email' => trim((string) $submission->email) !== '',
                    'phone' => trim((string) $submission->phone) !== '', 'bio_chars' => mb_strlen((string) $submission->bio), 'comment_chars' => mb_strlen((string) $submission->provatferi_comment),
                    'facebook' => $submission->facebook_url !== null, 'linkedin' => $submission->linkedin_url !== null, 'website' => $submission->website_url !== null,
                    'admin_note_chars' => mb_strlen((string) $submission->admin_note),
                ],
                'consents' => ['publishing' => (bool) $submission->publishing_consent, 'accuracy' => (bool) $submission->accuracy_declaration],
                'correction' => ['token_present' => $submission->correction_token_hash !== null, 'expires_at' => $submission->correction_expires_at, 'used_at' => $submission->correction_used_at],
            ],
            'photo_private' => ['column' => 'photo_path'] + $private,
            'photo_public' => ['column' => 'photo_approved_path'] + $public,
            'public_copy_equals_private_original' => ($private['exists'] ?? false) && ($public['exists'] ?? false) ? ($private['sha256_8'] === $public['sha256_8']) : null,
            'public_url_path_shape' => $submission->photo_approved_path ? preg_replace('~[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}~', '<uuid>', parse_url(Storage::disk('public')->url($submission->photo_approved_path), PHP_URL_PATH) ?: '') : null,
            'audit_trail' => $trail->map(fn ($t) => ['id' => (int) $t->id, 'action' => $t->action, 'actor_type' => $t->actor_type, 'actor_id' => $t->actor_id, 'at' => $t->created_at, 'note_chars' => mb_strlen((string) $t->note)])->all(),
            'committee_seats_from_this_submission' => $seats->map(fn ($s) => ['id' => (int) $s->id, 'committee_id' => (int) $s->committee_id, 'position_id' => $s->committee_position_id, 'status' => $s->status, 'serial_no' => $s->serial_no, 'start_date' => $s->start_date])->all(),
            'committee' => $committee === null ? null : ['id' => (int) $committee->id, 'status' => $committee->status, 'term_start' => $committee->term_start, 'term_end' => $committee->term_end],
            'position' => $position === null ? null : ['id' => (int) $position->id, 'status' => $position->status, 'allow_duplicates' => (bool) $position->allow_duplicates, 'active_seats_now' => $seatsInPosition],
            'table_totals' => [
                'submissions' => DB::table('committee_submissions')->count(),
                'by_status' => DB::table('committee_submissions')->select('status', DB::raw('count(*) as n'))->groupBy('status')->pluck('n', 'status')->all(),
                'seats' => DB::table('committee_members')->count(),
            ],
            'environment' => [
                'app_env' => config('app.env'), 'app_debug' => (bool) config('app.debug'), 'php' => PHP_VERSION,
                'log_default' => config('logging.default'), 'log_stack_channels' => $channels, 'log_level' => config('logging.channels.single.level'),
                'log_files' => logSizes(),
                'uploads_private_root_inside_release_tree' => str_starts_with(realpath($privateRoot) ?: $privateRoot, $release.DIRECTORY_SEPARATOR),
                'public_root_inside_release_tree' => str_starts_with(realpath($publicRoot) ?: $publicRoot, $release.DIRECTORY_SEPARATOR),
                'uploads_private_root_writable' => is_writable($privateRoot), 'public_root_writable' => is_writable($publicRoot),
                'private_folder_files' => count(folderFiles('uploads_private', PRIVATE_FOLDER)), 'public_folder_files' => count(folderFiles('public', PUBLIC_FOLDER)),
            ],
        ]);

    case 'setup':
        $suffix = substr(bin2hex(random_bytes(4)), 0, 6);
        $tag = QA_COMMITTEE_MARK.$suffix;
        $result = [];
        DB::transaction(function () use ($suffix, $tag, &$result): void {
            $unit = OrganizationalUnit::query()->orderBy('id')->first();
            $unitCreated = false;
            if (! $unit) {
                $unit = OrganizationalUnit::query()->create(['name' => $tag.' unit', 'slug' => 'qa-cmt-unit-'.$suffix, 'unit_type' => 'head_office', 'status' => 'active']);
                $unitCreated = true;
            }
            $committee = Committee::query()->create([
                'organization_unit_id' => $unit->id, 'name' => $tag.' (ignore)', 'name_en' => $tag.' (ignore)', 'slug' => 'qa-cmt-'.$suffix,
                'status' => 'upcoming', 'description' => 'Disposable QA data — ignore.',
            ]);
            $positions = [];
            foreach ([1 => 'QA পদ এক', 2 => 'QA পদ দুই'] as $order => $name) {
                $p = CommitteePosition::query()->create(['committee_id' => $committee->id, 'name' => $name, 'name_en' => 'QA position '.$order, 'slug' => 'qa-cmt-pos-'.$order.'-'.$suffix, 'display_order' => $order, 'allow_duplicates' => true, 'status' => 'active']);
                $positions[] = ['id' => $p->id, 'name' => $p->name];
            }
            [$link, $raw] = CommitteeRegistrationLink::issue($committee, now()->addHours(3), null);
            $result = ['committee' => ['id' => $committee->id, 'name' => $committee->name, 'slug' => $committee->slug, 'status' => $committee->status], 'unit_created' => $unitCreated, 'positions' => $positions, 'link_id' => $link->id, 'registration_token' => $raw, 'link_expires_at' => $link->expires_at?->toIso8601String()];
        });
        out(['mode' => 'setup'] + $result);

    case 'status':
        $target = $argv[3];
        if (! in_array((int) $arg, qaCommitteeIds(), true)) {
            out(['mode' => 'status', 'ok' => false, 'error' => 'not a QA committee — nothing changed'], 2);
        }
        DB::table('committees')->where('id', (int) $arg)->update(['status' => $target, 'updated_at' => now()]);
        out(['mode' => 'status', 'ok' => true, 'committee_id' => (int) $arg, 'status' => DB::table('committees')->where('id', (int) $arg)->value('status')]);

    case 'seed':
        if (! in_array((int) $arg, qaCommitteeIds(), true)) {
            out(['mode' => 'seed', 'ok' => false, 'error' => 'not a QA committee — nothing seeded'], 2);
        }
        $committeeId = (int) $arg;
        $positionId = (int) DB::table('committee_positions')->where('committee_id', $committeeId)->orderBy('id')->value('id');
        $suffix = substr(bin2hex(random_bytes(3)), 0, 6);
        $cases = [
            'nophoto' => ['status' => 'pending', 'photo_path' => null],
            'lostphoto' => ['status' => 'pending', 'photo_path' => PRIVATE_FOLDER.'/'.Str::uuid()->toString().'.png'],
            'badpath' => ['status' => 'pending', 'photo_path' => '../../.env'],
            'rejected' => ['status' => 'rejected', 'photo_path' => null],
            'unpublished' => ['status' => 'unpublished', 'photo_path' => null],
        ];
        $made = [];
        DB::transaction(function () use ($cases, $committeeId, $positionId, $suffix, &$made): void {
            foreach ($cases as $label => $case) {
                $row = CommitteeSubmission::query()->create([
                    'committee_id' => $committeeId, 'committee_position_id' => $positionId,
                    'full_name' => QA_NAME_MARK.$suffix.' '.$label, 'email' => 'qacmt'.$suffix.$label.'@example.test', 'phone' => '01700000000',
                    'photo_path' => $case['photo_path'], 'provatferi_comment' => 'Disposable QA data — ignore.',
                    'publishing_consent' => true, 'accuracy_declaration' => true, 'status' => $case['status'], 'submitted_at' => now(),
                ]);
                $made[$label] = $row->id;
            }
        });
        out(['mode' => 'seed', 'ok' => true, 'committee_id' => $committeeId, 'submissions' => $made]);

    case 'inventory':
        $qaCommittees = qaCommitteeIds();
        $qaSubmissions = qaSubmissionIds();
        out([
            'mode' => 'inventory', 'app_env' => config('app.env'), 'app_path' => base_path(),
            'committees' => ['total' => DB::table('committees')->count(), 'qa' => count($qaCommittees), 'by_status' => DB::table('committees')->select('status', DB::raw('count(*) as n'))->groupBy('status')->pluck('n', 'status')->all()],
            'submissions' => ['total' => DB::table('committee_submissions')->count(), 'qa' => count($qaSubmissions), 'by_status' => DB::table('committee_submissions')->select('status', DB::raw('count(*) as n'))->groupBy('status')->pluck('n', 'status')->all(), 'max_id' => (int) DB::table('committee_submissions')->max('id')],
            'seats' => ['total' => DB::table('committee_members')->count(), 'from_submissions' => DB::table('committee_members')->whereNotNull('committee_submission_id')->count()],
            'qa_users' => qaUserIds(), 'qa_roles' => qaRoleIds(), 'qa_units' => qaUnitIds(),
            'private_files' => count(folderFiles('uploads_private', PRIVATE_FOLDER)), 'public_files' => count(folderFiles('public', PUBLIC_FOLDER)),
            'logs' => logSizes(),
        ]);

    case 'snapshot':
        if (is_file($file)) {
            out(['replayed_first_run' => true, 'snapshot' => $arg]);
        }
        if (! is_dir($STATE)) {
            mkdir($STATE, 0700, true);
        }
        $state = state();
        file_put_contents($file, json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
        out(['mode' => 'snapshot', 'tag' => $arg, 'committees' => count($state['committees']), 'positions' => count($state['positions']), 'submissions' => count($state['submissions']),
            'seats' => count($state['members']), 'audit_rows' => count($state['history']), 'users' => count($state['users']), 'roles' => count($state['roles']),
            'private_files' => count($state['private_files']), 'public_files' => count($state['public_files']), 'logs' => $state['logs']]);

    case 'logs':
        $then = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        if (! is_array($then)) {
            out(['error' => "no snapshot named {$arg}"], 1);
        }
        $lines = [];
        $scanned = 0;
        foreach (glob(storage_path('logs/*.log')) ?: [] as $path) {
            $size = (int) filesize($path);
            $from = (int) (($then['logs'] ?? [])[basename($path)] ?? 0);
            if ($size <= $from) {
                continue;
            }
            $handle = fopen($path, 'rb');
            fseek($handle, $from);
            $chunk = (string) stream_get_contents($handle, 2_000_000);
            fclose($handle);
            $scanned += strlen($chunk);
            foreach (preg_split('/\R/', $chunk) ?: [] as $line) {
                if (preg_match('/^\[[^\]]+\]\s+\S+\.(ERROR|CRITICAL|ALERT|EMERGENCY|WARNING):\s*(.*)$/', $line, $m) === 1) {
                    $lines[] = $m[1].': '.mask($m[2]);
                }
            }
        }
        out(['mode' => 'logs', 'tag' => $arg, 'bytes_scanned' => $scanned, 'warning_or_error_lines' => count($lines), 'lines' => array_slice($lines, 0, 40)]);

    case 'diff':
    case 'purge':
        $then = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        if (! is_array($then)) {
            out(['error' => "no snapshot named {$arg}"], 1);
        }

        $purged = null;
        if ($mode === 'purge') {
            $go = ($argv[3] ?? '') === 'go';
            $qaCommittees = qaCommitteeIds();
            $qaSubmissions = qaSubmissionIds();
            $qaUsers = qaUserIds();
            $qaRoles = qaRoleIds();
            $qaUnits = qaUnitIds();

            $rows = $qaSubmissions === [] ? collect() : DB::table('committee_submissions')->whereIn('id', $qaSubmissions)->get(['id', 'photo_path', 'photo_approved_path']);
            $keepPrivate = DB::table('committee_submissions')->whereNotIn('id', $qaSubmissions ?: [0])->pluck('photo_path')->filter()->all();
            $keepPublic = DB::table('committee_submissions')->whereNotIn('id', $qaSubmissions ?: [0])->pluck('photo_approved_path')->filter()->all();
            // A file a REAL application also references is never ours to delete.
            $ownPrivate = array_values(array_diff($rows->pluck('photo_path')->filter()->unique()->all(), $keepPrivate));
            $ownPublic = array_values(array_diff($rows->pluck('photo_approved_path')->filter()->unique()->all(), $keepPublic));

            // A path read from a row is never used to delete a file unless it has the exact shape the application itself writes
            // (one folder, one plain file name, an image extension): the `seed` rows with a '../..' path exist precisely to be
            // refused, and the purge must refuse them too. Anything else is only counted.
            $isSafe = fn (string $pattern) => fn (string $path): bool => preg_match($pattern, $path) === 1;
            $safePrivate = '~^'.preg_quote(PRIVATE_FOLDER, '~').'/[A-Za-z0-9][A-Za-z0-9._-]{0,120}\.(?:jpe?g|png|webp)\z~i';
            $safePublic = '~^'.preg_quote(PUBLIC_FOLDER, '~').'/[A-Za-z0-9][A-Za-z0-9._-]{0,120}\.(?:jpe?g|png|webp)\z~i';
            $unsafeCount = count(array_filter($ownPrivate, fn ($p) => ! $isSafe($safePrivate)($p))) + count(array_filter($ownPublic, fn ($p) => ! $isSafe($safePublic)($p)));
            $ownPrivate = array_values(array_filter($ownPrivate, $isSafe($safePrivate)));
            $ownPublic = array_values(array_filter($ownPublic, $isSafe($safePublic)));

            // A file nobody references and the baseline did not list: an upload whose row was never written. Only one older than
            // half an hour — a real applicant's photo is stored a moment BEFORE their row is.
            $stray = ['private' => [], 'public' => []];
            foreach (['private' => ['uploads_private', PRIVATE_FOLDER, $keepPrivate, $ownPrivate, 'private_files'], 'public' => ['public', PUBLIC_FOLDER, $keepPublic, $ownPublic, 'public_files']] as $key => [$disk, $folder, $keep, $own, $baselineKey]) {
                foreach (folderFiles($disk, $folder) as $path => $size) {
                    if (! in_array($path, $keep, true) && ! in_array($path, $own, true) && ! array_key_exists($path, $then[$baselineKey] ?? [])
                        && Storage::disk($disk)->lastModified($path) < time() - STRAY_MIN_AGE_SECONDS) {
                        $stray[$key][] = $path;
                    }
                }
            }

            $plan = ['committees' => $qaCommittees, 'submissions' => $qaSubmissions, 'users' => $qaUsers, 'roles' => $qaRoles, 'units' => $qaUnits,
                'private_photos' => $ownPrivate, 'public_photos' => $ownPublic, 'stray_private' => $stray['private'], 'stray_public' => $stray['public'],
                'unsafe_paths_left_alone' => $unsafeCount];
            if (! $go) {
                out(['mode' => 'purge', 'dry_run' => true, 'would_delete' => $plan, 'hint' => 'run again with the word "go" to delete']);
            }

            DB::transaction(function () use ($qaCommittees, $qaSubmissions, $qaUsers, $qaRoles, $qaUnits): void {
                if ($qaSubmissions !== []) {
                    ApprovalHistory::query()->where('subject_type', CommitteeSubmission::class)->whereIn('subject_id', $qaSubmissions)->delete();
                }
                if ($qaCommittees !== [] || $qaSubmissions !== []) {
                    CommitteeMember::query()->where(fn ($q) => $q->whereIn('committee_id', $qaCommittees ?: [0])->orWhereIn('committee_submission_id', $qaSubmissions ?: [0]))->delete();
                }
                if ($qaSubmissions !== []) {
                    CommitteeSubmission::query()->whereIn('id', $qaSubmissions)->delete();
                }
                if ($qaCommittees !== []) {
                    CommitteeRegistrationLink::query()->whereIn('committee_id', $qaCommittees)->delete();
                    CommitteePosition::query()->whereIn('committee_id', $qaCommittees)->delete();
                    Committee::query()->whereIn('id', $qaCommittees)->delete();
                }
                if ($qaUnits !== []) {
                    OrganizationalUnit::query()->whereIn('id', $qaUnits)->delete();
                }
                if ($qaUsers !== []) {
                    if (Schema::hasTable('sessions')) {
                        DB::table('sessions')->whereIn('user_id', $qaUsers)->delete();
                    }
                    if (Schema::hasTable('personal_access_tokens')) {
                        DB::table('personal_access_tokens')->where('tokenable_type', 'App\\Models\\User')->whereIn('tokenable_id', $qaUsers)->delete();
                    }
                    DB::table('user_roles')->whereIn('user_id', $qaUsers)->delete();
                    DB::table('users')->whereIn('id', $qaUsers)->delete();
                }
                if ($qaRoles !== []) {
                    DB::table('user_roles')->whereIn('role_id', $qaRoles)->delete();
                    DB::table('role_permissions')->whereIn('role_id', $qaRoles)->delete();
                    DB::table('roles')->whereIn('id', $qaRoles)->delete();
                }
            });

            $removed = 0;
            foreach ([['uploads_private', [...$ownPrivate, ...$stray['private']]], ['public', [...$ownPublic, ...$stray['public']]]] as [$disk, $paths]) {
                foreach ($paths as $path) {
                    try {
                        if (Storage::disk($disk)->exists($path) && Storage::disk($disk)->delete($path)) {
                            $removed++;
                        }
                    } catch (Throwable) {
                        // A file that cannot be removed is left for the next run (it then shows up as a stray); never abort halfway.
                    }
                }
            }
            $purged = ['committees' => count($qaCommittees), 'submissions' => count($qaSubmissions), 'users' => count($qaUsers), 'roles' => count($qaRoles), 'units' => count($qaUnits), 'files_removed' => $removed];
        }

        $now = state();
        $changed = [];
        foreach (['committees', 'positions', 'links', 'submissions', 'members', 'history', 'units', 'users', 'roles', 'private_files', 'public_files'] as $section) {
            $before = $then[$section] ?? [];
            $after = $now[$section];
            $diff = [
                'changed' => array_keys(array_filter($before, fn ($value, $key) => array_key_exists($key, $after) && $after[$key] != $value, ARRAY_FILTER_USE_BOTH)),
                'added' => array_values(array_diff(array_keys($after), array_keys($before))),
                'missing' => array_values(array_diff(array_keys($before), array_keys($after))),
            ];
            if ($diff['changed'] !== [] || $diff['added'] !== [] || $diff['missing'] !== []) {
                $changed[$section] = $diff;
            }
        }
        if (($then['role_permission_links'] ?? null) !== $now['role_permission_links']) {
            $changed['role_permission_links'] = ['was' => $then['role_permission_links'] ?? null, 'now' => $now['role_permission_links']];
        }
        $left = ['qa_committees' => qaCommitteeIds(), 'qa_submissions' => qaSubmissionIds(), 'qa_users' => qaUserIds(), 'qa_roles' => qaRoleIds(), 'qa_units' => qaUnitIds()];
        $identical = $changed === [] && array_filter($left) === [];
        out(['mode' => $mode, 'tag' => $arg, 'purged' => $purged, 'differences_from_snapshot' => $changed, 'qa_leftovers' => $left, 'identical_to_snapshot' => $identical]);

    case 'forget':
        $existed = is_file($file);
        if ($existed) {
            unlink($file);
        }
        if (is_dir($STATE) && (glob($STATE.'/*') ?: []) === []) {
            rmdir($STATE);
        }
        out(['mode' => 'forget', 'tag' => $arg, 'snapshot_removed' => $existed, 'state_dir_left' => is_dir($STATE)]);
}
