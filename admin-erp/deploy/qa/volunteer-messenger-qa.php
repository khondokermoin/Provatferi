<?php
/**
 * PRODUCTION ACCEPTANCE KIT for the gated volunteer Messenger group (docs/VOLUNTEER_MESSENGER_GROUP.md). CLI only: 404
 * otherwise. Upload it under a one-off name (`php deploy/qa/cron-guard.php new-session`) and run each mode from a cron. It
 * prints no secret: the saved invite is shown only as a short digest of its token, never as a link. The browser half is
 * institutional/scripts/volunteer-messenger-qa.mjs; this half proves what a browser cannot see (the rows and files behind
 * the applications it submits, the saved setting, the log) and removes the run's data afterwards.
 *
 * It touches ONLY what belongs to a QA run, recognised by TWO markers the browser kit puts into every application it
 * submits: the applicant name starts with "QA-VMG-" AND the e-mail address contains "qavmg". A real applicant carries
 * neither. Real postings, notices and applications are never changed, and the kit never creates a posting: the browser
 * kit applies to the real volunteer posting and this kit removes exactly the applications it made.
 *
 *   inventory
 *       Read-only. Table presence, the postings (slug, status, whether they take applications), application counts (real /
 *       QA), the two Messenger settings (as digests), the private application folders and the log files' sizes.
 *   settings
 *       Read-only. The central Messenger group's state: switched on?, link saved?, canonical?, the token's length and the
 *       first 8 hex characters of its sha256, whether each row is private (is_public = false), when and by whom it was
 *       last changed. Never the link and never the token.
 *   snapshot <tag>
 *       Writes the state of everything that is NOT a QA row to storage/app/volunteer-messenger-qa/<tag>.json (once per tag;
 *       a later run replays): a hash of every real application, posting and notice, the settings other than the two
 *       Messenger rows, the application folders' file lists, the highest application id and every log file's size.
 *   diff <tag>
 *       Read-only. Compares the live state with the snapshot. `identical_to_snapshot` is true only when nothing real changed
 *       AND no QA application or stray file is left. The two Messenger settings are listed apart (`messenger_settings`):
 *       setting the real link at the end of the acceptance is intended and is not a difference.
 *   logs <tag>
 *       Read-only. Lines written to the Laravel log since the snapshot, error level and above only, each reduced to its
 *       level and the first 160 characters of the message with addresses and long tokens masked. Empty means a clean log.
 *   purge <tag> go
 *       Deletes the QA applications and their files in one transaction, then proves the next application number is the
 *       one it would have been. Without the word "go" it only lists what it would delete.
 *   forget <tag>
 *       Deletes the snapshot file (and the folder once empty).
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
ini_set('display_errors', 'stderr');

const QA_DOMAIN_ROOT = '/home/u951246149/domains/provatferi.org';
const QA_NAME_MARK = 'QA-VMG-';
const QA_EMAIL_MARK = 'qavmg';
$APP = getenv('QA_APP') ?: (is_dir(QA_DOMAIN_ROOT.'/laravel-admin') ? QA_DOMAIN_ROOT.'/laravel-admin' : dirname(__DIR__, 2));
$STATE = $APP.'/storage/app/volunteer-messenger-qa';
$mode = $argv[1] ?? '';
$tag = (string) ($argv[2] ?? '');

function out(array $data, int $exit = 0): never
{
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE), "\n";
    exit($exit);
}

const MODES = ['inventory', 'settings', 'snapshot', 'diff', 'logs', 'purge', 'forget'];
if (! in_array($mode, MODES, true)) {
    fwrite(STDERR, "modes: inventory | settings | snapshot <tag> | diff <tag> | logs <tag> | purge <tag> go | forget <tag>\n");
    exit(2);
}
if (in_array($mode, ['snapshot', 'diff', 'logs', 'purge', 'forget'], true) && preg_match('/^[a-z0-9]{6,24}$/', $tag) !== 1) {
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

use App\Support\Recruitment\CentralMessengerGroup;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

const FOLDERS = ['applications/photos', 'applications/cv'];
const STRAY_MIN_AGE_SECONDS = 1800;
const MESSENGER_KEYS = [CentralMessengerGroup::KEY_ENABLED, CentralMessengerGroup::KEY_URL];

/** @return list<int> */
function qaApplicationIds(): array
{
    if (! Schema::hasTable('job_applications')) {
        return [];
    }

    return DB::table('job_applications')
        ->where('applicant_name', 'like', QA_NAME_MARK.'%')
        ->where('applicant_email', 'like', '%'.QA_EMAIL_MARK.'%')
        ->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
}

/** @return array<string, int> path => size, for the private application folders */
function applicationFiles(): array
{
    $disk = Storage::disk('uploads_private');
    $files = [];
    foreach (FOLDERS as $folder) {
        foreach ($disk->allFiles($folder) as $path) {
            $files[$path] = (int) $disk->size($path);
        }
    }
    ksort($files);

    return $files;
}

