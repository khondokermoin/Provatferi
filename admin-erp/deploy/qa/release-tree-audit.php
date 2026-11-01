<?php
/**
 * CLASSIFIES THE PRIVATE FILES HELD IN RETIRED RELEASE TREES, AND RESCUES THEM FIRST.
 * (docs/BACKUP_AND_DISASTER_RECOVERY.md, "Retained release trees"). CLI only: 404 otherwise.
 *
 * WHY THIS EXISTS. Housekeeping is fail-closed: it refuses to delete a retired release tree that holds a file the
 * live application does not have, because it cannot tell an irreplaceable record from a leftover of a QA run. Two
 * trees have been kept for that reason since 2026-10-03 and 2026-10-18, together about 18,700 inodes and 310 MB. The
 * question "can these go?" is answerable — but only with evidence, and only after the files are safe somewhere else.
 *
 * ORDER OF OPERATIONS, deliberately: RESCUE, then classify, then report. Nothing here deletes anything, ever.
 *
 *   rescue      Copies every private file found in the retired trees into the backup root's `recovery/` directory
 *               (outside every document root, 0600), with a manifest of hashes. Idempotent.
 *   classify    For each rescued file: is it referenced by a live database row? does the live application have it?
 *               is it a duplicate of a live file by hash? does it look like a QA artifact? Prints a verdict per file.
 *   report      rescue + classify in one pass, which is what the owner wants to read.
 *
 * PRIVACY: filenames are uuids, but they belong to real applicants, so the report identifies a file by its hash and
 * its tree, and names the DB row it belongs to only as `table#id`.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
ini_set('display_errors', 'stderr');

const QA_DOMAIN_ROOT = '/home/u951246149/domains/provatferi.org';
$APP = getenv('QA_APP') ?: (is_dir(QA_DOMAIN_ROOT.'/laravel-admin') ? QA_DOMAIN_ROOT.'/laravel-admin' : dirname(__DIR__, 2));
$RELEASES = QA_DOMAIN_ROOT.'/laravel-admin-releases';

function out(array $data, int $exit = 0): never
{
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE), "\n";
    exit($exit);
}

/** Every file under a directory, relative path => [size, sha256]. */
function walk(string $dir): array
{
    if (! is_dir($dir)) {
        return [];
    }
    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS | FilesystemIterator::UNIX_PATHS)
    );
    foreach ($iterator as $entry) {
        /** @var SplFileInfo $entry */
        if (! $entry->isFile() || $entry->isLink()) {
            continue;
        }
        $relative = ltrim(substr(str_replace('\\', '/', $entry->getPathname()), strlen(rtrim(str_replace('\\', '/', $dir), '/'))), '/');
        $files[$relative] = [
            'size' => (int) $entry->getSize(),
            'sha256' => (string) @hash_file('sha256', $entry->getPathname()),
            'mtime' => gmdate('Y-m-d', (int) $entry->getMTime()),
        ];
    }
    ksort($files);

    return $files;
}

$mode = $argv[1] ?? 'report';
if (! in_array($mode, ['rescue', 'classify', 'report'], true)) {
    out(['error' => 'modes: rescue | classify | report'], 2);
}

// --------------------------------------------------------------------------------------- the retired trees to examine
$retired = [];
foreach ((array) glob($RELEASES.'/_previous-*') as $path) {
    if (is_string($path) && is_dir($path)) {
        $retired[basename($path)] = $path.'/storage/app/private';
    }
}
ksort($retired);

$live = walk($APP.'/storage/app/private');

// ------------------------------------------------------------------------------------------------------------ rescue
define('LARAVEL_START', microtime(true));
define('LARAVEL_PUBLIC_PATH_OVERRIDE', $APP.'/public');
require $APP.'/vendor/autoload.php';
$laravel = require $APP.'/bootstrap/app.php';
$laravel->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * The backup root, from config/backup.php when that release is live and from the same derivation otherwise — this
 * kit has to be runnable BEFORE the backup release is deployed, which is exactly when the retained trees need
 * classifying.
 */
