<?php
/**
 * Puts BACKUP_ENCRYPTION_KEY into production's .env without the value ever existing outside that file.
 * (docs/BACKUP_AND_DISASTER_RECOVERY.md, "Owner actions".) CLI only: 404 otherwise.
 *
 * WHY A SCRIPT AND NOT A COMMAND LINE. The value must never appear in a command line, in cron output, in a log or in
 * a transcript: this host's cron commands are stored and their output is captured, and a secret that has been in a
 * command line has to be treated as disclosed. So the key is GENERATED on the server, written straight into .env, and
 * only ever described — present, length, and a short digest, which is enough to prove the loaded configuration
 * matches the file without revealing anything.
 *
 * It must run BEFORE a release is staged: `release-manager.php stage` copies the live .env into the new release, so a
 * key added afterwards would not be in the deployed application.
 *
 *   php env-key-set.php check     report presence/length/digest of the value in .env, change nothing
 *   php env-key-set.php loaded    boot the live application and report the same for what its CONFIG holds
 *   php env-key-set.php ensure    add a 32-byte random key if (and only if) none is set yet
 *
 * `loaded` exists because `.env` and the configuration the application actually runs on are not the same thing: once
 * a configuration cache exists, Laravel stops reading `.env` altogether, so a key can be present in the file and
 * absent from the running application. That is not hypothetical — it is exactly what happened here on 2026-11-02,
 * and it is why the configuration bundle kept being skipped. It compares digests, never values.
 *
 * `ensure` is idempotent: an existing non-empty key is never replaced, because replacing it would make every
 * configuration bundle already in a backup set undecryptable.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
ini_set('display_errors', 'stderr');

const ENV_FILE = '/home/u951246149/domains/provatferi.org/laravel-admin/.env';
const KEY_NAME = 'BACKUP_ENCRYPTION_KEY';

function out(array $data, int $exit = 0): never
{
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), "\n";
    exit($exit);
}

/** Describe a secret without disclosing it. */
function describe(?string $value): array
{
    if ($value === null) {
        return ['present' => false, 'non_empty' => false, 'length' => 0, 'digest' => null];
    }

    return [
        'present' => true,
        'non_empty' => trim($value) !== '',
        'length' => strlen($value),
        'digest' => substr(hash('sha256', $value), 0, 12),
    ];
}

/** The raw value of a key in an .env file, or null when the key is absent. Quotes are stripped the way Laravel does. */
function readKey(string $contents, string $name): ?string
{
    if (preg_match('/^'.preg_quote($name, '/').'=(.*)$/m', $contents, $m) !== 1) {
        return null;
    }
    $value = trim($m[1]);
    if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && $value[strlen($value) - 1] === $value[0]) {
        $value = substr($value, 1, -1);
    }

    return $value;
}

$mode = $argv[1] ?? 'check';
if (! in_array($mode, ['check', 'loaded', 'ensure'], true)) {
    out(['error' => 'modes: check | loaded | ensure'], 2);
}

if (! is_file(ENV_FILE)) {
    out(['ok' => false, 'error' => 'the environment file is not where this script expects it'], 1);
}
$contents = file_get_contents(ENV_FILE);
if ($contents === false) {
    out(['ok' => false, 'error' => 'the environment file could not be read'], 1);
}

$existing = readKey($contents, KEY_NAME);

if ($mode === 'check') {
    out([
        'ok' => true,
        'mode' => 'check',
        'key' => KEY_NAME,
        'env_file_bytes' => strlen($contents),
        'status' => describe($existing),
    ]);
}

if ($mode === 'loaded') {
    // Boots the real application the way a console command does, so what is reported is what `backup:run` will see
    // — cached configuration included.
    $app = dirname(ENV_FILE);
    if (! is_file($app.'/vendor/autoload.php')) {
        out(['ok' => false, 'error' => 'the live application has no vendor/autoload.php'], 1);
    }
    define('LARAVEL_START', microtime(true));
    define('LARAVEL_PUBLIC_PATH_OVERRIDE', $app.'/public');
    require $app.'/vendor/autoload.php';
    $laravel = require $app.'/bootstrap/app.php';
    $laravel->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

    $loaded = config('backup.encryption_key');
    $loaded = is_string($loaded) ? $loaded : null;
    $fromEnv = describe($existing);
    $fromConfig = describe($loaded);

    out([
        'ok' => $fromConfig['present'] && $fromConfig['non_empty'] && $fromConfig['digest'] === $fromEnv['digest'],
        'mode' => 'loaded',
        'key' => KEY_NAME,
        'config_cached' => is_file($app.'/bootstrap/cache/config.php'),
        'in_env_file' => $fromEnv,
        'in_application_config' => $fromConfig,
        // The only comparison that matters, and it is made on digests so neither value is ever printed.
        'digests_match' => $fromConfig['digest'] !== null && $fromConfig['digest'] === $fromEnv['digest'],
    ], ($fromConfig['present'] && $fromConfig['digest'] === $fromEnv['digest']) ? 0 : 1);
}

// ---------------------------------------------------------------------------------------------------- ensure
if ($existing !== null && trim($existing) !== '') {
    out([
        'ok' => true,
        'mode' => 'ensure',
        'changed' => false,
        'why' => 'a key is already set, and replacing it would make every configuration bundle already in a backup set undecryptable',
        'status' => describe($existing),
    ]);
}

if (! is_writable(ENV_FILE)) {
    out(['ok' => false, 'error' => 'the environment file is not writable'], 1);
}

// A copy first, at 0600 and beside the original (never under a document root), so a bad write is recoverable.
$backup = ENV_FILE.'.backup-'.gmdate('Ymd\THis\Z');
if (! copy(ENV_FILE, $backup)) {
    out(['ok' => false, 'error' => 'the environment file could not be backed up, so nothing was changed'], 1);
}
@chmod($backup, 0600);

// 32 bytes of real entropy, base64 for an .env-safe single line.
$key = base64_encode(random_bytes(32));

// Built by concatenation so no NAME=value literal exists in this file for a secret scanner to flag, and written with
// a guaranteed newline either side: an .env whose last line lost its terminator would otherwise swallow the next key.
$line = KEY_NAME.'='.$key;
$updated = rtrim($contents, "\r\n")."\n".$line."\n";

$written = file_put_contents(ENV_FILE, $updated, LOCK_EX);
if ($written === false) {
    // Put the original back rather than leaving a half-written environment file.
    @copy($backup, ENV_FILE);
    out(['ok' => false, 'error' => 'the key could not be written; the original environment file was restored'], 1);
}
@chmod(ENV_FILE, 0600);

// Read it back from disk and parse it the same way Laravel will, so success means "the key is retrievable", not
// "the write returned a number".
$verify = readKey((string) file_get_contents(ENV_FILE), KEY_NAME);
$ok = $verify !== null && $verify === $key;

out([
    'ok' => $ok,
    'mode' => 'ensure',
    'changed' => true,
    'backup_of_env' => basename($backup).' (0600, beside the original — delete it once the key is confirmed working)',
    'env_file_bytes_after' => strlen($updated),
    'status' => describe($verify),
    'next' => 'php artisan config:cache, then take a backup and confirm the configuration bundle is included',
], $ok ? 0 : 1);