/**
 * The files the given applications reference. An EMPTY list means "no application", never "every application": a purge
 * that finds nothing of its own must delete nothing.
 *
 * @param  list<int>  $ids
 * @return list<string>
 */
function filesOf(array $ids): array
{
    if ($ids === []) {
        return [];
    }

    return pathsOf(DB::table('job_applications')->whereIn('id', $ids)->get(['photo_path', 'cv_path']));
}

/** The files every application EXCEPT the given ones reference (all of them when the list is empty). @return list<string> */
function filesExcept(array $ids): array
{
    $query = DB::table('job_applications');
    if ($ids !== []) {
        $query->whereNotIn('id', $ids);
    }

    return pathsOf($query->get(['photo_path', 'cv_path']));
}

/** @return list<string> */
function pathsOf(iterable $rows): array
{
    $paths = [];
    foreach ($rows as $row) {
        foreach ([$row->photo_path, $row->cv_path] as $path) {
            if (is_string($path) && $path !== '') {
                $paths[] = $path;
            }
        }
    }

    return array_values(array_unique($paths));
}

/** @return array<string, string> id => hash of the row, for every row of $table not in $skip */
function rowHashes(string $table, array $skip = []): array
{
    if (! Schema::hasTable($table)) {
        return [];
    }
    $query = DB::table($table)->orderBy('id');
    if ($skip !== []) {
        $query->whereNotIn('id', $skip);
    }
    $hashes = [];
    foreach ($query->get() as $row) {
        $hashes[(string) $row->id] = sha1((string) json_encode($row));
    }

    return $hashes;
}

