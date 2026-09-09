<?php
/**
 * Config contract checker — the direct countermeasure to the 2026-09-09
 * production outage (commit 59530fa): AppServiceProvider.php referenced
 * config('mail.reply_to.support') while the config/mail.php defining that
 * key never reached production. Nothing checked that before this existed.
 *
 * What it actually checks, precisely: not "is this value null" (a config key
 * can legitimately be null — mail.reply_to.support being null is fine now
 * that AppServiceProvider guards it) but "does this dotted path exist in the
 * config tree at all". A key that's missing entirely and a key that exists
 * and is null are indistinguishable through config('a.b.c') alone, so this
 * walks the array with array_key_exists() at every segment instead of
 * calling the config() helper for the final read.
 *
 * Usage:
 *   php config-contract.php <app-base-path>
 *
 * <app-base-path> is a Laravel application root (has artisan, app/,
 * bootstrap/app.php). Scans that same tree's app/, routes/, resources/views/
 * for literal config('a.b.c') / config("a.b.c") call sites, boots the app
 * from that same base path, and checks every discovered path.
 *
 * Exit code 0: every statically-discovered key path exists. Exit code 1:
 * at least one does not — this is the exact failure class that shipped the
 * outage, so it is a hard failure. Dynamic call sites (the argument isn't a
 * single plain string literal, e.g. string concatenation) can't be resolved
 * statically and are reported separately for manual review — they never
 * cause a non-zero exit on their own, since a dynamic key not existing at a
 * given runtime value may be perfectly intentional (see the
 * config('auth.passwords.'.config(...).'.expire') call this project itself
 * has — the outer call is dynamic and correctly not checked here, while the
 * inner config('auth.defaults.passwords') is static and is checked).
 *
 * Prints a JSON report to stdout either way, so a caller (this script,
 * package.sh, or remote/release-manager.php) can machine-parse the result
 * rather than scraping human-readable text.
 */

// This script's entire contract is "prints ONLY a JSON object to stdout" —
// callers (build-release.sh, release-manager.php) machine-parse it. Some
// PHP builds duplicate a warning to stdout via display_errors even when
// log_errors already sent it to stderr (observed locally: a loaded-twice
// openssl module notice on this dev machine's php.ini) — routing display
// output to stderr keeps stdout JSON-only regardless of which php.ini a
// given environment ships.
ini_set('display_errors', 'stderr');

if ($argc < 2) {
    fwrite(STDERR, "Usage: php config-contract.php <app-base-path>\n");
    exit(2);
}

$base = rtrim($argv[1], '/\\');
if (!is_file($base.'/bootstrap/app.php')) {
    fwrite(STDERR, "Not a Laravel app root (no bootstrap/app.php): {$base}\n");
    exit(2);
}

// --- 1. statically discover every config('...') / config("...") call site ---
$scanDirs = ['app', 'routes', 'resources/views', 'bootstrap'];
$staticKeys = [];   // dotted-path => [ {file, line}, ... ]
$dynamicSites = []; // [ {file, line, snippet}, ... ]

$phpFiles = [];
foreach ($scanDirs as $dir) {
    $full = $base.'/'.$dir;
    if (!is_dir($full)) continue;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($full, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if (preg_match('/\.(php|blade\.php)$/', $file->getFilename())) {
            $phpFiles[] = $file->getPathname();
        }
    }
}

foreach ($phpFiles as $path) {
    $lines = file($path);
    if ($lines === false) continue;

    foreach ($lines as $i => $line) {
        if (!preg_match_all('/\bconfig\s*\(/', $line, $m, PREG_OFFSET_CAPTURE)) continue;

        foreach ($m[0] as [, $offset]) {
            $argStart = $offset + strlen($m[0][0][0]);
            // Grab a bounded window after the opening paren — call sites in
            // this codebase are all single-line; this avoids a full
            // tokenizer for what is, in practice, a simple pattern.
            $window = substr($line, $argStart, 200);

            if (preg_match('/^\s*([\'"])((?:[^\'"\\\\]|\\\\.)*)\1\s*[,)]/', $window, $sm)) {
                $key = $sm[2];
                if ($key === '') continue; // config() with no args — reads everything, not a path
                $staticKeys[$key][] = ['file' => relativePath($base, $path), 'line' => $i + 1];
            } else {
                $snippet = trim(substr($line, max(0, $offset - 10), 90));
                $dynamicSites[] = ['file' => relativePath($base, $path), 'line' => $i + 1, 'snippet' => $snippet];
            }
        }
    }
}

ksort($staticKeys);

// --- 2. boot the app from its OWN base path (matters for release-directory checks) ---
define('LARAVEL_PUBLIC_PATH_OVERRIDE', $base.'/public');
require $base.'/vendor/autoload.php';
$app = require $base.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

// --- 3. walk each static key with array_key_exists, not config()'s null-collapsing read ---
function pathExists(string $dotPath): bool
{
    $segments = explode('.', $dotPath);
    $top = array_shift($segments);
    $repo = app('config');
    if (!$repo->has($top) && !array_key_exists($top, $repo->all())) {
        return false;
    }
    $cursor = $repo->get($top);
    foreach ($segments as $segment) {
        if (!is_array($cursor) || !array_key_exists($segment, $cursor)) {
            return false;
        }
        $cursor = $cursor[$segment];
    }
    return true;
}

$missing = [];
$presentButNull = [];
$ok = [];

foreach ($staticKeys as $key => $sites) {
    if (!pathExists($key)) {
        $missing[$key] = $sites;
        continue;
    }
    $value = config($key);
    if ($value === null) {
        $presentButNull[$key] = $sites;
    } else {
        $ok[] = $key;
    }
}

$report = [
    'checked_at' => date('c'),
    'app_base' => $base,
    'static_keys_checked' => count($staticKeys),
    'ok_count' => count($ok),
    'present_but_null' => $presentButNull,   // informational — not a failure by itself
    'missing' => $missing,                   // the actual incident class — hard failure
    'dynamic_call_sites' => $dynamicSites,    // can't verify statically — for human review
    'pass' => count($missing) === 0,
];

echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
exit($report['pass'] ? 0 : 1);

function relativePath(string $base, string $path): string
{
    return ltrim(str_replace($base, '', $path), '/\\');
}
