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
 *   php release-manager.php housekeeping [apply] [<ack,list>]   (dry-run unless `apply`; `cleanup` is an alias)
 *   php release-manager.php salvage <_previous-…|_rolled-back-…>
 *   php release-manager.php usage
 *
 * `pipeline <releaseId> [essential]` first checks account capacity and refuses a
 * non-essential deploy at >=80% inodes; `smoke-test-live` runs `housekeeping
 * apply` itself once the live site has passed — see lib/housekeeping.php.
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
    fwrite(STDERR, "Usage: php release-manager.php <install|pipeline|stage|build|contract-check|migrate-check|smoke-test-isolated|switch|smoke-test-live|rollback|status|housekeeping|salvage|usage> [releaseId]\n");
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
// copyRecursive(), countFilesRecursive(), syncUploadsAndVerify() — extracted
// 2026-09-25 into lib/uploads-persistence.php so the uploads-persistence
// contract is directly unit-testable (see that file's own docblock).
require_once __DIR__.'/lib/uploads-persistence.php';

// publicUploadsRoot(), ensurePublicUploadsRoot(), migrateLegacyPublicUploads(),
// setEnvValue(), writePublicUploadsSentinel() — the PUBLIC-uploads half of the
// same problem, added 2026-10-02 and split out for the same testability
// reason (see lib/public-uploads.php's docblock for the storage:link failure
// this exists to fix).
require_once __DIR__.'/lib/public-uploads.php';

// hkRun(), hkPlanRetention(), hkCapacity() … — release retention, staging/trash
// cleanup and the capacity guard, added 2026-10-03 after the hosting audit found
// 30 retired release copies holding 58% of the account's inodes. Pulls in
// lib/disk-audit.php for resourceUsage().
require_once __DIR__.'/lib/housekeeping.php';

