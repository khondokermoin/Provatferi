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
 *   register-application   records the volunteer applications a real browser just submitted (name starts with
 *                          "QA TIMING TEST"): their database row, photo and CV paths and the files' SHA-256.
 *   verify                 after `switch`: every recorded private file is in the LIVE tree with the recorded hash AND
 *                          readable through the app's own disk; every public file is in the docroot and answers HTTP
 *                          200 with the same bytes; mtime/mode survived; the registered applications still have their
 *                          files; and no retired tree (_previous-*, _rolled-back-*, _stray-*) holds an upload the
 *                          live tree lacks ("stranded"). Exit code 1 unless all of it holds.
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

// ---------------------------------------------------------------------------------------------- put
if ($mode === 'put') {
    $label = preg_replace('/[^a-z0-9-]/i', '-', $argv[2] ?? 'upload');
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

    $report['stranded'] = stranded($APP, $retired);
    foreach ($report['stranded'] as $s) $failures[] = "stranded in {$s['tree']}: {$s['path']} ({$s['in_live']})";
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
    // whatever else sits in our own marker directory (a preserved collision copy, a stray), in every place it can be
    foreach ($trees as $tree) {
        foreach (glob(uploadsOf($tree).'/'.QA_DIR.'/*') ?: [] as $leftover) $paths[] = $leftover;
    }
    foreach (glob($publicRoot.'/'.QA_DIR.'/*') ?: [] as $leftover) $paths[] = $leftover;
    foreach (glob($RELEASES.'/_upload-conflicts/*/files/'.QA_DIR.'/*') ?: [] as $leftover) $paths[] = $leftover;

    foreach (array_unique($paths) as $path) {
        if (is_file($path) && !is_link($path) && @unlink($path)) $removed['files'][] = str_replace($RELEASES.'/', '', str_replace($APP.'/', 'LIVE/', $path));
    }
    foreach ($trees as $tree) {
        if (is_dir(uploadsOf($tree).'/'.QA_DIR) && @rmdir(uploadsOf($tree).'/'.QA_DIR)) $removed['dirs'][] = basename($tree).'/…/'.QA_DIR;
    }
    if (is_dir($publicRoot.'/'.QA_DIR) && @rmdir($publicRoot.'/'.QA_DIR)) $removed['dirs'][] = 'public/'.QA_DIR;
    foreach (glob($RELEASES.'/_upload-conflicts/*/files/'.QA_DIR) ?: [] as $dir) @rmdir($dir);

    $ids = array_column($state['applications'], 'id');
    if ($ids) {
        $deleted = JobApplication::query()->whereIn('id', $ids)->where('applicant_name', 'like', QA_NAME.' %')->delete();
        $removed['rows'][] = "job_applications: $deleted";
    }

    // prove it: nothing of ours is left anywhere
    $left = [];
    foreach ($trees as $tree) {
        foreach (glob(uploadsOf($tree).'/'.QA_DIR.'/*') ?: [] as $f) $left[] = $f;
    }
    foreach (glob($publicRoot.'/'.QA_DIR.'/*') ?: [] as $f) $left[] = $f;
    foreach ($state['applications'] as $registered) {
        foreach (['photo_path', 'cv_path'] as $column) {
            if ($registered[$column]) foreach ($trees as $tree) if (is_file(uploadsOf($tree).'/'.$registered[$column])) $left[] = uploadsOf($tree).'/'.$registered[$column];
        }
    }
    $rowsLeft = $ids ? JobApplication::query()->whereIn('id', $ids)->count() : 0;
    $qaRowsAnywhere = JobApplication::query()->where('applicant_name', 'like', QA_NAME.' %')->count();

    $clean = !$left && $rowsLeft === 0;
    if ($clean && is_file($STATE)) {
        @unlink($STATE);
        @rmdir($STATE_DIR);
    }
    out(['mode' => 'cleanup', 'ok' => $clean, 'removed' => $removed, 'files_left' => $left, 'registered_rows_left' => $rowsLeft, 'other_qa_timing_rows_in_db' => $qaRowsAnywhere, 'state_file_removed' => !is_file($STATE)], $clean ? 0 : 1);
}

fwrite(STDERR, "usage: uploads-race-qa.php put <label> | register-application | verify | inspect | cleanup\n");
exit(2);
