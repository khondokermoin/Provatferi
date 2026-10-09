<?php
/**
 * Production acceptance for the stage -> switch upload race (lib/uploads-sync.php). CLI only. Run it where the
 * Laravel app lives, through a one-shot cron like the other one-shot scripts. Everything it creates is labelled
 * `_qa-deploy-race` (files) or `QA TIMING TEST` (the volunteer applications institutional/scripts/submit-qa.mjs
 * submits), is recorded in a state file OUTSIDE the swapped application tree, and is removed by `cleanup`.
 *
 *   put <label>            writes one disposable PRIVATE upload (random bytes, through the app's own uploads_private
 *                          disk) and one disposable PUBLIC file (through the app's own public disk), exactly as the
 *                          live application would, and records bytes, size, mtime, mode and which application
 *                          directory (by inode) took the write. Run it before `stage` and again between `stage` and
 *                          `switch`.
 *   put-once <label>       as put, but a label that was already written is reported, not written again — so it is safe
 *                          to run from a `* * * * *` cron (fast feedback) and then delete the cron.
 *   writer [seconds] [interval-ms] [max-files]
 *                          behaves like the live application's upload path for a few minutes: through the app's own
 *                          uploads_private disk it writes small random files (default one every 200 ms, 150 s, at most
 *                          1000) and logs each successful write with the inode of the application directory that took
 *                          it. Start it a minute before `switch`: it keeps writing across the stage -> switch window,
 *                          the rename and the sweeps. Hard limits: 240 s, 1500 files, stops at once if
 *                          <state dir>/writer.stop appears.
 *   drill-setup            the recovery/collision drill, part 1: in the tree THIS deploy retired it plants one disposable
 *                          file the live tree lacks (what the pre-fix tooling used to strand) and one path that holds
 *                          different bytes on both sides (a collision). Then run, in this order:
 *                          `release-manager.php reconcile <retired>` (dry run), `… apply` (must be REFUSED, changing nothing),
 *                          `… apply keep-both` — and finally drill-verify.
 *   drill-verify           the drill, part 2: the stranded file now exists in live with the same bytes; the collision kept
 *                          the live version at its path; the other version is preserved byte for byte under
 *                          _upload-conflicts/reconcile-<retired>/ with a ledger entry; the retired tree is untouched.
 *   register-application   records the volunteer applications a real browser just submitted (name starts with
 *                          "QA TIMING TEST"): their database row, photo and CV paths and the files' SHA-256.
 *   verify                 after `switch`: every recorded private file is in the LIVE tree with the recorded hash AND
 *                          readable through the app's own disk; every public file is in the docroot and answers HTTP
 *                          200 with the same bytes; mtime/mode survived; the registered applications still have their
 *                          files; and no retired tree (_previous-*, _rolled-back-*, _stray-*) holds an upload the
 *                          live tree lacks ("stranded") — that check covers the trees THIS deploy retired (CURRENT_RELEASE's
 *                          previous_path and any stray); older retired trees are reported separately, with whether each
 *                          missing file is referenced by a database row, because they predate the deploy being tested and
 *                          are a warning, never a failure. Exit code 1 unless all of it holds.
 *   inspect                the same scan as verify, read-only, no pass/fail exit code.
 *   cleanup                deletes every file (live, public, every retired tree, _upload-conflicts), every registered
 *                          database row, then the state file, and proves nothing is left.
 *
 * Environment: QA_APP (application directory), QA_RELEASES (laravel-admin-releases), QA_STATE_DIR, QA_SKIP_HTTP=1.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
ini_set('display_errors', 'stderr');
set_time_limit(0);

$mode = $argv[1] ?? 'inspect';

// PRODUCTION SAFETY (2026-10-09). `cleanup` deletes data, so on the live host it only runs from a kit uploaded under a one-off name made
// for THIS session (_qa_<8+ hex>.php, or _qa_<word>_<hex>.php) — never from a fixed name that an old cron can still point at: a minutely
// `_qa_registry.php cleanup` cron that the API could neither list nor delete wiped QA rows a day after it was made (deploy/README.md,
// "Cron jobs"). Local runs are not gated; QA_FORCE_PRODUCTION_GUARDS=1 applies the production rule anywhere (the tests use it).
if ($mode === 'cleanup' && (is_dir('/home/u951246149/domains') || getenv('QA_FORCE_PRODUCTION_GUARDS') === '1')
    && ! preg_match('/^_qa_(?:[a-z]+_)?[0-9a-f]{8,}\.php$/', basename(__FILE__))) {
    fwrite(STDERR, json_encode(['mode' => 'cleanup', 'ok' => false, 'error' => 'refusing on production: a destructive mode runs only from a kit named _qa_<hex>.php made for this session (this file is '.basename(__FILE__).') — nothing was removed'], JSON_UNESCAPED_SLASHES)."\n");
    exit(2);
}
$APP = getenv('QA_APP') ?: (is_dir('/home/u951246149/domains/provatferi.org/laravel-admin') ? '/home/u951246149/domains/provatferi.org/laravel-admin' : dirname(__DIR__, 2));
$APP = rtrim(str_replace('\\', '/', $APP), '/');
$RELEASES = rtrim(str_replace('\\', '/', getenv('QA_RELEASES') ?: dirname($APP).'/laravel-admin-releases'), '/');
$STATE_DIR = rtrim(str_replace('\\', '/', getenv('QA_STATE_DIR') ?: $RELEASES.'/_qa-uploads-race'), '/');
$STATE = $STATE_DIR.'/state.json';
const QA_DIR = '_qa-deploy-race';
const QA_NAME = 'QA TIMING TEST';

define('LARAVEL_START', microtime(true));
define('LARAVEL_PUBLIC_PATH_OVERRIDE', $APP.'/public');
require $APP.'/vendor/autoload.php';
$app = require $APP.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\JobApplication;
use Illuminate\Support\Facades\Storage;

function out(array $data, int $exit = 0): never
{
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE), "\n";
    exit($exit);
}

function loadState(string $file): array
{
    $state = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;

    return is_array($state) ? $state : ['entries' => [], 'applications' => []];
}

function saveState(string $dir, string $file, array $state): void
{
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    file_put_contents($file, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

function sha(?string $path): ?string
{
    clearstatcache(true, (string) $path);

    return $path !== null && is_file($path) ? hash_file('sha256', $path) : null;
}

/** @return string[] the application directories that are NOT live: _previous-*, _rolled-back-*, _stray-* */
function retiredTrees(string $releases): array
{
    $trees = [];
    foreach (is_dir($releases) ? (scandir($releases) ?: []) : [] as $name) {
        if (preg_match('/^_(previous|rolled-back|stray)-/', $name) && is_dir($releases.'/'.$name) && !is_link($releases.'/'.$name)) $trees[] = $releases.'/'.$name;
    }

    return $trees;
}

