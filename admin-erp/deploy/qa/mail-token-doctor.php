<?php
/**
 * TRACES THE MAIL API TOKEN FROM .env TO THE RUNNING APPLICATION (docs/MAIL_CENTER.md). CLI only: 404 otherwise.
 *
 * WHY THIS EXISTS. On 2026-10-26 `mail:discover` reported the token "not set" while the owner could see a working token
 * in hPanel. Either answer alone is a guess; this walks every link of the chain and reports what each one actually holds
 * — so a false negative (a stale configuration cache, a release-local .env, a name or quoting mistake) can be told apart
 * from a genuinely absent value.
 *
 * IT NEVER PRINTS A SECRET. For every value it reports only: whether it is present, its length, and the first eight
 * characters of a keyed digest of it, which is enough to prove two places hold the SAME value without revealing it.
 *
 *   doctor      Read-only. The whole chain: the .env file the app reads, the key's raw line (shape only), the value the
 *               configuration exposes, whether the configuration is cached and whether that cache agrees with the file,
 *               and the same for every other .env on the server that could be the one being edited.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
ini_set('display_errors', 'stderr');

const QA_DOMAIN_ROOT = '/home/u951246149/domains/provatferi.org';
const KEY = 'HOSTINGER_MAIL_API_TOKEN';
const CONFIG_KEY = 'services.hostinger_mail.token';
$APP = getenv('QA_APP') ?: (is_dir(QA_DOMAIN_ROOT.'/laravel-admin') ? QA_DOMAIN_ROOT.'/laravel-admin' : dirname(__DIR__, 2));

function out(array $data, int $exit = 0): never
{
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE), "\n";
    exit($exit);
}

/** A value's fingerprint: never the value. Keyed so the digest cannot be matched against a rainbow table of tokens. */
function fingerprint(?string $value): array
{
    if ($value === null) {
        return ['present' => false];
    }

    return [
        'present' => $value !== '',
        'length' => strlen($value),
        'sha8' => $value === '' ? null : substr(hash_hmac('sha256', $value, 'mail-token-doctor'), 0, 8),
    ];
}

/**
 * What one .env file says about the key, by shape alone: whether the line exists, whether it is commented out, whether
 * the value is wrapped in quotes or padded, and whether the line ends with a stray carriage return.
 */
function envFileFacts(string $path): array
{
    if (! is_file($path)) {
        return ['path' => $path, 'exists' => false];
    }
    $raw = (string) file_get_contents($path);
    $facts = [
        'path' => $path,
        'exists' => true,
        'bytes' => strlen($raw),
        'modified_utc' => gmdate('c', (int) filemtime($path)),
        'key_lines' => 0,
        'commented_out' => false,
    ];
    $value = null;
    foreach (preg_split('/\n/', $raw) ?: [] as $line) {
        $bare = ltrim($line);
        if (str_starts_with($bare, '#') && str_contains($bare, KEY.'=')) {
            $facts['commented_out'] = true;

            continue;
        }
        if (! preg_match('/^'.KEY.'=(.*)$/', rtrim($line, "\r"), $m)) {
            continue;
        }
        $facts['key_lines']++;
        $rawValue = $m[1];
        $facts['line_has_trailing_cr'] = str_ends_with($line, "\r");
        $facts['value_quoted'] = (bool) preg_match('/^\s*["\'].*["\']\s*$/', $rawValue);
        $facts['value_padded'] = $rawValue !== trim($rawValue);
        // Laravel strips surrounding quotes and whitespace; compare what it would actually use.
        $value = trim($rawValue);
        if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && $value[-1] === $value[0]) {
            $value = substr($value, 1, -1);
        }
    }
    $facts['value'] = fingerprint($value);

    // The NAMES of every variable the file defines — never a value. A key that is simply spelled differently from the
    // one the code reads is the likeliest cause of "it is there but the application cannot see it", and only the names
    // can show that.
    $names = [];
    foreach (preg_split('/\n/', $raw) ?: [] as $line) {
        if (preg_match('/^\s*([A-Za-z_][A-Za-z0-9_]*)=/', $line, $m) === 1) {
            $names[] = $m[1];
        }
    }
    sort($names);
    $facts['variable_names'] = $names;
    $facts['mail_related_names'] = array_values(array_filter($names, fn (string $n) => preg_match('/MAIL|HOSTINGER|TOKEN|SECRET/i', $n) === 1));

    return $facts;
}

$mode = $argv[1] ?? 'doctor';
if ($mode !== 'doctor') {
    out(['error' => 'modes: doctor'], 2);
}

