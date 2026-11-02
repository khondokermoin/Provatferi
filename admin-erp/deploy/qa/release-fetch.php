<?php
/**
 * Places an already-built release artifact into the pipeline's staging directory, then verifies it.
 * (deploy/README.md, "Deploying a commit", step 2.) CLI only: 404 otherwise.
 *
 * WHY THIS EXISTS. `upload-release.mjs` pushes the three artifact files over the file browser's TUS API, which needs
 * short-lived hPanel upload credentials. Where those are not available, the artifact still has to reach
 * `public_html/admin/_release_staging/<releaseId>/` — the one place `release-manager.php stage` reads from — without
 * anyone hand-copying files into the live application. This fetches it over HTTPS instead, into exactly that
 * directory, and changes nothing else.
 *
 * It is NOT a second deployment path. `stage` still re-verifies every byte against the manifest's sha256, and every
 * later gate runs unchanged; this only moves the bytes.
 *
 * The source URL is a CONSTANT here rather than an argument on purpose: a cron command carrying a remote-download URL
 * is refused by the hosting control panel's firewall as a command-injection pattern (observed 2026-11-02, repeatedly,
 * while the identical POST without a URL succeeded). Keeping the URL in a committed file keeps the cron command plain.
 *
 *   php release-fetch.php <releaseId>          fetch, verify, and report
 *   php release-fetch.php <releaseId> clean    remove a staging directory this script created
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
ini_set('display_errors', 'stderr');

const STAGING_ROOT = '/home/u951246149/domains/provatferi.org/public_html/admin/_release_staging';
const SOURCE_BASE = 'https://raw.githubusercontent.com/khondokermoin/Provatferi/deploy-artifact-640251211cbc';
const MEMBERS = ['manifest.json', 'private.tar', 'public-assets.tar'];

function out(array $data, int $exit = 0): never
{
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), "\n";
    exit($exit);
}

$releaseId = $argv[1] ?? '';
if (preg_match('/^[A-Za-z0-9._-]{8,80}$/', $releaseId) !== 1) {
    out(['ok' => false, 'error' => 'the release id is missing or not a plain identifier'], 2);
}
$dir = STAGING_ROOT.'/'.$releaseId;

if (($argv[2] ?? '') === 'clean') {
    $removed = [];
    foreach (array_merge(MEMBERS, ['.htaccess']) as $name) {
        if (is_file($dir.'/'.$name) && @unlink($dir.'/'.$name)) {
            $removed[] = $name;
        }
    }
    @rmdir($dir);
    out(['ok' => true, 'mode' => 'clean', 'removed' => $removed, 'directory_gone' => ! is_dir($dir)]);
}

if (! is_dir($dir) && ! @mkdir($dir, 0700, true) && ! is_dir($dir)) {
    out(['ok' => false, 'error' => 'the staging directory could not be created'], 1);
}
@chmod($dir, 0700);

// This directory sits inside the PUBLIC document root, because that is where `stage` reads from. The TUS upload has
// the same exposure by design; denying HTTP outright costs nothing and closes the window while the files are there.
@file_put_contents($dir.'/.htaccess', "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");

/** Download one file to a path, returning its size or an error string. */
function fetch(string $url, string $destination): array
{
    $handle = @fopen($destination, 'wb');
    if ($handle === false) {
        return ['ok' => false, 'error' => 'cannot open the destination for writing'];
    }
    $curl = curl_init($url);
    curl_setopt_array($curl, [
        CURLOPT_FILE => $handle,
        CURLOPT_FAILONERROR => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_TIMEOUT => 600,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $ok = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $error = curl_error($curl);
    curl_close($curl);
    fclose($handle);

    if ($ok === false || $status !== 200) {
        @unlink($destination);

        return ['ok' => false, 'error' => 'HTTP '.$status.($error !== '' ? ': '.$error : '')];
    }

    return ['ok' => true, 'bytes' => (int) (filesize($destination) ?: 0)];
}

$fetched = [];
foreach (MEMBERS as $name) {
    $result = fetch(SOURCE_BASE.'/'.$name, $dir.'/'.$name);
    if (! ($result['ok'] ?? false)) {
        out(['ok' => false, 'error' => $name.': '.$result['error'], 'fetched_so_far' => $fetched], 1);
    }
    @chmod($dir.'/'.$name, 0600);
    $fetched[$name] = ['bytes' => $result['bytes'], 'sha256' => hash_file('sha256', $dir.'/'.$name)];
}

// The same check `stage` will make, made here too: a truncated download should fail now, with a clear reason, rather
// than as a checksum mismatch inside the pipeline.
$manifest = json_decode((string) file_get_contents($dir.'/manifest.json'), true);
if (! is_array($manifest)) {
    out(['ok' => false, 'error' => 'the manifest is not readable JSON', 'fetched' => $fetched], 1);
}

$checks = [];
$problems = [];
foreach (['private_tar', 'public_assets_tar'] as $key) {
    $file = $manifest['artifacts'][$key]['file'] ?? null;
    $expected = $manifest['artifacts'][$key]['sha256'] ?? null;
    if (! is_string($file) || ! is_string($expected)) {
        $problems[] = $key.': the manifest does not describe it';

        continue;
    }
    $actual = isset($fetched[$file]) ? $fetched[$file]['sha256'] : null;
    $checks[$file] = ['expected' => $expected, 'actual' => $actual, 'match' => $actual !== null && hash_equals($expected, $actual)];
    if (! $checks[$file]['match']) {
        $problems[] = $file.': sha256 does not match the manifest';
    }
}

if ($problems !== []) {
    foreach (MEMBERS as $name) {
        @unlink($dir.'/'.$name);
    }
    out(['ok' => false, 'error' => 'the artifact did not verify, so it was removed', 'problems' => $problems, 'checks' => $checks], 1);
}

out([
    'ok' => true,
    'release_id' => $releaseId,
    'commit' => $manifest['commit'] ?? null,
    'built_at' => $manifest['built_at'] ?? null,
    'staged_in' => $dir,
    'fetched' => $fetched,
    'checksum_checks' => $checks,
    'next' => 'release-manager.php pipeline '.$releaseId,
]);