function uploadsOf(string $appDir): string
{
    return $appDir.'/storage/app/private/uploads';
}

/** Every file under $root as rel => sha256 (plain PHP on purpose: this verifier does not use the code under test). */
function hashTree(string $root): array
{
    $files = [];
    if (!is_dir($root)) return $files;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if ($file->isLink() || !$file->isFile() || in_array($file->getFilename(), ['.gitignore', '.gitkeep'], true) || str_starts_with($file->getFilename(), '.usync-')) continue;
        $files[ltrim(str_replace('\\', '/', substr($file->getPathname(), strlen($root))), '/')] = hash_file('sha256', $file->getPathname());
    }
    ksort($files);

    return $files;
}

/** Files that exist in a retired tree but not, byte-identical, in the live tree. */
function stranded(string $appLive, array $retired): array
{
    $live = hashTree(uploadsOf($appLive));
    $out = [];
    foreach ($retired as $tree) {
        foreach (hashTree(uploadsOf($tree)) as $rel => $hash) {
            if (($live[$rel] ?? null) !== $hash) $out[] = ['tree' => basename($tree), 'path' => $rel, 'sha256' => $hash, 'in_live' => isset($live[$rel]) ? 'different bytes' : 'missing'];
        }
    }

    return $out;
}

/** Deletes a directory tree — but only one that is our own marker directory, never anything else. */
function removeMarkerTree(string $dir): int
{
    if (basename($dir) !== QA_DIR || !is_dir($dir) || is_link($dir)) return 0;
    $count = 0;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $item) {
        if ($item->isLink() || $item->isFile()) { if (@unlink($item->getPathname())) $count++; }
        else @rmdir($item->getPathname());
    }
    @rmdir($dir);

    return $count;
}