/** The paths housekeeping works on, derived from where this copy of the tool lives. */
function hkContext(): array
{
    global $DOMAIN_ROOT, $RELEASES_ROOT, $LIVE_APP, $PUBLIC_DOCROOT, $STAGING_ROOT;

    return [
        'home' => dirname($DOMAIN_ROOT, 2),
        'releasesRoot' => $RELEASES_ROOT,
        'liveApp' => $LIVE_APP,
        'publicDocroot' => $PUBLIC_DOCROOT,
        'publicUploadsRoot' => publicUploadsRoot($PUBLIC_DOCROOT),
        'stagingRoot' => $STAGING_ROOT,
        'trashDirs' => [$DOMAIN_ROOT.'/.trash', $PUBLIC_DOCROOT.'/.trash'],
    ];
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

    // lib/uploads-persistence.php (added 2026-09-25) — required, unconditionally,
    // at the top of this very file (see the require_once above), so it must
    // already exist wherever THIS copy of release-manager.php is running
    // from, or the script fatals before this switch statement is ever
    // reached, on every action, not just install. Uploaded alongside
    // release-manager.php itself (as public_html/admin/lib/uploads-persistence.php)
    // in the same staging batch; relocated into _tooling/lib/ here, same
    // pattern as config-contract.php above.
    // Every lib is required unconditionally at the top of this file, so each
    // must exist next to whichever copy is running or every action fatals
    // before dispatch. lib/public-uploads.php joined the list 2026-10-02;
    // lib/housekeeping.php and lib/disk-audit.php (which housekeeping requires)
    // joined 2026-10-03.
    foreach (['uploads-persistence.php', 'public-uploads.php', 'housekeeping.php', 'disk-audit.php'] as $libFile) {
        $libSrc = $PUBLIC_DOCROOT.'/lib/'.$libFile;
        $libDest = $TOOLING_DIR.'/lib/'.$libFile;
        $resultKey = 'relocated_lib_'.str_replace(['-', '.php'], ['_', ''], $libFile);
        if (!$SELF_IN_TOOLING && is_file($libSrc)) {
            if (!is_dir($TOOLING_DIR.'/lib')) mkdir($TOOLING_DIR.'/lib', 0755, true);
            copy($libSrc, $libDest);
            @unlink($libSrc);
            $result[$resultKey] = is_file($libDest);
        } else {
            $result[$resultKey] = is_file($libDest) ? 'already present' : 'MISSING — upload lib/'.$libFile.' alongside release-manager.php and rerun install';
        }
    }
    @rmdir($PUBLIC_DOCROOT.'/lib'); // only removes it if now empty — never fails loudly if not

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
    // CLI boot. A symlink would be the cheaper option, but this host's
    // symlink() silently fatals with no error surfaced (verified directly:
    // an isolated, @-suppressed symlink() call still never completed) —
    // copyRecursive is already used twice above in this exact function, so
    // it's the proven-working primitive here instead.
    if (!file_exists($releaseDir.'/app/public')) {
        copyRecursive($releaseDir.'/public_assets', $releaseDir.'/app/public');
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

    // storage/app/private/uploads (the uploads_private disk — every
    // recruitment applicant's photo and CV) is anonymous-visitor runtime
    // data: never in git, never in the artifact, and — until this fix —
    // never carried forward here either. Every switch() atomically pointed
    // laravel-admin at a BRAND NEW directory whose uploads tree started
    // empty, silently orphaning every previously-uploaded file in the old,
    // now-unreachable release directory: the DB row and photo_path/cv_path
    // stayed correct, but the file itself 404'd — a broken image in Admin,
    // a broken image in the PDF, for every application older than the most
    // recent deploy at the time. Found 2026-09-24 tracing a reported broken-
    // photo defect back to its actual root cause. Copied the same way
    // .env is, immediately above — from the currently-live app, server-side
    // only — before this release ever goes live.
    // storage/app/private/uploads (the uploads_private disk — every
    // recruitment applicant's photo and CV) is anonymous-visitor runtime
    // data: never in git, never in the artifact. Every switch() atomically
    // pointed laravel-admin at a BRAND NEW directory whose uploads tree
    // started empty until 2026-09-24, silently orphaning every previously-
    // uploaded file in the old, now-unreachable release directory: the DB
    // row and photo_path/cv_path stayed correct, but the file itself 404'd —
    // a broken image in Admin, a broken image in the PDF, for every
    // application older than the most recent deploy at the time.
    //
    // syncUploadsAndVerify() (lib/uploads-persistence.php) both fixes that
    // AND proves it worked: hardened 2026-09-25 so a future regression here
    // (a permissions problem, copyRecursive breaking, this call being
    // removed) fails the stage step instead of silently reopening the same
    // incident with ok:true. `switch` already refuses to proceed past a
    // failed stage (see $requiredStages there).
    $liveUploads = $LIVE_APP.'/storage/app/private/uploads';
    $uploadsDest = $releaseDir.'/app/storage/app/private/uploads';
    $uploadsResult = syncUploadsAndVerify($liveUploads, $uploadsDest);

    // staging tars served their purpose — remove from the public docroot
    @unlink($stagingDir.'/private.tar');
    @unlink($stagingDir.'/public-assets.tar');

    copy($manifestPath, $releaseDir.'/manifest.json');

    // PUBLIC uploads (approved derivatives served over HTTP) — the mirror of
    // the private-uploads problem solved immediately above, found 2026-10-02.
    // storage:link can never work here (symlink() is disabled), so the
    // 'public' disk points at <docroot>/storage instead. That path lives
    // OUTSIDE every release directory on purpose: the switch renames
    // laravel-admin/, never the docroot, and the public-asset sync is purely
    // additive (copyRecursive only — it never prunes), so nothing in the
    // deploy can erase or orphan an upload written there. Three things happen
    // here, all idempotent: the root is created and proven writable, anything
    // still in the old unreachable stock location is copied forward once, and
    // PUBLIC_UPLOADS_ROOT is pinned in this release's .env so the live value
    // is explicit rather than relying on helpers.php's auto-detection alone.
    $publicUploadsRoot = publicUploadsRoot($PUBLIC_DOCROOT);
    $publicUploads = ensurePublicUploadsRoot($publicUploadsRoot);
    $publicUploads['legacy_migration'] = migrateLegacyPublicUploads($LIVE_APP.'/storage/app/public', $publicUploadsRoot);
    $publicUploads['env_pinned'] = setEnvValue($releaseDir.'/app/.env', 'PUBLIC_UPLOADS_ROOT', $publicUploadsRoot);
    $publicUploads['sentinel'] = writePublicUploadsSentinel($publicUploadsRoot, $releaseId);
    if (!($publicUploads['sentinel']['ok'] ?? false)) {
        $publicUploads['ok'] = false;
    }

    $result = [
        'ok' => $uploadsResult['ok'] && $publicUploads['ok'], 'release_dir' => $releaseDir, 'checksum_checks' => $checks,
        'env_copied' => is_file($releaseDir.'/app/.env'),
        'uploads_persisted' => $uploadsResult,
        'public_uploads' => $publicUploads,
    ];
    if (!$uploadsResult['ok']) {
        $result['error'] = "uploads regression: {$uploadsResult['source_file_count']} file(s) existed on the live disk before staging, only {$uploadsResult['dest_file_count']} survived the copy into the new release — refusing to let switch proceed. Investigate copyRecursive/permissions before retrying; do not re-run switch against this releaseId until this passes.";
    } elseif (!$publicUploads['ok']) {
        $result['error'] = 'public uploads root unusable at '.$publicUploadsRoot.' — every approved photo would publish to a path no visitor can fetch. Refusing to let switch proceed.';
    }
    mergeStatus($releaseDir, 'stage', $result);
    // The manifest was copied into the release dir above and the tars are gone,
    // so the staging dir is empty weight — 19 of them had piled up by 2026-10-03.
    // Only on success: a failed stage leaves its inputs for diagnosis.
    if ($result['ok']) {
        @unlink($manifestPath);
        @rmdir($stagingDir);
    }
    jout($result);
    if (!$result['ok']) exit(1);
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

        // Contract-check hardening added 2026-09-25 (see stage's own
        // matching check): stage() proves a file count survived the copy,
        // but a file existing on disk is not the same as the app's own
        // uploads_private disk config actually resolving to it — a wrong
        // disk root, permissions the webserver user can't read, or a stale
        // cached config could all make a byte-identical file unreadable to
        // the app while stage's count-based check still passes. This reads
        // ONE real, pre-existing upload back through Storage::disk exactly
        // as JobApplicationController does, in the NEW release, before it
        // ever goes live — so a regression here blocks switch instead of
        // surfacing as a broken photo/CV download after the fact.
        try {
            $uploadsRoot = $appDir.'/storage/app/private/uploads';
            $sampleRelativePath = null;
            if (is_dir($uploadsRoot)) {
                $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($uploadsRoot, FilesystemIterator::SKIP_DOTS));
                foreach ($it as $file) {
                    if ($file->isFile()) {
                        $sampleRelativePath = ltrim(str_replace($uploadsRoot, '', $file->getPathname()), '/\\');
                        break;
                    }
                }
            }

            if ($sampleRelativePath === null) {
                $result['checks']['uploads_readable'] = 'no_uploads_to_test_with';
            } else {
                $disk = \Illuminate\Support\Facades\Storage::disk('uploads_private');
                $existsViaDisk = $disk->exists($sampleRelativePath);
                $bytes = $existsViaDisk ? $disk->get($sampleRelativePath) : null;
                $rawBytes = filesize($uploadsRoot.'/'.$sampleRelativePath);
                $readableAndMatchesSize = $existsViaDisk && $bytes !== null && strlen($bytes) === $rawBytes && $rawBytes > 0;

                $result['checks']['uploads_readable'] = $readableAndMatchesSize;
                $result['checks']['uploads_readable_sample'] = $sampleRelativePath;
                if (!$readableAndMatchesSize) {
                    $result['checks']['uploads_readable_detail'] = [
                        'exists_via_disk' => $existsViaDisk,
                        'bytes_via_disk' => $bytes === null ? null : strlen($bytes),
                        'bytes_on_raw_filesystem' => $rawBytes,
                    ];
                    $result['ok'] = false;
                }
            }
        } catch (\Throwable $e) {
            $result['checks']['uploads_readable'] = false;
            $result['checks']['uploads_readable_error'] = $e->getMessage();
            $result['ok'] = false;
        }
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
    // public/js/ (plain static scripts) — found 2026-09-24 missing from this
    // list entirely: build-release.sh already packages it into public_assets/js
    // (see that file), but nothing here ever copied it into the live docroot,
    // so a real file could ship in git, pass every build check, and still
    // 404 in production forever. Same treatment as brand/ above.
    if (is_dir($releaseDir.'/public_assets/js')) copyRecursive($releaseDir.'/public_assets/js', $PUBLIC_DOCROOT.'/js');
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

    // 5. PUBLIC UPLOADS PERSISTENCE GATE. The sentinel stage() wrote into the
    //    docroot's storage/ root must still be there, byte-for-byte, AFTER
    //    the swap and after the public-asset sync ran over the same docroot.
    //    This is the check that would have caught the 2026-10-02 defect (and
    //    would catch a future regression that reintroduced a release-local
    //    public root, or made the asset sync destructive) instead of a broken
    //    image being discovered by a visitor months later.
    $publicUploadsRoot = publicUploadsRoot($PUBLIC_DOCROOT);
    $expectedSentinel = $status['stage']['public_uploads']['sentinel'] ?? null;
    $sentinelCheck = ['ok' => false, 'reason' => 'stage recorded no sentinel'];
    if (is_array($expectedSentinel) && ($expectedSentinel['relative_path'] ?? null)) {
        $sentinelFile = $publicUploadsRoot.'/'.$expectedSentinel['relative_path'];
        $actualHash = is_file($sentinelFile) ? hash_file('sha256', $sentinelFile) : null;
        $sentinelCheck = [
            'ok' => $actualHash !== null && $actualHash === ($expectedSentinel['sha256'] ?? null),
            'path' => $sentinelFile,
            'expected_sha256' => $expectedSentinel['sha256'] ?? null,
            'actual_sha256' => $actualHash,
            'survived_switch' => $actualHash !== null,
        ];
    }

    $result = [
        'ok' => $sentinelCheck['ok'],
        'previous_path' => $previousPath,
        'public_asset_sync_ms' => $publicSyncMs,
        'atomic_rename_ms' => $switchMs,
        'routes_changed' => $routesChanged,
        'cache_rebuild' => $cacheResult,
        'public_uploads_persisted' => $sentinelCheck,
        'switched_at' => date('c'),
    ];
    if (!$sentinelCheck['ok']) {
        $result['error'] = 'public uploads did not survive the switch byte-identically — approved photos are at risk of being erased or unreachable. The new release IS live (the rename already completed); verify '.$publicUploadsRoot.' before trusting any upload, and consider rollback.';
    }
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

        // The public-upload path proven END TO END over real HTTP: the disk
        // the live app would actually write an approved photo to, fetched
        // back as a visitor, with its bytes compared. A 200 here is the only
        // thing that distinguishes "the file exists on disk" (which was
        // always true, even while broken) from "a visitor can see it".
        $sentinelRelative = null;
        $sentinelDir = config('filesystems.disks.public.root').'/_deploy_sentinel';
        if (is_dir($sentinelDir)) {
            $newest = null;
            foreach (glob($sentinelDir.'/*.txt') ?: [] as $candidate) {
                if ($newest === null || filemtime($candidate) > filemtime($newest)) $newest = $candidate;
            }
            if ($newest !== null) $sentinelRelative = '_deploy_sentinel/'.basename($newest);
        }

        if ($sentinelRelative === null) {
            $result['public_upload_http'] = ['ok' => false, 'reason' => 'no sentinel file found under the live public disk root'];
            $result['ok'] = false;
        } else {
            $url = \Illuminate\Support\Facades\Storage::disk('public')->url($sentinelRelative);
            $onDisk = config('filesystems.disks.public.root').'/'.$sentinelRelative;
            $ch = curl_init($url);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_FOLLOWLOCATION => false]);
            $body = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            $diskHash = is_file($onDisk) ? hash_file('sha256', $onDisk) : null;
            $httpHash = is_string($body) ? hash('sha256', $body) : null;
            $httpOk = $code === 200 && $diskHash !== null && $diskHash === $httpHash;

            $result['public_upload_http'] = [
                'ok' => $httpOk,
                'url' => $url,
                'http_code' => $code,
                'disk_sha256' => $diskHash,
                'http_sha256' => $httpHash,
                'bytes_match' => $diskHash !== null && $diskHash === $httpHash,
            ];
            if (!$httpOk) {
                $result['ok'] = false;
                $result['public_upload_error'] = 'the live public disk is not web-reachable (or served altered bytes) — approved photos will appear broken to visitors. This is the exact 2026-10-02 storage:link failure mode; check the public disk root and the vhost docroot before shipping.';
            }
        }
    } catch (\Throwable $e) {
        $result['ok'] = false;
        $result['boot_error'] = $e->getMessage();
    }

    // Housekeeping only ever runs behind a passing live check, so a release that
    // needs rolling back still has its rollback target. It cannot flip `ok`: the
    // deploy itself succeeded — a housekeeping problem is reported, loudly, next
    // to it. The retention rules (what is never deleted) are in lib/housekeeping.php.
    if ($result['ok']) {
        try {
            $result['housekeeping'] = hkRun(hkContext(), true, ['measure_before' => false, 'label' => 'post-deploy '.(hkReadCurrent($RELEASES_ROOT)['release_id'] ?? '')]);
        } catch (\Throwable $e) {
            $result['housekeeping'] = ['ok' => false, 'error' => get_class($e).': '.$e->getMessage()];
        }
    }
    jout($result);
    if (!$result['ok']) exit(1);
    break;

