<?php
/**
 * Fingerprints a built Laravel application so two builds can be compared field by field.
 * (deploy/README.md, "Artifact equivalence".) CLI only: 404 otherwise.
 *
 * WHY THIS EXISTS. `build` installs with `--no-scripts` and then runs the lifecycle script itself, because
 * composer's own script runner needs proc_open and this host no longer allows it. That is only acceptable if the
 * result is the SAME application a normal `composer install` would have produced — otherwise the deploy quietly
 * becomes a "special Hostinger build" with something missing, which is exactly the class of bug that shipped on
 * 2026-09-09.
 *
 * So this prints a fingerprint, the same way on any machine, and the two are compared:
 *
 *   php build-equivalence.php <path-to-app-root>
 *
 * It reads only. It starts no process, boots no framework, and touches nothing — a fingerprint that changed what it
 * measured would be useless.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
ini_set('display_errors', 'stderr');

$app = rtrim((string) ($argv[1] ?? ''), '/\\');
if ($app === '' || ! is_file($app.'/composer.json')) {
    fwrite(STDERR, "usage: php build-equivalence.php <path-to-app-root>\n");
    exit(2);
}

/** A stable hash of a list, independent of the order it was discovered in. */
function fingerprint(array $items): string
{
    sort($items, SORT_STRING);

    return substr(hash('sha256', implode("\n", $items)), 0, 16);
}

$out = ['app' => basename($app), 'checked_at_utc' => gmdate('c')];

// ------------------------------------------------------------------ the installed package set
$installedPath = $app.'/vendor/composer/installed.json';
$packages = [];
if (is_file($installedPath)) {
    $decoded = json_decode((string) file_get_contents($installedPath), true);
    foreach ((array) ($decoded['packages'] ?? $decoded ?? []) as $package) {
        if (is_array($package) && isset($package['name'], $package['version'])) {
            $packages[] = $package['name'].'@'.$package['version'];
        }
    }
}
$out['packages'] = [
    'count' => count($packages),
    'fingerprint' => fingerprint($packages),
    'dev_packages_present' => $decoded['dev'] ?? null,
];

// ------------------------------------------------------------------ lock consistency
$lock = is_file($app.'/composer.lock') ? json_decode((string) file_get_contents($app.'/composer.lock'), true) : null;
$manifestJson = json_decode((string) file_get_contents($app.'/composer.json'), true);
$out['composer_lock'] = [
    'content_hash' => is_array($lock) ? ($lock['content-hash'] ?? null) : null,
    'locked_packages' => is_array($lock) ? count((array) ($lock['packages'] ?? [])) : 0,
    'locked_dev_packages' => is_array($lock) ? count((array) ($lock['packages-dev'] ?? [])) : 0,
    // The non-dev lock set is what a --no-dev install should produce, so these two numbers must agree.
    'matches_installed_count' => is_array($lock) ? count((array) ($lock['packages'] ?? [])) === count($packages) : false,
    'php_requirement' => $manifestJson['require']['php'] ?? null,
];

// ------------------------------------------------------------------ autoloader
$classmap = $app.'/vendor/composer/autoload_classmap.php';
$classmapEntries = 0;
if (is_file($classmap)) {
    $map = @include $classmap;
    $classmapEntries = is_array($map) ? count($map) : 0;
}
$out['autoload'] = [
    'autoload_php_present' => is_file($app.'/vendor/autoload.php'),
    'classmap_entries' => $classmapEntries,
    // An optimised autoloader is the difference between a classmap of a few hundred and one of many thousands.
    'optimised' => $classmapEntries > 3000,
    'psr4_present' => is_file($app.'/vendor/composer/autoload_psr4.php'),
    'files_present' => is_file($app.'/vendor/composer/autoload_files.php'),
];

