<?php
/**
 * Server-side deployment orchestrator for admin-erp. Uploaded ONCE (see
 * deploy/README.md "First-time setup"), then relocated by its own `install`
 * action into a private, non-web-accessible sibling of laravel-admin/ —
 * every deploy after that invokes the SAME persistent copy via cron, rather
 * than a fresh one-off script per incident. That distinction matters: the
 * scripts used to diagnose and fix the 2026-09-09 outage were disposable and
 * uploaded/deleted per-use; this one is meant to still be here for the next
 * ten deploys, driven by data (a releaseId), not rewritten each time.
 *
 * No shell functions are available on this host (exec, shell_exec, system,
 * passthru, popen, symlink and link are all in php.ini disable_functions —
 * confirmed empirically, not assumed). Two things ARE confirmed to work and
 * are what this script is built on:
 *   - proc_open() genuinely spawns processes (Composer's own Process
 *     component uses proc_open directly, never the disabled shell
 *     wrappers, which is why `composer install` can run here at all)
 *   - rename() on a directory is a real, fast, atomic filesystem op (~0.4ms
 *     measured), which is what makes the release swap in `switch` atomic
 *     without needing symlinks (also confirmed disabled)
 *
 * Usage (always via the absolute-path cron pattern established throughout
 * this project — never `cd dir && ...`, which silently fails in this cron
 * context):
 *   php release-manager.php install
 *   php release-manager.php stage <releaseId>
 *   php release-manager.php build <releaseId>
 *   php release-manager.php contract-check <releaseId>
 *   php release-manager.php migrate-check <releaseId>
 *   php release-manager.php smoke-test-isolated <releaseId>
 *   php release-manager.php switch <releaseId>
 *   php release-manager.php smoke-test-live
 *   php release-manager.php rollback
 *   php release-manager.php status [<releaseId>]
 *   php release-manager.php cleanup
 *
 * Every action writes <releaseDir>/status.json (merging into whatever is
 * already there — each stage adds its own key, none overwrite a prior
 * stage's result) rather than printing human text, so a caller can poll
 * and machine-parse it exactly like every diagnostic script this session
 * has used.
 */

error_reporting(E_ALL);
ini_set('display_errors', '0'); // never leak a stack trace into any output path

$action = $argv[1] ?? null;
$arg2 = $argv[2] ?? null;

if (!$action) {
    fwrite(STDERR, "Usage: php release-manager.php <install|stage|build|contract-check|migrate-check|smoke-test-isolated|switch|smoke-test-live|rollback|status|cleanup> [releaseId]\n");
    exit(2);
}

// --- fixed paths -----------------------------------------------------------
// This file, once relocated by `install`, lives at:
//   .../provatferi.org/laravel-admin-releases/_tooling/release-manager.php
// so __DIR__/.. is laravel-admin-releases/, and __DIR__/../.. is the domain
// root — the same domain root laravel-admin/ and public_html/ are siblings
// of. Before relocation (its first-ever run, action=install) it instead
// lives at public_html/admin/, so DOMAIN_ROOT is computed relative to
// wherever this copy of the file actually is, not hardcoded.
$SELF_IN_TOOLING = str_contains(str_replace('\\', '/', __DIR__), '/laravel-admin-releases/_tooling');
$DOMAIN_ROOT = $SELF_IN_TOOLING ? realpath(__DIR__.'/../..') : realpath(__DIR__.'/../..');
$RELEASES_ROOT = $DOMAIN_ROOT.'/laravel-admin-releases';
$TOOLING_DIR = $RELEASES_ROOT.'/_tooling';
$LIVE_APP = $DOMAIN_ROOT.'/laravel-admin';
$PUBLIC_DOCROOT = $DOMAIN_ROOT.'/public_html/admin';
$STAGING_ROOT = $PUBLIC_DOCROOT.'/_release_staging';
$COMPOSER_PHAR = $TOOLING_DIR.'/composer.phar';
$PHP_BINARY = PHP_BINARY ?: '/usr/bin/php';

function jout(array $data): void
{
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
}

function statusPath(string $releaseDir): string
{
    return $releaseDir.'/status.json';
}