case 'pipeline':
    // Runs every PRE-SWITCH action in sequence, in one invocation, stopping
    // at the first failure. Added 2026-10-02 for a blunt operational reason:
    // this host caps a cron command at 255 characters once escaped, and the
    // absolute path to this script is 91 of them, so chaining actions with
    // `&&` in the cron itself does not fit. Without this, every deploy costs
    // one ~5-minute cron round-trip PER action.
    //
    // Deliberately STOPS before `switch`: switch is the irreversible step and
    // stays a separate, explicit decision made after a human has read
    // migrate-check's pretend output. Same reason `migrate-apply` is not here.
    if (!$arg2) { fwrite(STDERR, "pipeline requires <releaseId>\n"); exit(2); }
    $releaseId = $arg2;
    $releaseDir = $RELEASES_ROOT.'/'.$releaseId;

    $steps = ['stage', 'build', 'contract-check', 'migrate-check', 'smoke-test-isolated'];
    $pipeline = ['ok' => true, 'release_id' => $releaseId, 'steps' => []];

    // Capacity preflight (2026-10-03). A deploy writes ~9,000 files and ~200 MB;
    // on an account already past 80% of its inode quota that can be the deploy
    // that exhausts it, and the failure lands mid-switch. Blocked here, before
    // anything is staged, unless the caller says the deploy is `essential`. The
    // figures are account-wide because the quota is: see hkCapacity().
    $capacity = hkCapacity(resourceUsage(dirname($DOMAIN_ROOT, 2)), hkPolicy());
    $pipeline['capacity_preflight'] = $capacity;
    if ($capacity['status'] === 'block' && !in_array('essential', array_slice($argv, 3), true)) {
        $pipeline['ok'] = false;
        $pipeline['failed_at'] = 'capacity_preflight';
        $pipeline['next'] = 'free space first (housekeeping apply), or re-run `pipeline '.$releaseId.' essential` if this deploy cannot wait';
        jout($pipeline);
        exit(1);
    }

    foreach ($steps as $step) {
        $run = runProcess([$PHP_BINARY, __FILE__, $step, $releaseId], null, 900);
        $stepOk = $run['exit_code'] === 0;
        $pipeline['steps'][$step] = ['ok' => $stepOk, 'exit_code' => $run['exit_code']];
        if (!$stepOk) {
            $pipeline['ok'] = false;
            $pipeline['failed_at'] = $step;
            // The step already merged its own detail into status.json; echo
            // its tail here so the cron output alone explains the failure.
            $pipeline['steps'][$step]['stderr'] = substr($run['stderr'], -800);
            $pipeline['steps'][$step]['stdout_tail'] = substr($run['stdout'], -800);
            break;
        }
    }
    $pipeline['next'] = $pipeline['ok']
        ? 'review migrate-check in status.json, then run: switch '.$releaseId
        : 'fix the failure above and re-run pipeline '.$releaseId;
    jout($pipeline);
    if (!$pipeline['ok']) exit(1);
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
        $retired = array_values(array_filter(scandir($RELEASES_ROOT) ?: [], fn ($d) => str_starts_with($d, '_previous-') || str_starts_with($d, '_rolled-back-')));
        jout(['current' => $current, 'releases_present' => $releases, 'retired_copies' => count($retired), 'capacity_alert' => is_file($RELEASES_ROOT.'/CAPACITY_ALERT.json')]);
    }
    break;