// ---------------------------------------------------------------------------------------------- the running application
define('LARAVEL_START', microtime(true));
define('LARAVEL_PUBLIC_PATH_OVERRIDE', $APP.'/public');
require $APP.'/vendor/autoload.php';
$laravel = require $APP.'/bootstrap/app.php';
$laravel->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$configValue = config(CONFIG_KEY);
$configValue = is_string($configValue) ? $configValue : null;
$cachePath = $APP.'/bootstrap/cache/config.php';
$cacheExists = is_file($cachePath);

// The cached configuration is a plain PHP array file: load it WITHOUT the application, so what the cache really holds
// can be compared with what the file on disk says, independently of which one the app happened to use.
$cachedValue = null;
if ($cacheExists) {
    $cached = require $cachePath;
    $cachedValue = $cached['services']['hostinger_mail']['token'] ?? null;
    $cachedValue = is_string($cachedValue) ? $cachedValue : null;
}

$appEnvFile = envFileFacts($APP.'/.env');

// Every other .env on the server that a person might reasonably have edited instead.
$others = [];
foreach ([
    'live_app_symlink_target' => (is_link($APP) ? readlink($APP) : $APP).'/.env',
    'domain_root' => QA_DOMAIN_ROOT.'/.env',
    'releases_shared' => QA_DOMAIN_ROOT.'/laravel-admin-releases/.env',
    'public_docroot' => QA_DOMAIN_ROOT.'/public_html/admin/.env',
] as $label => $path) {
    $facts = envFileFacts($path);
    if (($facts['exists'] ?? false) && realpath($path) !== realpath($APP.'/.env')) {
        $others[$label] = $facts;
    }
}

// The release directories, newest first: which of them carry the key at all. A release keeps the .env it was given when
// it was staged, so a value added to the live app AFTER a stage is simply not in the next release.
$releases = [];
$releaseRoot = QA_DOMAIN_ROOT.'/laravel-admin-releases';
foreach (glob($releaseRoot.'/*/app/.env') ?: [] as $path) {
    $facts = envFileFacts($path);
    $releases[basename(dirname(dirname($path)))] = [
        'key_lines' => $facts['key_lines'] ?? 0,
        'value' => $facts['value'] ?? ['present' => false],
        'env_modified_utc' => $facts['modified_utc'] ?? null,
    ];
}
krsort($releases);

$fileValuePresent = (bool) ($appEnvFile['value']['present'] ?? false);
$configPresent = $configValue !== null && $configValue !== '';
$agree = ($appEnvFile['value']['sha8'] ?? null) !== null && ($appEnvFile['value']['sha8'] ?? null) === (fingerprint($configValue)['sha8'] ?? null);

out([
    'mode' => 'doctor',
    'checked_at_utc' => gmdate('c'),
    'app' => [
        'base_path' => $APP,
        'resolved_path' => realpath($APP),
        'is_symlink' => is_link($APP),
        'env' => (string) config('app.env'),
        'expects_env_variable' => KEY,
        'expects_config_key' => CONFIG_KEY,
    ],
    'env_file' => $appEnvFile,
    'config' => [
        'value' => fingerprint($configValue),
        'is_cached' => $cacheExists,
        'cache_modified_utc' => $cacheExists ? gmdate('c', (int) filemtime($cachePath)) : null,
        'cached_value' => fingerprint($cachedValue),
    ],
    // The verdict, in the terms the question was asked in.
    'verdict' => [
        'token_in_env_file' => $fileValuePresent,
        'token_visible_to_application' => $configPresent,
        'file_and_application_agree' => $agree,
        'reason' => match (true) {
            ! $fileValuePresent && ! $configPresent => 'the key is absent from the .env this application reads, so nothing could load it',
            $fileValuePresent && ! $configPresent && $cacheExists => 'STALE CONFIGURATION CACHE: the .env holds the key but the cached configuration, built earlier, does not — rebuild it',
            $fileValuePresent && ! $configPresent => 'the .env holds the key but the application does not expose it (name, quoting or loading)',
            $fileValuePresent && $configPresent && ! $agree => 'both hold a value but they are DIFFERENT: the cache was built from another .env',
            default => 'the application loads the key from its own .env',
        },
    ],
    'other_env_files_on_the_server' => $others,
    'release_env_copies' => array_slice($releases, 0, 6, true),
    'webhook_secret' => fingerprint(is_string(config('services.hostinger_mail.webhook_secret')) ? config('services.hostinger_mail.webhook_secret') : null),
]);