/** The retired trees THIS deploy produced: CURRENT_RELEASE.json's previous_path, plus any stray its switch set aside. */
function thisDeploysRetiredTrees(string $releases): array
{
    $record = is_file($releases.'/CURRENT_RELEASE.json') ? json_decode((string) file_get_contents($releases.'/CURRENT_RELEASE.json'), true) : null;
    if (!is_array($record)) return [];
    $trees = [];
    if (!empty($record['previous_path']) && is_dir($record['previous_path'])) $trees[] = rtrim(str_replace('\\', '/', $record['previous_path']), '/');
    $statusFile = $releases.'/'.($record['release_id'] ?? '_none').'/status.json';
    $status = is_file($statusFile) ? json_decode((string) file_get_contents($statusFile), true) : null;
    foreach (is_array($status) ? ($status['switch']['strays'] ?? []) : [] as $stray) {
        if (is_string($stray) && is_dir($stray)) $trees[] = rtrim(str_replace('\\', '/', $stray), '/');
    }

    return $trees;
}

/** Whether a database row points at this upload path (only the job_applications columns are checked; other folders are not). */
function referencedByRow(string $rel): ?bool
{
    if (!str_starts_with($rel, 'applications/')) return null;

    return JobApplication::query()->where('photo_path', $rel)->orWhere('cv_path', $rel)->exists();
}

function currentRelease(string $releases): ?string
{
    $file = $releases.'/CURRENT_RELEASE.json';
    $json = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;

    return is_array($json) ? ($json['release_id'] ?? null) : null;
}

function httpGet(string $url): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_FOLLOWLOCATION => false, CURLOPT_HEADER => false]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $type = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);

    return ['code' => $code, 'content_type' => $type, 'bytes' => is_string($body) ? strlen($body) : null, 'sha256' => is_string($body) ? hash('sha256', $body) : null];
}

$state = loadState($STATE);
$disk = Storage::disk('uploads_private');
$publicDisk = Storage::disk('public');
$privateRoot = uploadsOf($APP);
$publicRoot = rtrim(str_replace('\\', '/', (string) config('filesystems.disks.public.root')), '/');

// ---------------------------------------------------------------------------------------------- put / put-once
if ($mode === 'put' || $mode === 'put-once') {
    $label = preg_replace('/[^a-z0-9-]/i', '-', $argv[2] ?? 'upload');
    if ($mode === 'put-once') {
        foreach ($state['entries'] as $existing) {
            if ($existing['label'] === $label) out(['mode' => 'put-once', 'ok' => true, 'already_written' => true, 'entry' => $existing, 'state_file' => $STATE]);
        }
    }
    $tag = 'qa-race-'.$label.'-'.bin2hex(random_bytes(4));
    $privRel = QA_DIR.'/'.$tag.'.bin';
    $pubRel = QA_DIR.'/'.$tag.'.txt';
    // random bytes and a random size: edge and parser rules are content-dependent, so a fixed fixture proves less
    $privBytes = random_bytes(random_int(40000, 90000));
    $pubBytes = "QA deploy-race public file\nlabel=$label\ncreated=".gmdate('c')."\nnonce=".bin2hex(random_bytes(16))."\n";

    $privOk = $disk->put($privRel, $privBytes);
    $pubOk = $publicDisk->put($pubRel, $pubBytes);
    clearstatcache();
    $entry = [
        'label' => $label,
        'created_at' => gmdate('c'),
        'live_release_when_written' => currentRelease($RELEASES),
        'application_dir_inode_when_written' => @fileinode($APP),
        'private' => [
            'rel' => $privRel, 'written' => $privOk, 'sha256' => hash('sha256', $privBytes), 'size' => strlen($privBytes),
            'mtime' => @filemtime($privateRoot.'/'.$privRel), 'mode' => @fileperms($privateRoot.'/'.$privRel) & 0777,
        ],
        'public' => [
            'rel' => $pubRel, 'written' => $pubOk, 'sha256' => hash('sha256', $pubBytes), 'size' => strlen($pubBytes),
            'url' => $publicDisk->url($pubRel), 'mtime' => @filemtime($publicRoot.'/'.$pubRel),
        ],
    ];
    $state['entries'][] = $entry;
    saveState($STATE_DIR, $STATE, $state);
    out(['mode' => 'put', 'ok' => $privOk && $pubOk, 'entry' => $entry, 'state_file' => $STATE]);
}

