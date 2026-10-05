<?php
/**
 * Runs the one-time load of the owner-approved membership fee schedule from a STAGED release, before it is switched
 * live (Membership Registry task 1, 2026-10-05 — see docs/MEMBERSHIP_FEE_POLICIES.md and deploy/README.md).
 *
 *   php membership-fees-load.php <releaseId>          DRY RUN: prints exactly what would be written, writes nothing
 *   php membership-fees-load.php <releaseId> apply    carries it out (all or nothing)
 *
 * Why a script of its own: `membership:load-initial-policies` only exists in the NEW code, and a release's code is not
 * live until `switch`; this boots the staged tree (laravel-admin-releases/<releaseId>/app — the same tree migrate-check
 * and migrate-apply boot) so the policies exist the moment the release goes live and the public list is never empty.
 * Idempotent: a second `apply` changes nothing. Upload it to the admin docroot (public_html/admin/) for a one-shot cron
 * run, delete it afterwards. CLI only; prints no secrets.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
ini_set('display_errors', 'stderr');

$release = $argv[1] ?? '';
$apply = ($argv[2] ?? '') === 'apply';
if (preg_match('/^[A-Za-z0-9._-]+$/', $release) !== 1) {
    fwrite(STDERR, "usage: php membership-fees-load.php <releaseId> [apply]\n");
    exit(2);
}

// the script sits in <domain>/public_html/admin/; the releases are <domain>/laravel-admin-releases/
$base = getenv('QA_RELEASES') ? rtrim(getenv('QA_RELEASES'), '/').'/'.$release.'/app' : dirname(__DIR__, 2).'/laravel-admin-releases/'.$release.'/app';
if (! is_file($base.'/vendor/autoload.php') || ! is_file($base.'/bootstrap/app.php')) {
    echo json_encode(['ok' => false, 'error' => 'not a built, staged release: '.$base]), "\n";
    exit(1);
}

define('LARAVEL_START', microtime(true));
define('LARAVEL_PUBLIC_PATH_OVERRIDE', $base.'/public');
require $base.'/vendor/autoload.php';
$app = require $base.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo 'release: ', $release, "\n";
$code = $kernel->call('membership:load-initial-policies', $apply ? ['--apply' => true] : []);
echo $kernel->output();
echo 'exit code: ', $code, "\n";
exit($code);