// ------------------------------------------------------------------ package discovery
// The names, not the file, because the file holds absolute paths that differ between machines by definition. What
// has to match is WHICH packages were discovered and WHICH providers and aliases they registered.
$packagesCache = $app.'/bootstrap/cache/packages.php';
$discovered = [];
$providers = [];
$aliases = [];
if (is_file($packagesCache)) {
    $cache = @include $packagesCache;
    foreach ((array) $cache as $name => $entry) {
        $discovered[] = (string) $name;
        foreach ((array) ($entry['providers'] ?? []) as $provider) {
            $providers[] = (string) $provider;
        }
        foreach ((array) ($entry['aliases'] ?? []) as $alias => $class) {
            $aliases[] = $alias.'=>'.$class;
        }
    }
}
$out['package_discovery'] = [
    'cache_present' => is_file($packagesCache),
    'packages' => count($discovered),
    'packages_fingerprint' => fingerprint($discovered),
    'providers' => count($providers),
    'providers_fingerprint' => fingerprint($providers),
    'aliases' => count($aliases),
    'aliases_fingerprint' => fingerprint($aliases),
];

// ------------------------------------------------------------------ the application's own files
$count = static function (string $dir, string $pattern) use ($app): int {
    $found = glob($app.'/'.$dir.'/'.$pattern);

    return is_array($found) ? count($found) : 0;
};
$viewFiles = [];
if (is_dir($app.'/resources/views')) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($app.'/resources/views', FilesystemIterator::SKIP_DOTS | FilesystemIterator::UNIX_PATHS));
    foreach ($iterator as $file) {
        if ($file->isFile()) {
            $viewFiles[] = substr($file->getPathname(), strlen($app) + 1);
        }
    }
}
$out['application'] = [
    'config_files' => $count('config', '*.php'),
    'migrations' => $count('database/migrations', '*.php'),
    'route_files' => $count('routes', '*.php'),
    'view_files' => count($viewFiles),
    'views_fingerprint' => fingerprint($viewFiles),
    'artisan_present' => is_file($app.'/artisan'),
];

// ------------------------------------------------------------------ the packages specific features depend on
// Named rather than counted: if one of these ever goes missing from a build, the feature it carries goes with it,
// and "98 packages installed" would still look right.
$required = [
    'mpdf/mpdf' => 'Bengali PDF engine',
    'setasign/fpdi' => 'PDF assembly',
    'hostinger/mail-api-php-sdk' => 'Mail Center',
    'pragmarx/google2fa' => 'admin two-step verification',
    'bacon/bacon-qr-code' => 'two-step enrolment QR codes',
    'laravel/sanctum' => 'member portal tokens',
    'ezyang/htmlpurifier' => 'rich-text sanitising',
    'laravel/framework' => 'the framework itself',
];
$names = array_map(static fn (string $p): string => explode('@', $p)[0], $packages);
$features = [];
foreach ($required as $package => $what) {
    $features[$package] = ['present' => in_array($package, $names, true), 'carries' => $what];
}
$out['feature_dependencies'] = $features;
$out['feature_dependencies_all_present'] = ! in_array(false, array_column($features, 'present'), true);

// The backup system needs no package of its own — it is deliberately pure PHP — so what is checked is that its code
// arrived in the artifact at all.
$out['backup_code_present'] = [
    'services' => count((array) glob($app.'/app/Services/Backup/*.php')),
    'commands' => count((array) glob($app.'/app/Console/Commands/Backup*.php')),
    'config' => is_file($app.'/config/backup.php'),
    'notification' => is_file($app.'/app/Notifications/BackupFailedNotification.php'),
];

// ------------------------------------------------------------------ built front-end assets
$viteManifest = null;
foreach (['/public/build/manifest.json', '/public/build/.vite/manifest.json'] as $candidate) {
    if (is_file($app.$candidate)) {
        $viteManifest = $app.$candidate;
        break;
    }
}
$out['vite'] = [
    'manifest_present' => $viteManifest !== null,
    'manifest_sha256' => $viteManifest !== null ? hash_file('sha256', $viteManifest) : null,
    'entries' => $viteManifest !== null ? count((array) json_decode((string) file_get_contents($viteManifest), true)) : 0,
];

echo json_encode($out, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), "\n";