// ---------------------------------------------------------------------------------------------- writer
if ($mode === 'writer') {
    $seconds = max(1, min(240, (int) ($argv[2] ?? 150)));
    $intervalMs = max(20, (int) ($argv[3] ?? 200));
    $maxFiles = max(1, min(1500, (int) ($argv[4] ?? 1000)));
    $log = $STATE_DIR.'/writer.log';
    $stop = $STATE_DIR.'/writer.stop';
    if (!is_dir($STATE_DIR)) mkdir($STATE_DIR, 0755, true);
    @unlink($stop);
    $handle = fopen($log, 'ab');
    $started = microtime(true);
    $deadline = $started + $seconds;
    $written = 0;
    $failed = 0;
    $firstInode = $lastInode = null;
    while (microtime(true) < $deadline && $written + $failed < $maxFiles && !is_file($stop)) {
        clearstatcache(true, $APP);
        $rel = QA_DIR.'/stress/'.sprintf('%05d', $written + $failed + 1).'-'.bin2hex(random_bytes(3)).'.bin';
        $bytes = random_bytes(random_int(300, 40000));
        $inode = @fileinode($APP);
        if ($disk->put($rel, $bytes)) { // the app's own disk; a failed write is a failed request, not an upload
            fwrite($handle, $rel.' '.hash('sha256', $bytes).' '.$inode."\n");
            fflush($handle);
            $written++;
            $firstInode ??= $inode;
            $lastInode = $inode;
        } else {
            $failed++;
        }
        usleep($intervalMs * 1000);
    }
    fclose($handle);
    out(['mode' => 'writer', 'ok' => true, 'written' => $written, 'failed_writes' => $failed, 'seconds' => round(microtime(true) - $started, 1), 'first_inode' => $firstInode, 'last_inode' => $lastInode, 'log' => $log]);
}