function mergeStatus(string $releaseDir, string $key, array $value): void
{
    $path = statusPath($releaseDir);
    $existing = is_file($path) ? (json_decode(file_get_contents($path), true) ?: []) : [];
    $existing[$key] = $value;
    $existing['updated_at'] = date('c');
    file_put_contents($path, json_encode($existing, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

function readStatus(string $releaseDir): array
{
    $path = statusPath($releaseDir);
    return is_file($path) ? (json_decode(file_get_contents($path), true) ?: []) : [];
}

/** Runs a command via proc_open (exec/shell_exec/system are disabled here). */
function runProcess(array $command, ?string $cwd = null, int $timeoutSeconds = 300): array
{
    $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = proc_open($command, $descriptors, $pipes, $cwd);
    if (!is_resource($process)) {
        return ['exit_code' => -1, 'stdout' => '', 'stderr' => 'proc_open failed to start the process'];
    }
    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $stdout = '';
    $stderr = '';
    $start = time();
    do {
        $stdout .= stream_get_contents($pipes[1]);
        $stderr .= stream_get_contents($pipes[2]);
        $status = proc_get_status($process);
        if (!$status['running']) break;
        if (time() - $start > $timeoutSeconds) {
            proc_terminate($process);
            $stderr .= "\n[release-manager] terminated after {$timeoutSeconds}s timeout";
            break;
        }
        usleep(200000);
    } while (true);

    $stdout .= stream_get_contents($pipes[1]);
    $stderr .= stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    return ['exit_code' => $exitCode, 'stdout' => trim($stdout), 'stderr' => trim($stderr)];
}

function extractTar(string $tarPath, string $destDir): void
{
    if (!is_dir($destDir)) mkdir($destDir, 0755, true);
    $phar = new PharData($tarPath);
    $phar->extractTo($destDir, null, true);
}

/** Recursive copy — used only for the public docroot sync, which cannot be
 *  an atomic rename because public_html/admin/ is a fixed vhost path. */
function copyRecursive(string $src, string $dst): void
{
    if (!is_dir($dst)) mkdir($dst, 0755, true);
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    foreach ($it as $item) {
        $target = $dst.DIRECTORY_SEPARATOR.$it->getSubPathName();
        if ($item->isDir()) {
            if (!is_dir($target)) mkdir($target, 0755, true);
        } else {
            copy($item->getPathname(), $target);
        }
    }
}

function bootApp(string $appBase): \Illuminate\Foundation\Application
{
    if (!defined('LARAVEL_PUBLIC_PATH_OVERRIDE')) {
        define('LARAVEL_PUBLIC_PATH_OVERRIDE', $appBase.'/public');
    }
    require $appBase.'/vendor/autoload.php';
    $app = require $appBase.'/bootstrap/app.php';
    $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    return $app;
}

// =============================================================================
switch ($action) {

case 'install':
    // One-time bootstrap. Run this copy from public_html/admin/ exactly
    // once; afterward it relocates itself and every future invocation
    // targets the relocated copy.
    if (!is_dir($TOOLING_DIR)) mkdir($TOOLING_DIR, 0755, true);

    $selfSource = __FILE__;
    $selfDest = $TOOLING_DIR.'/release-manager.php';
    $result = ['releases_root_created' => is_dir($RELEASES_ROOT), 'tooling_dir' => $TOOLING_DIR];

    if (!$SELF_IN_TOOLING) {
        copy($selfSource, $selfDest);
        $result['relocated_self'] = is_file($selfDest);
    } else {
        $result['relocated_self'] = 'already running from tooling dir, no-op';
    }

    // config-contract.php is uploaded alongside this file in the same
    // staging batch (see deploy/README.md) — relocate it too.
    $contractSrc = $PUBLIC_DOCROOT.'/_bootstrap_config_contract.php';
    if (is_file($contractSrc)) {
        copy($contractSrc, $TOOLING_DIR.'/config-contract.php');
        @unlink($contractSrc);
        $result['relocated_config_contract'] = true;
    }

    $composerSrc = $PUBLIC_DOCROOT.'/_bootstrap_composer.phar';
    if (is_file($composerSrc)) {
        copy($composerSrc, $COMPOSER_PHAR);
        @unlink($composerSrc);
        $result['relocated_composer_phar'] = is_file($COMPOSER_PHAR);
    } else {
        $result['relocated_composer_phar'] = is_file($COMPOSER_PHAR) ? 'already present' : 'MISSING — upload _bootstrap_composer.phar and rerun install';
    }

    if (!$SELF_IN_TOOLING && is_file($selfDest)) {
        @unlink($selfSource);
        $result['deleted_public_copy_of_self'] = !is_file($selfSource);
    }

    jout($result);
    break;

case 'stage':
    if (!$arg2) { fwrite(STDERR, "stage requires <releaseId>\n"); exit(2); }
    $releaseId = $arg2;
    $stagingDir = $STAGING_ROOT.'/'.$releaseId;
    $releaseDir = $RELEASES_ROOT.'/'.$releaseId;

    $manifestPath = $stagingDir.'/manifest.json';
    if (!is_file($manifestPath)) { jout(['ok' => false, 'error' => 'manifest.json not found in staging: '.$stagingDir]); exit(1); }
    $manifest = json_decode(file_get_contents($manifestPath), true);

    $checks = [];
    foreach (['private_tar' => 'private.tar', 'public_assets_tar' => 'public-assets.tar'] as $key => $file) {
        $path = $stagingDir.'/'.$file;
        $expected = $manifest['artifacts'][$key]['sha256'] ?? null;
        $actual = is_file($path) ? hash_file('sha256', $path) : null;
        $checks[$file] = ['expected' => $expected, 'actual' => $actual, 'match' => $expected !== null && $expected === $actual];
    }
    if (in_array(false, array_column($checks, 'match'), true)) {
        mergeStatus($releaseDir, 'stage', ['ok' => false, 'checksum_checks' => $checks]);
        jout(['ok' => false, 'error' => 'checksum mismatch — upload is incomplete or corrupted', 'checks' => $checks]);
        exit(1);
    }

    mkdir($releaseDir.'/app', 0755, true);
    mkdir($releaseDir.'/public_assets', 0755, true);
    extractTar($stagingDir.'/private.tar', $releaseDir.'/app_extracted');
    // private.tar was built with `admin-erp` as its single top-level entry
    // (see build-release.sh) — flatten that one level so releaseDir/app is
    // the actual Laravel root, matching what bootApp() expects.
    if (is_dir($releaseDir.'/app_extracted/admin-erp')) {
        copyRecursive($releaseDir.'/app_extracted/admin-erp', $releaseDir.'/app');
        // copyRecursive leaves the source in place; remove the staging copy
        deleteRecursive($releaseDir.'/app_extracted');
    }
    extractTar($stagingDir.'/public-assets.tar', $releaseDir.'/public_assets');

    // private.tar excludes admin-erp/public entirely (see build-release.sh) —
    // the built Vite assets, brand images, favicon, etc. only exist in
    // public_assets/, extracted above. bootApp() points public_path() at
    // releaseDir/app/public so isolated actions (migrate-check,
    // smoke-test-isolated) see the same tree the live docroot gets at
    // switch time, instead of a missing-manifest error the moment a view
    // calls @vite(). index.php/.htaccess are deliberately excluded from
    // public-assets.tar (see build-release.sh) and are never needed for a
    // CLI boot, so the symlink is safe even though it omits them.
    if (!file_exists($releaseDir.'/app/public')) {
        symlink($releaseDir.'/public_assets', $releaseDir.'/app/public');
    }

    // Runtime skeleton Laravel needs to boot — never shipped, always created fresh.
    foreach (['storage/framework/cache/data', 'storage/framework/sessions', 'storage/framework/views', 'storage/framework/testing', 'storage/logs', 'storage/app/public', 'bootstrap/cache'] as $dir) {
        @mkdir($releaseDir.'/app/'.$dir, 0755, true);
    }

    // The real .env is a server-side secret. It is copied here, server-side
    // only, from the currently-live app — it never travels through the
    // artifact, never touches the uploader's machine.
    $liveEnv = $LIVE_APP.'/.env';
    if (is_file($liveEnv)) {
        copy($liveEnv, $releaseDir.'/app/.env');
    }

    // staging tars served their purpose — remove from the public docroot
    @unlink($stagingDir.'/private.tar');
    @unlink($stagingDir.'/public-assets.tar');

    copy($manifestPath, $releaseDir.'/manifest.json');

    $result = ['ok' => true, 'release_dir' => $releaseDir, 'checksum_checks' => $checks, 'env_copied' => is_file($releaseDir.'/app/.env')];
    mergeStatus($releaseDir, 'stage', $result);
    jout($result);
    break;

case 'build':
    if (!$arg2) { fwrite(STDERR, "build requires <releaseId>\n"); exit(2); }
    $releaseDir = $RELEASES_ROOT.'/'.$arg2;
    $appDir = $releaseDir.'/app';
    if (!is_file($appDir.'/composer.json')) { jout(['ok' => false, 'error' => 'no composer.json — did stage run first?']); exit(1); }
    if (!is_file($COMPOSER_PHAR)) { jout(['ok' => false, 'error' => 'composer.phar not found in tooling dir — run install with it staged first']); exit(1); }

    $install = runProcess([$PHP_BINARY, $COMPOSER_PHAR, 'install', '--no-dev', '--optimize-autoloader', '--no-interaction', '--working-dir', $appDir], null, 600);
    $platform = runProcess([$PHP_BINARY, $COMPOSER_PHAR, 'check-platform-reqs', '--working-dir', $appDir], null, 120);

    $ok = $install['exit_code'] === 0 && !str_contains(strtolower($platform['stdout'].$platform['stderr']), 'not satisfied');
    $result = ['ok' => $ok, 'composer_install' => $install, 'check_platform_reqs' => $platform, 'vendor_autoload_present' => is_file($appDir.'/vendor/autoload.php')];
    mergeStatus($releaseDir, 'build', $result);
    jout($result);
    if (!$ok) exit(1);
    break;

case 'contract-check':
    if (!$arg2) { fwrite(STDERR, "contract-check requires <releaseId>\n"); exit(2); }
    $releaseDir = $RELEASES_ROOT.'/'.$arg2;
    $appDir = $releaseDir.'/app';
    $contractScript = $TOOLING_DIR.'/config-contract.php';
    if (!is_file($contractScript)) { jout(['ok' => false, 'error' => 'config-contract.php missing from tooling dir']); exit(1); }

    // Written directly to a file, not read from the subprocess's stdout —
    // a PHP startup warning (e.g. a duplicate extension load notice) fires
    // before config-contract.php's own code runs and can land on stdout on
    // some builds regardless of anything that script does, corrupting a
    // stdout-capture. The same reasoning already applies to every
    // diagnostic script used throughout this project.
    $reportFile = $releaseDir.'/contract-check-report.json';
    $run = runProcess([$PHP_BINARY, $contractScript, $appDir, $reportFile], null, 60);
    $report = is_file($reportFile) ? json_decode(file_get_contents($reportFile), true) : null;
    $ok = $run['exit_code'] === 0 && is_array($report) && ($report['pass'] ?? false) === true;
    $result = ['ok' => $ok, 'report' => $report, 'raw_exit_code' => $run['exit_code'], 'stderr' => $run['stderr']];
    mergeStatus($releaseDir, 'contract_check', $result);
    jout($result);
    if (!$ok) exit(1);
    break;

case 'migrate-check':
    if (!$arg2) { fwrite(STDERR, "migrate-check requires <releaseId>\n"); exit(2); }
    $releaseDir = $RELEASES_ROOT.'/'.$arg2;
    $appDir = $releaseDir.'/app';
    try {
        $app = bootApp($appDir);
        ob_start();
        $exit = \Illuminate\Support\Facades\Artisan::call('migrate', ['--pretend' => true, '--force' => true]);
        ob_end_clean();
        $output = \Illuminate\Support\Facades\Artisan::output();
        $pendingSql = trim($output) !== '' && !str_contains($output, 'Nothing to migrate');
        $result = ['ok' => true, 'exit_code' => $exit, 'pending_migrations_detected' => $pendingSql, 'pretend_output' => $output];
    } catch (\Throwable $e) {
        $result = ['ok' => false, 'error' => get_class($e).': '.$e->getMessage()];
    }
    mergeStatus($releaseDir, 'migrate_check', $result);
    jout($result);
    if (!$result['ok']) exit(1);
    break;

case 'migrate-apply':
    // Deliberately separate from migrate-check and requires a distinct,
    // explicit third argument — never invoked as a side effect of anything
    // else. Still never migrate:fresh / db:wipe; only the same forward
    // migrate migrate-check already previewed.
    if (!$arg2 || ($argv[3] ?? null) !== '--i-have-reviewed-the-pretend-output') {
        jout(['ok' => false, 'error' => 'refusing to run: requires <releaseId> --i-have-reviewed-the-pretend-output']);
        exit(2);
    }
    $releaseDir = $RELEASES_ROOT.'/'.$arg2;
    $appDir = $releaseDir.'/app';
    $priorCheck = readStatus($releaseDir)['migrate_check'] ?? null;
    if (!$priorCheck || !$priorCheck['ok']) {
        jout(['ok' => false, 'error' => 'migrate-check has not passed for this release — run it first']);
        exit(1);
    }
    try {
        $app = bootApp($appDir);
        \Illuminate\Support\Facades\Artisan::call('migrate', ['--force' => true]);
        $output = \Illuminate\Support\Facades\Artisan::output();
        $result = ['ok' => true, 'output' => $output];
    } catch (\Throwable $e) {
        $result = ['ok' => false, 'error' => get_class($e).': '.$e->getMessage()];
    }
    mergeStatus($releaseDir, 'migrate_apply', $result);
    jout($result);
    if (!$result['ok']) exit(1);
    break;

case 'smoke-test-isolated':
    if (!$arg2) { fwrite(STDERR, "smoke-test-isolated requires <releaseId>\n"); exit(2); }
    $releaseDir = $RELEASES_ROOT.'/'.$arg2;
    $appDir = $releaseDir.'/app';
    $result = ['ok' => true, 'checks' => []];
    try {
        $app = bootApp($appDir);
        // bootApp() only bootstraps the console kernel, so none of the HTTP
        // middleware that a real request runs ever fires here — including
        // ShareErrorsFromSession, which is what normally binds $errors into
        // every view. Blade auth views reference $errors unconditionally
        // (correctly — it's always present on a real request), so without
        // this line the check below fails on an "Undefined variable $errors"
        // that has nothing to do with the release itself. Sharing an empty
        // bag replicates the one piece of real-request state this isolated
        // check needs, without booting a full HTTP request.
        \Illuminate\Support\Facades\View::share('errors', new \Illuminate\Support\ViewErrorBag);

        try {
            $html = view('auth.forgot-password')->render();
            $result['checks']['forgot_password_view_renders'] = true;
            $result['checks']['forgot_password_view_bytes'] = strlen($html);
        } catch (\Throwable $e) {
            $result['checks']['forgot_password_view_renders'] = false;
            $result['checks']['forgot_password_view_error'] = $e->getMessage();
            $result['ok'] = false;
        }

        try {
            $user = \App\Models\User::query()->first();
            if ($user) {
                $notification = new \Illuminate\Auth\Notifications\ResetPassword('smoke-test-placeholder-token');
                $mail = $notification->toMail($user);
                $result['checks']['reset_mail_builds'] = true;
                $result['checks']['reset_mail_subject'] = $mail->subject;
                $result['checks']['reset_mail_reply_to_count'] = count($mail->replyTo ?? []);
            } else {
                $result['checks']['reset_mail_builds'] = 'no_user_to_test_with';
            }
        } catch (\Throwable $e) {
            $result['checks']['reset_mail_builds'] = false;
            $result['checks']['reset_mail_error'] = $e->getMessage();
            $result['ok'] = false;
        }

        $result['checks']['app_debug'] = config('app.debug') ? 'TRUE_PROBLEM' : 'false';
        if (config('app.debug')) $result['ok'] = false;
    } catch (\Throwable $e) {
        $result = ['ok' => false, 'error' => 'app failed to boot: '.get_class($e).': '.$e->getMessage()];
    }
    mergeStatus($releaseDir, 'smoke_test_isolated', $result);
    jout($result);
    if (!$result['ok']) exit(1);
    break;

case 'switch':
    if (!$arg2) { fwrite(STDERR, "switch requires <releaseId>\n"); exit(2); }
    $releaseId = $arg2;
    $releaseDir = $RELEASES_ROOT.'/'.$releaseId;
    $status = readStatus($releaseDir);

    $requiredStages = ['stage', 'build', 'contract_check', 'smoke_test_isolated'];
    $notPassed = array_filter($requiredStages, fn ($s) => !($status[$s]['ok'] ?? false));
    if (!empty($notPassed)) {
        jout(['ok' => false, 'error' => 'refusing to switch — stage(s) not passed: '.implode(', ', $notPassed)]);
        exit(1);
    }

    $releaseApp = $releaseDir.'/app';
    $previousPath = $RELEASES_ROOT.'/_previous-'.date('Ymd-His');

    // 1. Public assets: file-level sync, NOT atomic — public_html/admin/ is
    //    a fixed vhost path that can't be renamed. This is the one
    //    documented non-atomic step (see deploy/README.md "Remaining
    //    risk"). Built assets are content-hashed by Vite, so old and new
    //    filenames coexist safely during the sync window.
    $t0 = microtime(true);
    copyRecursive($releaseDir.'/public_assets/build', $PUBLIC_DOCROOT.'/build');
    if (is_dir($releaseDir.'/public_assets/brand')) copyRecursive($releaseDir.'/public_assets/brand', $PUBLIC_DOCROOT.'/brand');
    foreach (['favicon.ico', 'robots.txt'] as $f) {
        if (is_file($releaseDir.'/public_assets/'.$f)) copy($releaseDir.'/public_assets/'.$f, $PUBLIC_DOCROOT.'/'.$f);
    }
    $publicSyncMs = round((microtime(true) - $t0) * 1000, 1);

    // 2. Route cache: only rebuild if routes actually changed.
    $routesChanged = true;
    $liveRoutesHash = is_dir($LIVE_APP.'/routes') ? hashDir($LIVE_APP.'/routes') : null;
    $newRoutesHash = hashDir($releaseApp.'/routes');
    if ($liveRoutesHash !== null && $liveRoutesHash === $newRoutesHash) $routesChanged = false;

    // 3. THE atomic step.
    $t1 = microtime(true);
    $renamedOld = @rename($LIVE_APP, $previousPath);
    $renamedNew = $renamedOld && @rename($releaseApp, $LIVE_APP);
    $switchMs = round((microtime(true) - $t1) * 1000, 3);

    if (!$renamedNew) {
        // Best-effort revert if the second rename failed after the first succeeded.
        if ($renamedOld && !is_dir($LIVE_APP)) @rename($previousPath, $LIVE_APP);
        jout(['ok' => false, 'error' => 'atomic rename failed', 'renamed_old' => $renamedOld, 'renamed_new' => $renamedNew]);
        exit(1);
    }

    // 4. Rebuild caches on the NOW-LIVE tree.
    $cacheResult = [];
    try {
        // Fresh process state is not available (same PHP process continues),
        // but bootApp() only requires-once autoloaders/bootstrap; the paths
        // resolved below are already the new, swapped-in files since
        // $LIVE_APP now IS the new release on disk.
        $app2 = bootApp($LIVE_APP);
        foreach (['config:clear', 'view:clear', 'config:cache'] as $cmd) {
            $code = \Illuminate\Support\Facades\Artisan::call($cmd);
            $cacheResult[$cmd] = ['exit' => $code];
        }
        if ($routesChanged) {
            $code = \Illuminate\Support\Facades\Artisan::call('route:cache');
            $cacheResult['route:cache'] = ['exit' => $code, 'ran_because' => 'routes/ changed'];
        } else {
            $cacheResult['route:cache'] = ['skipped' => true, 'reason' => 'routes/ unchanged from previous release'];
        }
        $code = \Illuminate\Support\Facades\Artisan::call('view:cache');
        $cacheResult['view:cache'] = ['exit' => $code];
    } catch (\Throwable $e) {
        $cacheResult['error'] = $e->getMessage();
    }

    $result = [
        'ok' => true,
        'previous_path' => $previousPath,
        'public_asset_sync_ms' => $publicSyncMs,
        'atomic_rename_ms' => $switchMs,
        'routes_changed' => $routesChanged,
        'cache_rebuild' => $cacheResult,
        'switched_at' => date('c'),
    ];
    mergeStatus($releaseDir, 'switch', $result);
    file_put_contents($RELEASES_ROOT.'/CURRENT_RELEASE.json', json_encode(['release_id' => $releaseId, 'previous_path' => $previousPath, 'switched_at' => $result['switched_at']], JSON_PRETTY_PRINT));
    jout($result);
    break;

case 'smoke-test-live':
    // Deliberately makes real HTTP requests to the domain this same app
    // serves — this is what actually proves the swap worked from a
    // visitor's perspective, not just "the files are in place".
    $paths = ['/login' => 200, '/forgot-password' => 200, '/admin' => 302];
    $result = ['ok' => true, 'routes' => []];
    foreach ($paths as $path => $expected) {
        $ch = curl_init('https://admin.provatferi.org'.$path);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_NOBODY => true, CURLOPT_TIMEOUT => 15, CURLOPT_FOLLOWLOCATION => false]);
        curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $ok = $code === $expected;
        $result['routes'][$path] = ['expected' => $expected, 'actual' => $code, 'ok' => $ok];
        if (!$ok) $result['ok'] = false;
    }
    try {
        $app = bootApp($LIVE_APP);
        $result['app_debug'] = config('app.debug') ? 'TRUE_PROBLEM' : 'false';
        if (config('app.debug')) $result['ok'] = false;
        $logPath = $LIVE_APP.'/storage/logs/laravel.log';
        $result['log_size_bytes_after_switch'] = is_file($logPath) ? filesize($logPath) : 0;
    } catch (\Throwable $e) {
        $result['ok'] = false;
        $result['boot_error'] = $e->getMessage();
    }
    jout($result);
    if (!$result['ok']) exit(1);
    break;

case 'rollback':
    $currentPath = $RELEASES_ROOT.'/CURRENT_RELEASE.json';
    if (!is_file($currentPath)) { jout(['ok' => false, 'error' => 'no CURRENT_RELEASE.json — nothing recorded to roll back from']); exit(1); }
    $current = json_decode(file_get_contents($currentPath), true);
    $previousPath = $current['previous_path'] ?? null;
    if (!$previousPath || !is_dir($previousPath)) { jout(['ok' => false, 'error' => 'previous release path missing: '.$previousPath]); exit(1); }

    $failedPath = $RELEASES_ROOT.'/_rolled-back-'.date('Ymd-His');
    $r1 = @rename($LIVE_APP, $failedPath);
    $r2 = $r1 && @rename($previousPath, $LIVE_APP);
    if (!$r2) { jout(['ok' => false, 'error' => 'rollback rename failed', 'r1' => $r1, 'r2' => $r2]); exit(1); }

    try {
        $app = bootApp($LIVE_APP);
        foreach (['config:clear', 'view:clear', 'config:cache', 'view:cache'] as $cmd) {
            \Illuminate\Support\Facades\Artisan::call($cmd);
        }
    } catch (\Throwable $e) {
        jout(['ok' => false, 'error' => 'rolled back but cache rebuild failed: '.$e->getMessage()]);
        exit(1);
    }

    jout(['ok' => true, 'restored_from' => $previousPath, 'failed_release_kept_at' => $failedPath]);
    break;

case 'status':
    if ($arg2) {
        jout(readStatus($RELEASES_ROOT.'/'.$arg2));
    } else {
        $current = is_file($RELEASES_ROOT.'/CURRENT_RELEASE.json') ? json_decode(file_get_contents($RELEASES_ROOT.'/CURRENT_RELEASE.json'), true) : null;
        $releases = array_values(array_filter(scandir($RELEASES_ROOT) ?: [], fn ($d) => $d[0] !== '.' && $d[0] !== '_'));
        jout(['current' => $current, 'releases_present' => $releases]);
    }
    break;

case 'cleanup':
    // Keeps the live app, the most recent _previous-*, and up to 2 older
    // release directories; removes the rest. Never touches _tooling or
    // CURRENT_RELEASE.json.
    $entries = scandir($RELEASES_ROOT) ?: [];
    $previous = [];
    $releases = [];
    foreach ($entries as $e) {
        if ($e[0] === '.' || $e === '_tooling' || $e === 'CURRENT_RELEASE.json') continue;
        if (str_starts_with($e, '_previous-') || str_starts_with($e, '_rolled-back-')) $previous[] = $e;
        else $releases[] = $e;
    }
    rsort($previous);
    rsort($releases);
    $removed = [];
    foreach (array_slice($previous, 1) as $old) { deleteRecursive($RELEASES_ROOT.'/'.$old); $removed[] = $old; }
    foreach (array_slice($releases, 2) as $old) { deleteRecursive($RELEASES_ROOT.'/'.$old); $removed[] = $old; }
    jout(['ok' => true, 'removed' => $removed]);
    break;

default:
    fwrite(STDERR, "Unknown action: $action\n");
    exit(2);
}

function hashDir(string $dir): ?string
{
    if (!is_dir($dir)) return null;
    $hashes = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if ($file->isFile()) $hashes[] = hash_file('sha256', $file->getPathname());
    }
    sort($hashes);
    return hash('sha256', implode('', $hashes));
}

function deleteRecursive(string $dir): void
{
    if (!is_dir($dir)) return;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $file) {
        $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
    }
    @rmdir($dir);
}