/** The central group's state with no link in it. */
function messengerState(): array
{
    $rows = DB::table('settings')->whereIn('key', MESSENGER_KEYS)->get()->keyBy('key');
    $url = $rows[CentralMessengerGroup::KEY_URL] ?? null;
    $enabled = $rows[CentralMessengerGroup::KEY_ENABLED] ?? null;
    $token = CentralMessengerGroup::tokenOf(is_string($url->value ?? null) ? $url->value : null);
    $changed = collect([$url, $enabled])->filter()->sortByDesc('updated_at')->first();

    return [
        'enabled_row' => $enabled !== null,
        'enabled' => CentralMessengerGroup::enabled(),
        'link_saved' => is_string($url->value ?? null) && $url->value !== '',
        'link_canonical' => $token !== null && ($url->value ?? null) === 'https://m.me/j/'.$token.'/',
        'token_length' => $token === null ? null : strlen($token),
        'token_sha256_8' => $token === null ? null : substr(hash('sha256', $token), 0, 8),
        'rows_private' => collect([$url, $enabled])->filter()->every(fn ($row) => (int) $row->is_public === 0),
        'offered_now' => CentralMessengerGroup::publicAction() !== null,
        'last_changed_at' => $changed->updated_at ?? null,
        'last_changed_by_user_id' => $changed->updated_by ?? null,
    ];
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

/** What a diff compares: everything that is not a QA row. */
function state(): array
{
    $qa = qaApplicationIds();
    $settings = [];
    foreach (DB::table('settings')->whereNotIn('key', MESSENGER_KEYS)->orderBy('key')->get(['key', 'value', 'is_public']) as $row) {
        $settings[$row->key] = sha1(json_encode([$row->value, $row->is_public]));
    }
    $real = DB::table('job_applications');
    if ($qa !== []) {
        $real->whereNotIn('id', $qa);
    }

    return [
        'applications' => rowHashes('job_applications', $qa),
        'application_max_id' => (int) (clone $real)->max('id'),
        'postings' => rowHashes('job_postings'),
        'notices' => rowHashes('notices'),
        'settings' => $settings,
        'files' => array_diff_key(applicationFiles(), array_flip(filesOf($qa))),
        'logs' => logSizes(),
    ];
}

/** Masks anything that could identify a person or carry a secret in a log line. */
function mask(string $line): string
{
    $line = preg_replace('/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+/', '<address>', $line) ?? $line;
    $line = preg_replace('~https?://\S+~', '<url>', $line) ?? $line;
    $line = preg_replace('/[A-Za-z0-9_\-+\/=]{32,}/', '<long-token>', $line) ?? $line;

    return mb_substr($line, 0, 160);
}

$file = $STATE.'/'.$tag.'.json';

switch ($mode) {
    case 'inventory':
        $qa = qaApplicationIds();
        $total = Schema::hasTable('job_applications') ? DB::table('job_applications')->count() : 0;
        out([
            'mode' => 'inventory',
            'app_env' => config('app.env'), 'app_path' => base_path(), 'cache_store' => config('cache.default'), 'session_driver' => config('session.driver'),
            'postings' => DB::table('job_postings')->whereNull('deleted_at')->orderBy('id')->get(['id', 'slug', 'status', 'accepts_applications', 'employment_type', 'application_mode'])->all(),
            'applications' => ['total' => $total, 'real' => $total - count($qa), 'qa' => count($qa), 'max_id' => (int) DB::table('job_applications')->max('id')],
            'messenger' => messengerState(),
            'application_files' => count(applicationFiles()),
            'logs' => logSizes(),
        ]);

    case 'settings':
        out(['mode' => 'settings', 'messenger' => messengerState()]);

    case 'snapshot':
        if (is_file($file)) {
            out(['replayed_first_run' => true, 'snapshot' => $tag]);
        }
        if (! is_dir($STATE)) {
            mkdir($STATE, 0700, true);
        }
        $state = state();
        $state['messenger_at_snapshot'] = messengerState();
        file_put_contents($file, json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
        out(['mode' => 'snapshot', 'tag' => $tag, 'real_applications' => count($state['applications']), 'application_max_id' => $state['application_max_id'],
            'postings' => count($state['postings']), 'notices' => count($state['notices']), 'settings' => count($state['settings']),
            'files' => count($state['files']), 'logs' => $state['logs'], 'messenger' => $state['messenger_at_snapshot']]);

    case 'logs':
        $then = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        if (! is_array($then)) {
            out(['error' => "no snapshot named {$tag}"], 1);
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
                if (preg_match('/^\[[^\]]+\]\s+\S+\.(ERROR|CRITICAL|ALERT|EMERGENCY):\s*(.*)$/', $line, $m) === 1) {
                    $lines[] = $m[1].': '.mask($m[2]);
                }
            }
        }
        out(['mode' => 'logs', 'tag' => $tag, 'bytes_scanned' => $scanned, 'error_lines' => count($lines), 'lines' => array_slice($lines, 0, 40)]);

    case 'diff':
    case 'purge':
        $then = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        if (! is_array($then)) {
            out(['error' => "no snapshot named {$tag}"], 1);
        }

        $purged = null;
        if ($mode === 'purge') {
            $go = ($argv[3] ?? '') === 'go';
            $qa = qaApplicationIds();
            $ownFiles = filesOf($qa);
            $keepers = filesExcept($qa);
            // A file a REAL application also references is never ours to delete.
            $ownFiles = array_values(array_diff($ownFiles, $keepers));
            // A file no application references and the baseline did not list: an upload whose application was never written.
            // Only one older than half an hour: a real applicant's files are stored a moment BEFORE their row is, and a purge that
            // ran in that gap must not take them.
            $stray = [];
            foreach (applicationFiles() as $path => $size) {
                if (! in_array($path, $keepers, true) && ! in_array($path, $ownFiles, true) && ! array_key_exists($path, $then['files'] ?? [])
                    && Storage::disk('uploads_private')->lastModified($path) < time() - STRAY_MIN_AGE_SECONDS) {
                    $stray[] = $path;
                }
            }
            $plan = ['applications' => $qa, 'application_files' => $ownFiles, 'stray_files' => $stray];
            if (! $go) {
                out(['mode' => 'purge', 'dry_run' => true, 'would_delete' => $plan, 'hint' => 'run again with the word "go" to delete']);
            }

            DB::transaction(function () use ($qa): void {
                if ($qa !== []) {
                    DB::table('job_applications')->whereIn('id', $qa)->delete();
                }
            });

            $removed = [];
            foreach ([...$ownFiles, ...$stray] as $path) {
                if (Storage::disk('uploads_private')->exists($path) && Storage::disk('uploads_private')->delete($path)) {
                    $removed[] = $path;
                }
            }
            $purged = ['applications' => count($qa), 'files_removed' => count($removed), 'next_application_no' => App\Models\JobApplication::generateApplicationNo()];
        }

        $now = state();
        $changed = [];
        foreach (['applications', 'postings', 'notices', 'settings', 'files'] as $section) {
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
        if (($then['application_max_id'] ?? null) !== $now['application_max_id']) {
            $changed['application_max_id'] = ['was' => $then['application_max_id'] ?? null, 'now' => $now['application_max_id']];
        }
        $left = ['qa_applications' => qaApplicationIds()];
        $identical = $changed === [] && $left['qa_applications'] === [];
        out([
            'mode' => $mode, 'tag' => $tag, 'purged' => $purged, 'differences_from_snapshot' => $changed, 'qa_leftovers' => $left,
            'identical_to_snapshot' => $identical,
            'messenger_settings' => ['at_snapshot' => $then['messenger_at_snapshot'] ?? null, 'now' => messengerState()],
        ]);

    case 'forget':
        $existed = is_file($file);
        if ($existed) {
            unlink($file);
        }
        if (is_dir($STATE) && (glob($STATE.'/*') ?: []) === []) {
            rmdir($STATE);
        }
        out(['mode' => 'forget', 'tag' => $tag, 'snapshot_removed' => $existed, 'state_dir_left' => is_dir($STATE)]);
}