// ---------------------------------------------------------------------------------------------- drill-setup / drill-verify
if ($mode === 'drill-setup' || $mode === 'drill-verify') {
    $retiredOurs = thisDeploysRetiredTrees($RELEASES)[0] ?? null;
    if ($retiredOurs === null) out(['mode' => $mode, 'ok' => false, 'error' => 'this deploy has no retired tree on record'], 1);
    $retiredName = basename($retiredOurs);
    $retiredUploads = uploadsOf($retiredOurs);
    $dir = QA_DIR.'/drill';

    if ($mode === 'drill-setup') {
        if (isset($state['drill'])) out(['mode' => 'drill-setup', 'ok' => true, 'already_set_up' => true, 'drill' => $state['drill']]);
        $strandedBytes = random_bytes(random_int(5000, 20000));
        $retiredVersion = random_bytes(random_int(5000, 20000));
        $liveVersion = random_bytes(random_int(21000, 30000)); // a different size AND different bytes
        if (!is_dir($retiredUploads.'/'.$dir)) mkdir($retiredUploads.'/'.$dir, 0700, true);
        file_put_contents($retiredUploads.'/'.$dir.'/stranded.bin', $strandedBytes);
        file_put_contents($retiredUploads.'/'.$dir.'/collision.bin', $retiredVersion);
        $disk->put($dir.'/collision.bin', $liveVersion);
        $state['drill'] = [
            'retired_tree' => $retiredName, 'created_at' => gmdate('c'),
            'stranded' => ['rel' => $dir.'/stranded.bin', 'sha256' => hash('sha256', $strandedBytes), 'size' => strlen($strandedBytes)],
            'collision' => ['rel' => $dir.'/collision.bin', 'retired_sha256' => hash('sha256', $retiredVersion), 'live_sha256' => hash('sha256', $liveVersion)],
        ];
        saveState($STATE_DIR, $STATE, $state);
        out(['mode' => 'drill-setup', 'ok' => true, 'drill' => $state['drill'], 'run_next' => "reconcile $retiredName  /  reconcile $retiredName apply  /  reconcile $retiredName apply keep-both"]);
    }

    $d = $state['drill'] ?? null;
    if ($d === null) out(['mode' => 'drill-verify', 'ok' => false, 'error' => 'run drill-setup first'], 1);
    $conflictDir = $RELEASES.'/_upload-conflicts/reconcile-'.$d['retired_tree'];
    $preserved = $conflictDir.'/files/'.$d['collision']['rel'].'.source-'.substr($d['collision']['retired_sha256'], 0, 8);
    $ledger = is_file($conflictDir.'/conflicts.json') ? json_decode((string) file_get_contents($conflictDir.'/conflicts.json'), true) : null;
    $ledgerRels = is_array($ledger) ? array_column($ledger['conflicts'] ?? [], 'rel') : [];
    $checks = [
        'stranded_file_recovered_into_live_with_same_bytes' => sha($privateRoot.'/'.$d['stranded']['rel']) === $d['stranded']['sha256'],
        'collision_live_version_kept_at_its_path' => sha($privateRoot.'/'.$d['collision']['rel']) === $d['collision']['live_sha256'],
        'collision_other_version_preserved_byte_for_byte' => sha($preserved) === $d['collision']['retired_sha256'],
        'ledger_lists_the_collision' => in_array($d['collision']['rel'], $ledgerRels, true),
        'retired_tree_untouched_stranded' => sha($retiredUploads.'/'.$d['stranded']['rel']) === $d['stranded']['sha256'],
        'retired_tree_untouched_collision' => sha($retiredUploads.'/'.$d['collision']['rel']) === $d['collision']['retired_sha256'],
    ];
    out(['mode' => 'drill-verify', 'ok' => !in_array(false, $checks, true), 'checks' => $checks, 'preserved_as' => $preserved, 'ledger' => $ledger, 'drill' => $d], in_array(false, $checks, true) ? 1 : 0);
}

// ---------------------------------------------------------------------------------------------- register-application
if ($mode === 'register-application') {
    $known = array_column($state['applications'], 'id');
    $rows = JobApplication::query()->where('applicant_name', 'like', QA_NAME.' %')->orderBy('id')->get();
    $new = [];
    foreach ($rows as $row) {
        if (in_array($row->id, $known, true)) continue;
        $photo = $row->photo_path ? $privateRoot.'/'.$row->photo_path : null;
        $cv = $row->cv_path ? $privateRoot.'/'.$row->cv_path : null;
        $app = [
            'id' => $row->id, 'application_no' => $row->application_no, 'name' => $row->applicant_name, 'email' => $row->applicant_email,
            'photo_path' => $row->photo_path, 'photo_sha256' => sha($photo), 'photo_size' => $photo && is_file($photo) ? filesize($photo) : null,
            'cv_path' => $row->cv_path, 'cv_sha256' => sha($cv), 'cv_size' => $cv && is_file($cv) ? filesize($cv) : null,
            'registered_at' => gmdate('c'), 'application_dir_inode_when_registered' => @fileinode($APP), 'live_release_when_registered' => currentRelease($RELEASES),
        ];
        $state['applications'][] = $app;
        $new[] = $app;
    }
    saveState($STATE_DIR, $STATE, $state);
    out(['mode' => 'register-application', 'ok' => true, 'registered' => $new, 'total_registered' => count($state['applications'])]);
}