case 'housekeeping':
case 'cleanup':
    // Replaces the original `cleanup`, which deleted for real with no arguments and
    // could not be trusted: it sorted `_rolled-back-*` ahead of `_previous-*` (one
    // rolled-back copy made it delete every real rollback target) and treated
    // commit-sha release ids as timestamps. Dry-run unless `apply` is given. The
    // rules — and everything that is never deleted — live in lib/housekeeping.php.
    // Extra words after `apply`: `salvage` preserves (hash-verified) any storage/app
    // file a retired copy holds that the live app lacks, then deletes the copy;
    // anything else is a comma-separated list of copies a human already reviewed.
    $apply = $arg2 === 'apply';
    $extra = array_slice($argv, 3);
    $salvage = in_array('salvage', $extra, true);
    $ack = array_values(array_filter(explode(',', implode(',', array_diff($extra, ['salvage'])))));
    $report = hkRun(hkContext(), $apply, ['measure_before' => true, 'ack' => $ack, 'salvage' => $salvage, 'label' => $apply ? 'housekeeping apply' : 'housekeeping dry-run']);
    jout($report);
    if (!$report['ok']) exit(1);
    break;

case 'salvage':
    // Copies a retired release's storage/app files that the live app lacks into
    // _salvaged-uploads/ (hash-verified), so housekeeping can then be told to
    // delete that copy with nothing lost. Never deletes anything itself.
    if (!$arg2) { fwrite(STDERR, "salvage requires a retired-release directory name\n"); exit(2); }
    $result = hkSalvageUnique(hkContext(), $arg2);
    jout($result);
    if (!$result['ok']) exit(1);
    break;

case 'usage':
    $usage = resourceUsage(dirname($DOMAIN_ROOT, 2));
    jout(['usage' => $usage, 'capacity' => hkCapacity($usage, hkPolicy())]);
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