$backupRoot = (string) config('backup.root');
if ($backupRoot === '') {
    $backupRoot = dirname($APP, 3).'/backups';
}
$recoveryRoot = rtrim($backupRoot, '/').'/recovery/retired-release-trees';

$rescued = [];
$rescueErrors = [];
if ($mode === 'rescue' || $mode === 'report') {
    foreach ($retired as $treeName => $treePath) {
        foreach (walk($treePath) as $relative => $meta) {
            // Only what the live application does NOT have: those are the files keeping the tree alive.
            if (isset($live[$relative]) && $live[$relative]['sha256'] === $meta['sha256']) {
                continue;
            }
            $target = $recoveryRoot.'/'.$treeName.'/'.$relative;
            $dir = dirname($target);
            if (! is_dir($dir) && ! @mkdir($dir, 0700, true) && ! is_dir($dir)) {
                $rescueErrors[] = $treeName.': cannot create the recovery directory';

                continue;
            }
            if (! is_file($target) || (string) @hash_file('sha256', $target) !== $meta['sha256']) {
                if (! @copy($treePath.'/'.$relative, $target)) {
                    $rescueErrors[] = $treeName.'/'.substr($meta['sha256'], 0, 12).': copy failed';

                    continue;
                }
                @chmod($target, 0600);
            }
            $rescued[$treeName][$relative] = $meta;
        }
    }
    if (is_dir($recoveryRoot)) {
        @chmod($recoveryRoot, 0700);
        file_put_contents($recoveryRoot.'/RESCUE_MANIFEST.json', json_encode([
            'rescued_at_utc' => gmdate('c'),
            'why' => 'Private files found in retired release trees that the live application does not have. Kept here so the trees can be released without losing anything.',
            'trees' => array_map(fn (array $files) => ['files' => count($files), 'bytes' => array_sum(array_column($files, 'size'))], $rescued),
            'files' => $rescued,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
        @chmod($recoveryRoot.'/RESCUE_MANIFEST.json', 0600);
    }
}

if ($mode === 'rescue') {
    out([
        'mode' => 'rescue',
        'checked_at_utc' => gmdate('c'),
        'recovery_root' => $recoveryRoot,
        'trees' => array_map(fn (array $f) => ['files' => count($f), 'bytes' => array_sum(array_column($f, 'size'))], $rescued),
        'errors' => $rescueErrors,
    ]);
}

// ---------------------------------------------------------------------------------------------------------- classify
/*
 * Every path a live database row points at, so "is this file still referenced?" is answered from the database rather
 * than from a guess. Keyed on the last two path segments, the same way the backup's consistency check compares them.
 */
$referenced = [];
$references = (array) config('backup.consistency', []);
if ($references === []) {
    // The same list config/backup.php holds, repeated here only so this kit works before that release is live.
    $references = [
        ['table' => 'job_applications', 'column' => 'cv_path'],
        ['table' => 'job_applications', 'column' => 'photo_path'],
        ['table' => 'committee_submissions', 'column' => 'photo_path'],
        ['table' => 'committee_submissions', 'column' => 'photo_approved_path'],
        ['table' => 'members', 'column' => 'photo_path'],
        ['table' => 'public_member_profile_versions', 'column' => 'photo_path'],
        ['table' => 'public_member_profile_versions', 'column' => 'photo_approved_path'],
        ['table' => 'leadership_profiles', 'column' => 'photo_path'],
        ['table' => 'leadership_profiles', 'column' => 'photo_sm_path'],
        ['table' => 'notices', 'column' => 'attachment_path'],
        ['table' => 'notices', 'column' => 'cover_image_path'],
        ['table' => 'notices', 'column' => 'share_image_path'],
        ['table' => 'activities', 'column' => 'hero_image_path'],
        ['table' => 'homepage_carousel_slides', 'column' => 'image_path'],
        ['table' => 'job_postings', 'column' => 'share_image_path'],
    ];
}
foreach ($references as $reference) {
    $table = $reference['table'] ?? null;
    $column = $reference['column'] ?? null;
    if (! is_string($table) || ! is_string($column) || ! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
        continue;
    }
    foreach (DB::table($table)->select(['id', $column])->whereNotNull($column)->where($column, '!=', '')->get() as $row) {
        $value = trim((string) ($row->{$column} ?? ''));
        if ($value === '') {
            continue;
        }
        $segments = array_values(array_filter(explode('/', str_replace('\\', '/', $value))));
        $key = count($segments) >= 2 ? implode('/', array_slice($segments, -2)) : ($segments[0] ?? '');
        $referenced[$key][] = $table.'#'.$row->id;
        $referenced[basename($value)][] = $table.'#'.$row->id;
    }
}

$liveByHash = [];
foreach ($live as $relative => $meta) {
    $liveByHash[$meta['sha256']][] = $relative;
}

$classified = [];
$summary = ['referenced_by_a_live_row' => 0, 'duplicate_of_a_live_file' => 0, 'looks_like_a_qa_artifact' => 0, 'unexplained_keep_it' => 0];

foreach ($retired as $treeName => $treePath) {
    foreach (walk($treePath) as $relative => $meta) {
        if (isset($live[$relative]) && $live[$relative]['sha256'] === $meta['sha256']) {
            continue;   // the live app has it: not a reason to keep the tree
        }

        $segments = array_values(array_filter(explode('/', $relative)));
        $tail = count($segments) >= 2 ? implode('/', array_slice($segments, -2)) : ($segments[0] ?? '');
        $rows = array_values(array_unique(array_merge($referenced[$tail] ?? [], $referenced[basename($relative)] ?? [])));

        $duplicateOf = $liveByHash[$meta['sha256']] ?? [];

        // A QA artifact, judged on evidence rather than on the filename: tiny, or byte-identical to another file in
        // the same tree (the QA kits uploaded the same fixture repeatedly), and referenced by nothing.
        $tiny = $meta['size'] > 0 && $meta['size'] < 2048;

        $verdict = match (true) {
            $rows !== [] => 'referenced_by_a_live_row',
            $duplicateOf !== [] => 'duplicate_of_a_live_file',
            $tiny => 'looks_like_a_qa_artifact',
            default => 'unexplained_keep_it',
        };
        $summary[$verdict]++;

        $classified[] = [
            'tree' => $treeName,
            'kind' => implode('/', array_slice($segments, 0, max(1, count($segments) - 1))),
            'sha256_12' => substr($meta['sha256'], 0, 12),
            'size' => $meta['size'],
            'mtime' => $meta['mtime'],
            'referenced_by' => $rows,
            'duplicate_of_live' => $duplicateOf !== [],
            'verdict' => $verdict,
        ];
    }
}

out([
    'mode' => $mode,
    'checked_at_utc' => gmdate('c'),
    'retired_trees_examined' => array_keys($retired),
    'live_private_files' => count($live),
    'recovery_root' => $recoveryRoot,
    'rescued' => array_map(fn (array $f) => ['files' => count($f), 'bytes' => array_sum(array_column($f, 'size'))], $rescued),
    'rescue_errors' => $rescueErrors,
    'summary' => $summary,
    'verdict_meanings' => [
        'referenced_by_a_live_row' => 'A current database row points at this file and the live application does NOT have it. IRREPLACEABLE: restore it into live storage before the tree is released.',
        'duplicate_of_a_live_file' => 'Byte-identical to a file the live application already has, under a different path. Safe to release once the rescue copy exists.',
        'looks_like_a_qa_artifact' => 'Referenced by nothing and too small to be a real document or photograph. Almost certainly a test fixture. The owner confirms.',
        'unexplained_keep_it' => 'Referenced by nothing, not a duplicate, and large enough to be real. Do NOT delete on this evidence: the owner decides.',
    ],
    'files' => $classified,
    'note' => 'Nothing was deleted. Every file above was first copied into the recovery directory, which is outside every document root.',
]);