// ---------------------------------------------------------------------------------------------- verify / inspect
if ($mode === 'verify' || $mode === 'inspect') {
    $retired = retiredTrees($RELEASES);
    $report = [
        'mode' => $mode,
        'live_release' => currentRelease($RELEASES),
        'application_dir_inode_now' => @fileinode($APP),
        'retired_trees' => array_map('basename', $retired),
        'items' => [],
        'applications' => [],
    ];
    $failures = [];
    $skipHttp = (bool) getenv('QA_SKIP_HTTP');

    foreach ($state['entries'] as $entry) {
        $p = $entry['private'];
        $abs = $privateRoot.'/'.$p['rel'];
        clearstatcache(true, $abs);
        $liveHash = sha($abs);
        $viaApp = $disk->exists($p['rel']) ? hash('sha256', (string) $disk->get($p['rel'])) : null;
        $item = [
            'label' => $entry['label'],
            'written_while_release' => $entry['live_release_when_written'],
            'private' => [
                'rel' => $p['rel'], 'in_live_tree' => $liveHash !== null, 'sha256_matches' => $liveHash === $p['sha256'],
                'app_disk_read_matches' => $viaApp === $p['sha256'], 'size_matches' => is_file($abs) && filesize($abs) === $p['size'],
                'mtime_preserved' => is_file($abs) && filemtime($abs) === $p['mtime'], 'mode_preserved' => is_file($abs) && (fileperms($abs) & 0777) === $p['mode'],
                'recorded' => ['sha256' => $p['sha256'], 'size' => $p['size'], 'mtime' => $p['mtime'], 'mode' => decoct($p['mode'])],
                'now' => ['mtime' => is_file($abs) ? filemtime($abs) : null, 'mode' => is_file($abs) ? decoct(fileperms($abs) & 0777) : null],
            ],
        ];
        foreach (['in_live_tree', 'sha256_matches', 'app_disk_read_matches'] as $check) {
            if (!$item['private'][$check]) $failures[] = "{$entry['label']}: private {$check} is false";
        }
        foreach (['mtime_preserved', 'mode_preserved'] as $soft) {
            if (!$item['private'][$soft]) $failures[] = "{$entry['label']}: private {$soft} is false";
        }

        $pub = $entry['public'];
        $pubAbs = $publicRoot.'/'.$pub['rel'];
        $http = $skipHttp ? ['skipped' => true] : httpGet($pub['url']);
        $item['public'] = [
            'rel' => $pub['rel'], 'url' => $pub['url'], 'in_docroot' => is_file($pubAbs), 'sha256_matches' => sha($pubAbs) === $pub['sha256'],
            'http' => $http, 'http_200_with_same_bytes' => $skipHttp ? null : ($http['code'] === 200 && $http['sha256'] === $pub['sha256']),
        ];
        foreach (['in_docroot', 'sha256_matches'] as $check) {
            if (!$item['public'][$check]) $failures[] = "{$entry['label']}: public {$check} is false";
        }
        if (!$skipHttp && !$item['public']['http_200_with_same_bytes']) $failures[] = "{$entry['label']}: public file did not answer HTTP 200 with the same bytes (got {$http['code']})";
        $report['items'][] = $item;
    }

    foreach ($state['applications'] as $registered) {
        $row = JobApplication::query()->find($registered['id']);
        $photo = $row?->photo_path ? $privateRoot.'/'.$row->photo_path : null;
        $cv = $row?->cv_path ? $privateRoot.'/'.$row->cv_path : null;
        $one = [
            'id' => $registered['id'], 'application_no' => $registered['application_no'], 'row_exists' => $row !== null,
            'photo_file_exists_via_model' => $row?->photoFileExists() ?? false,
            'photo_sha256_matches' => $registered['photo_sha256'] !== null && sha($photo) === $registered['photo_sha256'],
            'cv_expected' => $registered['cv_path'] !== null,
            'cv_file_exists_via_model' => $row?->cvFileExists() ?? false,
            'cv_sha256_matches' => $registered['cv_path'] === null || sha($cv) === $registered['cv_sha256'],
            'registered_while_release' => $registered['live_release_when_registered'],
        ];
        foreach (['row_exists', 'photo_file_exists_via_model', 'photo_sha256_matches', 'cv_sha256_matches'] as $check) {
            if (!$one[$check]) $failures[] = "application {$registered['application_no']}: {$check} is false";
        }
        if ($one['cv_expected'] && !$one['cv_file_exists_via_model']) $failures[] = "application {$registered['application_no']}: the CV file is gone";
        $report['applications'][] = $one;
    }

    $writerLog = $STATE_DIR.'/writer.log';
    if (is_file($writerLog)) {
        $lines = file($writerLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $byInode = [];
        $missing = [];
        $wrong = [];
        foreach ($lines as $line) {
            [$rel, $hash, $inode] = array_pad(explode(' ', $line), 3, null);
            $byInode[$inode] = ($byInode[$inode] ?? 0) + 1;
            $abs = $privateRoot.'/'.$rel;
            clearstatcache(true, $abs);
            if (!is_file($abs)) $missing[] = $rel;
            elseif (hash_file('sha256', $abs) !== $hash) $wrong[] = $rel;
        }
        $report['writer'] = [
            'files_logged' => count($lines), 'in_live_tree_with_same_bytes' => count($lines) - count($missing) - count($wrong), 'missing' => array_slice($missing, 0, 20), 'different_bytes' => array_slice($wrong, 0, 20),
            'written_into_application_dir_by_inode' => $byInode, 'application_dir_inode_now' => @fileinode($APP),
        ];
        foreach ($missing as $rel) $failures[] = "writer file lost: $rel";
        foreach ($wrong as $rel) $failures[] = "writer file changed: $rel";
    }

    $ours = thisDeploysRetiredTrees($RELEASES);
    $report['this_deploys_retired_trees'] = array_map('basename', $ours);
    $report['stranded'] = stranded($APP, $ours);
    foreach ($report['stranded'] as $s) $failures[] = "stranded in {$s['tree']}: {$s['path']} ({$s['in_live']})";

    // Older retired trees predate the deploy under test. Not a failure — but say what is in them, and whether any row needs it.
    $warnings = [];
    $older = [];
    foreach (array_diff($retired, $ours) as $tree) {
        $missing = stranded($APP, [$tree]);
        $referenced = [];
        $unchecked = 0;
        foreach ($missing as $m) {
            $ref = referencedByRow($m['path']);
            if ($ref === true) $referenced[] = $m['path'];
            if ($ref === null) $unchecked++;
        }
        $older[basename($tree)] = [
            'files_absent_from_live' => count($missing),
            'referenced_by_a_database_row' => count($referenced),
            'referenced_paths' => array_slice($referenced, 0, 10),
            'folder_not_checked_against_the_database' => $unchecked,
            'distinct_hashes' => count(array_unique(array_column($missing, 'sha256'))),
            'folders' => array_values(array_unique(array_map(fn ($m) => explode('/', $m['path'])[0].'/'.(explode('/', $m['path'])[1] ?? ''), $missing))),
        ];
        if ($referenced) $warnings[] = basename($tree).': '.count($referenced).' file(s) absent from live ARE referenced by a database row — run reconcile on it';
    }
    $report['older_retired_trees'] = $older;
    $report['warnings'] = $warnings;
    $report['failures'] = $failures;
    $report['ok'] = !$failures;
    out($report, $mode === 'verify' && $failures ? 1 : 0);
}

// ---------------------------------------------------------------------------------------------- cleanup
if ($mode === 'cleanup') {
    $removed = ['files' => [], 'rows' => [], 'dirs' => []];
    $trees = array_merge([$APP], retiredTrees($RELEASES));

    $paths = [];
    foreach ($state['entries'] as $entry) {
        foreach ($trees as $tree) $paths[] = uploadsOf($tree).'/'.$entry['private']['rel'];
        $paths[] = $publicRoot.'/'.$entry['public']['rel'];
    }
    foreach ($state['applications'] as $registered) {
        foreach (['photo_path', 'cv_path'] as $column) {
            if ($registered[$column]) foreach ($trees as $tree) $paths[] = uploadsOf($tree).'/'.$registered[$column];
        }
    }
    foreach (array_unique($paths) as $path) {
        if (is_file($path) && !is_link($path) && @unlink($path)) $removed['files'][] = str_replace($RELEASES.'/', '', str_replace($APP.'/', 'LIVE/', $path));
    }
    // everything else in our own marker directory (the writer's files, a preserved collision copy, a stray), wherever it is
    foreach ($trees as $tree) {
        $n = removeMarkerTree(uploadsOf($tree).'/'.QA_DIR);
        if ($n) $removed['dirs'][] = basename($tree).'/…/'.QA_DIR.": $n file(s)";
    }
    $n = removeMarkerTree($publicRoot.'/'.QA_DIR);
    if ($n) $removed['dirs'][] = 'public/'.QA_DIR.": $n file(s)";
    foreach (glob($RELEASES.'/_upload-conflicts/*/files/'.QA_DIR) ?: [] as $dir) {
        $n = removeMarkerTree($dir);
        if ($n) $removed['dirs'][] = 'conflicts/…/'.QA_DIR.": $n file(s)";
    }

    // _upload-conflicts directories whose every ledger entry is one of our own marker files (the drill's), ledger included
    foreach (glob($RELEASES.'/_upload-conflicts/*', GLOB_ONLYDIR) ?: [] as $conflictDir) {
        $ledger = is_file($conflictDir.'/conflicts.json') ? json_decode((string) file_get_contents($conflictDir.'/conflicts.json'), true) : null;
        $rels = is_array($ledger) ? array_column($ledger['conflicts'] ?? [], 'rel') : [];
        if ($rels && count(array_filter($rels, fn ($r) => str_starts_with((string) $r, QA_DIR.'/'))) === count($rels)) {
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($conflictDir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
            $n = 0;
            foreach ($it as $item) {
                if ($item->isDir() && !$item->isLink()) @rmdir($item->getPathname());
                elseif (@unlink($item->getPathname())) $n++;
            }
            @rmdir($conflictDir);
            $removed['dirs'][] = 'conflicts/'.basename($conflictDir).": $n file(s) (ledger + preserved copy)";
        }
    }

    @rmdir($RELEASES.'/_upload-conflicts'); // only succeeds when nothing else lives there

    $ids = array_column($state['applications'], 'id');
    if ($ids) {
        $deleted = JobApplication::query()->whereIn('id', $ids)->where('applicant_name', 'like', QA_NAME.' %')->delete();
        $removed['rows'][] = "job_applications: $deleted";
    }

    // prove it: nothing of ours is left anywhere
    $left = [];
    foreach ($trees as $tree) {
        if (is_dir(uploadsOf($tree).'/'.QA_DIR)) $left[] = uploadsOf($tree).'/'.QA_DIR.' (directory still exists)';
    }
    if (is_dir($publicRoot.'/'.QA_DIR)) $left[] = $publicRoot.'/'.QA_DIR.' (directory still exists)';
    foreach (glob($RELEASES.'/_upload-conflicts/*/files/'.QA_DIR) ?: [] as $dir) $left[] = $dir.' (directory still exists)';
    foreach ($state['applications'] as $registered) {
        foreach (['photo_path', 'cv_path'] as $column) {
            if ($registered[$column]) foreach ($trees as $tree) if (is_file(uploadsOf($tree).'/'.$registered[$column])) $left[] = uploadsOf($tree).'/'.$registered[$column];
        }
    }
    $rowsLeft = $ids ? JobApplication::query()->whereIn('id', $ids)->count() : 0;
    $qaRowsAnywhere = JobApplication::query()->where('applicant_name', 'like', QA_NAME.' %')->count();

    $clean = !$left && $rowsLeft === 0;
    if ($clean && is_file($STATE)) {
        foreach (['writer.log', 'writer.stop'] as $extra) @unlink($STATE_DIR.'/'.$extra);
        @unlink($STATE);
        @rmdir($STATE_DIR);
    }
    // A one-shot copy uploaded into the web docroot removes itself once everything else is gone (a docroot script
    // is reachable over HTTP; this one answers 404 to anything but the CLI, but there is no reason to leave it).
    $selfRemoved = false;
    if ($clean && str_contains(str_replace('\\', '/', __FILE__), '/public_html/')) $selfRemoved = @unlink(__FILE__);
    out(['mode' => 'cleanup', 'ok' => $clean, 'script_removed_itself' => $selfRemoved, 'removed' => $removed, 'files_left' => $left, 'registered_rows_left' => $rowsLeft, 'other_qa_timing_rows_in_db' => $qaRowsAnywhere, 'state_file_removed' => !is_file($STATE)], $clean ? 0 : 1);
}

fwrite(STDERR, "usage: uploads-race-qa.php put <label> | put-once <label> | writer [seconds] [interval-ms] [max-files] | drill-setup | drill-verify | register-application | verify | inspect | cleanup\n");
exit(2);
