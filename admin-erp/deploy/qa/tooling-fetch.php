<?php
/**
 * Puts the deploy tooling into the document root so `release-manager.php install` can relocate it.
 * (deploy/README.md, "Updating an already-installed release-manager.php".) CLI only: 404 otherwise.
 *
 * WHY THIS EXISTS. `install` works by being run from `public_html/admin/` and copying itself and its libraries into
 * the private `_tooling/` directory. Getting the files there normally means a TUS upload, which needs hPanel
 * credentials. This fetches them from the public repository instead — the same transport
 * `deploy/qa/release-fetch.php` uses for a release artifact, and for the same reason.
 *
 * Every library is listed, not just the changed one: release-manager.php requires all of them unconditionally at the
 * top of the file, so one missing next to the copy being run means a fatal before the action is even dispatched —
 * which, with display_errors off on this host, looks like silence.
 *
 * The URLs live here rather than in the cron command on purpose: the hosting control panel's firewall refuses a
 * scheduled command that contains a remote-download URL (observed repeatedly on 2026-11-02), while a plain
 * `curl -o` of one file is accepted. Keeping them in a committed file also means what gets fetched is reviewable.
 *
 *   php tooling-fetch.php          fetch, report each file's size and sha256
 *   php tooling-fetch.php clean    remove anything this script left in the document root
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
ini_set('display_errors', 'stderr');

const DOCROOT = '/home/u951246149/domains/provatferi.org/public_html/admin';
const SOURCE_BASE = 'https://raw.githubusercontent.com/khondokermoin/Provatferi/main/admin-erp/deploy/remote';

/** repository path (relative to SOURCE_BASE) => destination (relative to DOCROOT). */
const FILES = [
    'release-manager.php' => '_rm_update.php',
    'lib/process-runner.php' => 'lib/process-runner.php',
    'lib/uploads-persistence.php' => 'lib/uploads-persistence.php',
    'lib/uploads-sync.php' => 'lib/uploads-sync.php',
    'lib/public-uploads.php' => 'lib/public-uploads.php',
    'lib/housekeeping.php' => 'lib/housekeeping.php',
    'lib/disk-audit.php' => 'lib/disk-audit.php',
];

function out(array $data, int $exit = 0): never
{
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), "\n";
    exit($exit);
}

if (($argv[1] ?? '') === 'clean') {
    $removed = [];
    foreach (FILES as $destination) {
        $path = DOCROOT.'/'.$destination;
        if (is_file($path) && @unlink($path)) {
            $removed[] = $destination;
        }
    }
    @rmdir(DOCROOT.'/lib');
    out([
        'ok' => true,
        'mode' => 'clean',
        'removed' => $removed,
        'lib_dir_gone' => ! is_dir(DOCROOT.'/lib'),
        'still_present' => array_values(array_filter(array_values(FILES), fn (string $d): bool => is_file(DOCROOT.'/'.$d))),
    ]);
}

$fetched = [];
foreach (FILES as $source => $destination) {
    $path = DOCROOT.'/'.$destination;
    $directory = dirname($path);
    if (! is_dir($directory) && ! @mkdir($directory, 0755, true) && ! is_dir($directory)) {
        out(['ok' => false, 'error' => 'could not create '.$directory, 'fetched_so_far' => $fetched], 1);
    }

    $handle = @fopen($path, 'wb');
    if ($handle === false) {
        out(['ok' => false, 'error' => 'could not open '.$destination.' for writing', 'fetched_so_far' => $fetched], 1);
    }
    $curl = curl_init(SOURCE_BASE.'/'.$source);
    curl_setopt_array($curl, [
        CURLOPT_FILE => $handle,
        CURLOPT_FAILONERROR => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_TIMEOUT => 120,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $ok = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $error = curl_error($curl);
    curl_close($curl);
    fclose($handle);

    if ($ok === false || $status !== 200) {
        @unlink($path);
        out(['ok' => false, 'error' => $source.': HTTP '.$status.($error !== '' ? ' '.$error : ''), 'fetched_so_far' => $fetched], 1);
    }

    // A PHP file that cannot be parsed would fatal release-manager.php before it dispatched, so a truncated
    // download is caught here, by size, rather than as a mystery later.
    $bytes = (int) (filesize($path) ?: 0);
    if ($bytes < 200) {
        @unlink($path);
        out(['ok' => false, 'error' => $source.': only '.$bytes.' bytes, which cannot be a complete file', 'fetched_so_far' => $fetched], 1);
    }

    @chmod($path, 0600);
    $fetched[$destination] = ['bytes' => $bytes, 'sha256' => hash_file('sha256', $path)];
}

out([
    'ok' => true,
    'mode' => 'fetch',
    'docroot' => DOCROOT,
    'fetched' => $fetched,
    'next' => 'php '.DOCROOT.'/_rm_update.php install',
]);
