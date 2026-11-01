<?php
/**
 * RUNS THE BACKUP ON PRODUCTION FROM A PINNED COMMIT, WITHOUT MODIFYING THE LIVE APPLICATION.
 * (docs/BACKUP_AND_DISASTER_RECOVERY.md). CLI only: 404 otherwise.
 *
 * WHY THIS EXISTS. The backup code belongs in the application and is deployed by the normal release pipeline. That
 * pipeline needs the built artifact uploaded to the server, and the upload channel (hPanel's File Manager
 * credentials) is not available in every session — while the hosting API offers no file-upload operation at all. The
 * honest options were then: hand-copy files into the live Laravel tree (the exact mistake the pipeline exists to
 * prevent — it is how a broken release shipped on 2026-09-09), or run the same committed code from OUTSIDE the live
 * tree. This is the second.
 *
 * It boots the LIVE application (so the database credentials, the paths and the mail identity are the real ones),
 * loads the backup classes and `config/backup.php` from a checkout of one exact commit, and runs them. It writes
 * nothing into the live application directory, changes no application file, and holds the same lock as the deployed
 * command would, so the two can never run over each other.
 *
 * This is an INTERIM arrangement. Once the release is deployed, point the cron at `php artisan backup:run` and delete
 * the checkout — see "Owner actions" in the documentation.
 *
 *   php backup-standalone.php run       take a backup
 *   php backup-standalone.php status    report age, integrity and the next expected run
 *   php backup-standalone.php where     show which paths it resolved, and stop (a dry check before the first run)
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
ini_set('display_errors', 'stderr');

const LIVE_APP = '/home/u951246149/domains/provatferi.org/laravel-admin';
const SOURCE_ROOT = '/home/u951246149/_bk_src';

function out(array $data, int $exit = 0): never
{
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE), "\n";
    exit($exit);
}

// The GitHub tarball extracts to `Provatferi-<sha>/`; take whichever checkout is there.
$candidates = array_values(array_filter((array) glob(SOURCE_ROOT.'/*/admin-erp/config/backup.php'), 'is_file'));
if ($candidates === []) {
    out(['ok' => false, 'error' => 'no checkout found under '.SOURCE_ROOT.' (fetch the commit tarball first)'], 2);
}
$src = dirname($candidates[0], 3);          // …/Provatferi-<sha>
$erp = $src.'/admin-erp';

$mode = $argv[1] ?? 'status';

if ($mode === 'where') {
    out([
        'mode' => 'where',
        'live_app' => LIVE_APP,
        'source_checkout' => $src,
        'backup_classes' => count((array) glob($erp.'/app/Services/Backup/*.php')),
        'live_app_present' => is_file(LIVE_APP.'/artisan'),
    ]);
}

if (! in_array($mode, ['run', 'status', 'consistency'], true)) {
    out(['error' => 'modes: run | status | consistency | where'], 2);
}

// ------------------------------------------------------------------------------------- boot the LIVE application
define('LARAVEL_START', microtime(true));
define('LARAVEL_PUBLIC_PATH_OVERRIDE', LIVE_APP.'/public');
require LIVE_APP.'/vendor/autoload.php';
$laravel = require LIVE_APP.'/bootstrap/app.php';
$laravel->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

// ------------------------------------------------- load the backup code from the checkout, before anything uses it
// Required explicitly because these classes are outside the live application's autoload map. Order matters only in
// that every class must exist before BackupFactory is touched.
foreach ((array) glob($erp.'/app/Services/Backup/*.php') as $file) {
    require_once $file;
}
require_once $erp.'/app/Notifications/BackupFailedNotification.php';

// The configuration file resolves its paths from the LIVE application's base_path()/storage_path()/public_path(),
// which is exactly what is wanted: this backs up production, not the checkout.
config(['backup' => require $erp.'/config/backup.php']);

use App\Services\Backup\BackupFactory;

if ($mode === 'status') {
    $store = BackupFactory::store();
    $ids = $store->setIds();
    $state = $store->state();
    $lastSuccess = is_array($state['last_success'] ?? null) ? $state['last_success'] : null;
    $finished = is_string($lastSuccess['finished_utc'] ?? null) ? $lastSuccess['finished_utc'] : null;

    $verification = [];
    foreach (array_slice($ids, 0, 3) as $id) {
        $result = $store->verify($id, BackupFactory::dumper());
        $verification[$id] = ['ok' => $result['ok'], 'artifacts' => $result['checked'], 'problems' => $result['problems']];
    }

    out([
        'mode' => 'status',
        'checked_at_utc' => gmdate('c'),
        'source_checkout' => basename($src),
        'root' => $store->root(),
        'sets' => count($ids),
        'newest_set' => $ids[0] ?? null,
        'last_success_utc' => $finished,
        'last_success_age_hours' => $finished !== null ? (int) floor((time() - strtotime($finished)) / 3600) : null,
        'verification' => $verification,
        'retention_would_keep' => $store->keepSet($ids),
        'disk_bytes' => $store->directoryBytes($store->root()),
        'config_bundle' => BackupFactory::vault()->configured() ? 'encrypted' : 'NOT INCLUDED: BACKUP_ENCRYPTION_KEY is not set',
        'last_run' => $state['last_run'] ?? null,
        'last_failure' => $state['last_failure'] ?? null,
    ]);
}

if ($mode === 'consistency') {
    // Every database reference to a stored file, checked against the live trees AND the newest set. Private
    // filenames never appear in the output — a problem is reported as `table.column#id` with a path digest.
    $store = BackupFactory::store();
    $newest = $store->setIds()[0] ?? null;
    $report = BackupFactory::consistency()->check(
        BackupFactory::fileSets(),
        $newest !== null ? $store->setPath($newest) : null
    );

    out([
        'mode' => 'consistency',
        'checked_at_utc' => gmdate('c'),
        'against_set' => $newest,
        'report' => $report,
    ], ($report['ok'] ?? false) === true ? 0 : 1);
}

$manifest = BackupFactory::runner()->run();
$manifest['source_checkout'] = basename($src);
out($manifest, ($manifest['ok'] ?? false) === true ? 0 : 1);
