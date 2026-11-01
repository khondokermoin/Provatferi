<?php

namespace App\Services\Backup;

use Throwable;

/**
 * One backup run, end to end (docs/BACKUP_AND_DISASTER_RECOVERY.md).
 *
 * ORDER, and why: databases first (they are the smallest and the most valuable, so if the run is going to fail it
 * should fail having already secured them), then the upload trees, then the encrypted configuration bundle, then the
 * manifest. The whole set is written into `sets/.tmp-<id>/` and renamed into `sets/<id>/` only once the manifest is
 * there — so an interrupted run can never leave something that looks like a finished backup.
 *
 * OVERLAP: an exclusive, non-blocking lock is taken before anything is written. A second run finding the lock held
 * exits without touching anything. If the lock has been held longer than the configured staleness, that is reported
 * as a FAILURE rather than a quiet skip, because a backup hung for hours is exactly the kind of silence this system
 * exists to break.
 */
final class BackupRunner
{
    /** @var resource|null */
    private $lockHandle = null;

    /** @param array<string, mixed> $config */
    public function __construct(
        private readonly array $config,
        private readonly BackupStore $store,
        private readonly DatabaseDumper $dumper,
        private readonly FileSetArchiver $archiver,
        private readonly ConfigVault $vault,
    ) {
    }

    /**
     * @return array<string, mixed> the manifest, plus `skipped` when another run held the lock
     */
    public function run(): array
    {
        $started = microtime(true);
        $this->store->ensureRoot($this->documentRoots());

        $lock = $this->acquireLock();
        if ($lock !== null) {
            return $lock;
        }

        // A run that was killed (a deploy, a reboot, an operator's Ctrl-C) leaves its `.tmp-` directory behind. It is
        // harmless — nothing reads it — but it would accumulate, so abandoned ones are swept once the lock is ours,
        // which is the only moment it is certainly safe to do.
        $swept = $this->sweepAbandoned();

        $id = $this->store->newSetId();
        $previous = $this->store->setIds()[0] ?? null;
        $previousDir = $previous !== null ? $this->store->setPath($previous) : null;
        $tmp = $this->store->setsDir().'/.tmp-'.$id;

        $manifest = [
            'ok' => false,
            'id' => $id,
            'started_utc' => gmdate('c'),
            'app' => [
                'env' => (string) config('app.env'),
                'commit' => $this->deployedCommit(),
                'php' => PHP_VERSION,
            ],
            'previous_set' => $previous,
            'databases' => [],
            'file_sets' => [],
            'config_bundle' => [],
            'artifacts' => [],
            'warnings' => [],
            'abandoned_sets_swept' => $swept,
        ];

        try {
            if (! is_dir($tmp) && ! @mkdir($tmp, 0700, true) && ! is_dir($tmp)) {
                throw new \RuntimeException('the set directory could not be created');
            }
            @chmod($tmp, 0700);

            $manifest = $this->dumpDatabases($manifest, $tmp);
            $manifest = $this->archiveFileSets($manifest, $tmp, $previousDir);
            $manifest = $this->writeConfigBundle($manifest, $tmp);

            $failed = [];
            foreach ($manifest['databases'] as $label => $result) {
                if (($result['ok'] ?? false) !== true) {
                    $failed[] = "database {$label}: ".($result['error'] ?? 'failed');
                }
            }
            foreach ($manifest['file_sets'] as $label => $result) {
                if (($result['ok'] ?? false) !== true) {
                    $failed[] = "file set {$label}: ".($result['error'] ?? 'failed');
                }
            }

            $manifest['ok'] = $failed === [];
            $manifest['error'] = $failed === [] ? null : implode('; ', $failed);
            $manifest['bytes'] = $this->store->directoryBytes($tmp);
            $manifest['seconds'] = round(microtime(true) - $started, 2);
            $manifest['finished_utc'] = gmdate('c');

            $this->store->writeManifest($tmp, $manifest);

            // Atomic publication: until this rename, nothing in `sets/` looks like a complete set.
            $final = $this->store->setPath($id);
            if (! @rename($tmp, $final)) {
                throw new \RuntimeException('the finished set could not be moved into place');
            }

            $verification = $this->store->verify($id, $this->dumper);
            $manifest['verification'] = $verification;
            if (! $verification['ok']) {
                $manifest['ok'] = false;
                $manifest['error'] = trim((string) ($manifest['error'] ?? '').' verification: '.implode('; ', $verification['problems']), ' ');
            }
            $this->store->writeManifest($final, $manifest);

            $manifest['retention'] = $this->store->prune(true, $this->dumper);
        } catch (Throwable $e) {
            $manifest['ok'] = false;
            $manifest['error'] = $e->getMessage();
            $manifest['seconds'] = round(microtime(true) - $started, 2);
            $manifest['finished_utc'] = gmdate('c');
            if (is_dir($tmp)) {
                // A half-written set is removed rather than left to confuse the next run.
                $this->store->removeDirectory($tmp);
            }
        } finally {
            $this->releaseLock();
        }

        $this->store->recordRun($manifest);

        return $manifest;
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return array<string, mixed>
     */
    private function dumpDatabases(array $manifest, string $tmp): array
    {
        foreach ((array) ($this->config['databases'] ?? []) as $label => $spec) {
            if (! is_string($label) || ! is_array($spec)) {
                continue;
            }
            $credentials = $this->credentialsFor($spec);
            if ($credentials === null) {
                $manifest['databases'][$label] = ['ok' => true, 'skipped' => 'no readable configuration for this database'];

                continue;
            }

            $name = "db-{$label}.sql.gz";
            $result = $this->dumper->dump($credentials, $tmp.'/'.$name);
            // The credentials array must not survive into the manifest in any form.
            unset($credentials);

            $manifest['databases'][$label] = $result;
            if ($result['ok']) {
                @chmod($tmp.'/'.$name, 0600);
                $manifest['artifacts'][$name] = [
                    'kind' => 'database',
                    'label' => $label,
                    'bytes' => $result['bytes'],
                    'uncompressed_bytes' => $result['uncompressed_bytes'],
                    'tables' => $result['tables'],
                    'sha256' => (string) hash_file('sha256', $tmp.'/'.$name),
                ];
            }
        }

        return $manifest;
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return array<string, mixed>
     */
    private function archiveFileSets(array $manifest, string $tmp, ?string $previousDir): array
    {
        foreach ((array) ($this->config['file_sets'] ?? []) as $label => $source) {
            if (! is_string($label) || ! is_string($source)) {
                continue;
            }
            $archiveName = "files-{$label}.tar.gz";
            $indexName = "files-{$label}.index.json";

            $result = $this->archiver->archive($source, $tmp.'/'.$archiveName, $tmp.'/'.$indexName, $previousDir, $label);
            $manifest['file_sets'][$label] = $result;

            if ($result['skipped_symlinks'] !== []) {
                $manifest['warnings'][] = "file set {$label}: ".count($result['skipped_symlinks']).' symlink(s) skipped';
            }
            if ($result['unreadable'] !== []) {
                $manifest['warnings'][] = "file set {$label}: ".count($result['unreadable']).' file(s) could not be read';
            }
            if (! $result['ok']) {
                continue;
            }

            @chmod($tmp.'/'.$indexName, 0600);
            $manifest['artifacts'][$indexName] = [
                'kind' => 'index',
                'label' => $label,
                'bytes' => (int) (filesize($tmp.'/'.$indexName) ?: 0),
                'sha256' => (string) hash_file('sha256', $tmp.'/'.$indexName),
            ];
            if ($result['present']) {
                @chmod($tmp.'/'.$archiveName, 0600);
                $manifest['artifacts'][$archiveName] = [
                    'kind' => 'files',
                    'label' => $label,
                    'bytes' => $result['archive_bytes'],
                    'entries' => $result['files'],
                    'source_bytes' => $result['bytes'],
                    'reused_from_previous_set' => $result['reused'],
                    'sha256' => $result['archive_sha256'],
                ];
            }
        }

        return $manifest;
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return array<string, mixed>
     */
    private function writeConfigBundle(array $manifest, string $tmp): array
    {
        $files = [];
        foreach ((array) ($this->config['config_files'] ?? []) as $name => $path) {
            if (is_string($name) && is_string($path)) {
                $files[$name] = $path;
            }
        }

        $result = $this->vault->write($files, $tmp.'/config.vault');
        $manifest['config_bundle'] = $result;

        if (($result['ok'] ?? false) === true) {
            $manifest['artifacts']['config.vault'] = [
                'kind' => 'config',
                'bytes' => $result['bytes'],
                'sha256' => $result['sha256'],
                'members' => array_keys($result['members']),
                'encrypted' => 'aes-256-gcm',
            ];
        } else {
            // Not a failure: an unconfigured key is an owner action, and the rest of the backup is still valuable.
            $manifest['warnings'][] = 'configuration bundle: '.($result['skipped'] ?? 'not written');
        }

        return $manifest;
    }

    /**
     * The credentials for one configured database. The ERP's come from the live connection; a WordPress database's are
     * parsed out of its own wp-config.php. Nothing read here is ever logged, reported or returned to a caller.
     *
     * @param  array<string, mixed>  $spec
     * @return array{host?: string, port?: int|string, database: string, username?: string, password?: string}|null
     */
    private function credentialsFor(array $spec): ?array
    {
        if (($spec['driver'] ?? null) === 'laravel') {
            $connection = (string) ($spec['connection'] ?? config('database.default'));
            // Concatenated, not interpolated: deploy/config-contract.php reads a double-quoted key as a LITERAL path
            // and would fail the release for a key named `database.connections.{$connection}`.
            $db = config('database.connections.'.$connection);
            if (! is_array($db) || ! is_string($db['database'] ?? null)) {
                return null;
            }

            return [
                'host' => (string) ($db['host'] ?? '127.0.0.1'),
                'port' => $db['port'] ?? 3306,
                'database' => $db['database'],
                'username' => (string) ($db['username'] ?? ''),
                'password' => (string) ($db['password'] ?? ''),
            ];
        }

        if (($spec['driver'] ?? null) === 'wp-config') {
            $path = (string) ($spec['path'] ?? '');

            return $path !== '' ? $this->parseWpConfig($path) : null;
        }

        return null;
    }

    /**
     * Reads DB_NAME / DB_USER / DB_PASSWORD / DB_HOST out of a wp-config.php. Deliberately a narrow regex over a
     * `define()` call rather than including the file: including a PHP file from another application inside this
     * process would run its code.
     *
     * @return array{host: string, port: int|string, database: string, username: string, password: string}|null
     */
    private function parseWpConfig(string $path): ?array
    {
        if (! is_file($path) || ! is_readable($path) || is_link($path)) {
            return null;
        }
        $contents = (string) file_get_contents($path);
        $value = static function (string $constant) use ($contents): ?string {
            $pattern = "/define\s*\(\s*(['\"])".preg_quote($constant, '/')."\\1\s*,\s*(['\"])(.*?)\\2\s*\)/s";

            return preg_match($pattern, $contents, $m) === 1 ? $m[3] : null;
        };

        $database = $value('DB_NAME');
        if ($database === null || $database === '') {
            return null;
        }
        $host = $value('DB_HOST') ?? 'localhost';
        $port = 3306;
        if (str_contains($host, ':')) {
            [$host, $maybePort] = explode(':', $host, 2);
            $port = ctype_digit($maybePort) ? (int) $maybePort : 3306;
        }

        return [
            'host' => $host !== '' ? $host : 'localhost',
            'port' => $port,
            'database' => $database,
            'username' => (string) ($value('DB_USER') ?? ''),
            'password' => (string) ($value('DB_PASSWORD') ?? ''),
        ];
    }

    /** @return list<string> */
    private function documentRoots(): array
    {
        $roots = [public_path()];
        $siteRoot = (string) ($this->config['site_root'] ?? '');
        if ($siteRoot !== '') {
            $roots[] = $siteRoot.'/public_html';
        }

        return array_values(array_filter($roots));
    }

    /** The commit this release was built from, when the deploy tooling recorded one. Useful when reading an old set. */
    private function deployedCommit(): ?string
    {
        foreach ([base_path('deploy/state/last-deployed.json'), base_path('../laravel-admin-releases/CURRENT_RELEASE.json')] as $path) {
            if (! is_file($path)) {
                continue;
            }
            $decoded = json_decode((string) file_get_contents($path), true);
            $commit = is_array($decoded) ? ($decoded['commit'] ?? $decoded['release_id'] ?? null) : null;
            if (is_string($commit) && $commit !== '') {
                return substr($commit, 0, 40);
            }
        }

        return null;
    }

    /**
     * Removes `.tmp-` set directories left by runs that died. Only ever called while this run holds the lock, so no
     * live run's directory can be taken away from under it.
     *
     * @return list<string>
     */
    private function sweepAbandoned(): array
    {
        $swept = [];
        foreach ((array) glob($this->store->setsDir().'/.tmp-*') as $path) {
            if (! is_string($path) || ! is_dir($path)) {
                continue;
            }
            if (! BackupPaths::isInside($path, $this->store->setsDir())) {
                continue;
            }
            $this->store->removeDirectory($path);
            $swept[] = basename($path);
        }

        return $swept;
    }

    /** @return array<string, mixed>|null a result when the run must not proceed, null when the lock is ours */
    private function acquireLock(): ?array
    {
        $path = $this->store->lockPath();
        $claimPath = $this->store->lockClaimPath();

        // How long the CURRENT holder has held it, from the claim file the holder writes. Not the lock file's own
        // mtime (that survives every completed run, so a brief overlap would look like a hung one) and not the lock
        // file's contents (Windows refuses to read a file somebody holds an exclusive lock on). A finished run empties
        // the claim, so an empty claim means nobody has started yet.
        $age = 0;
        if (is_file($claimPath)) {
            $claim = json_decode(trim((string) @file_get_contents($claimPath)), true);
            $startedUtc = is_array($claim) && is_string($claim['started_utc'] ?? null) ? strtotime($claim['started_utc']) : false;
            $age = $startedUtc !== false ? max(0, time() - $startedUtc) : 0;
        }

        $handle = fopen($path, 'c');
        if ($handle === false) {
            return ['ok' => false, 'id' => null, 'error' => 'the lock file could not be opened', 'finished_utc' => gmdate('c')];
        }
        if (! flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            $stale = (int) ($this->config['lock_stale_after_seconds'] ?? 7200);

            if ($age > $stale) {
                // Held for hours. The OS releases a dead process's lock, so this is a live run that is stuck.
                return [
                    'ok' => false, 'id' => null, 'finished_utc' => gmdate('c'),
                    'error' => "another backup run has held the lock for {$age}s (longer than {$stale}s): it appears to be stuck",
                ];
            }

            return [
                'ok' => true, 'id' => null, 'skipped' => 'another backup run holds the lock', 'lock_age_seconds' => $age,
                'finished_utc' => gmdate('c'),
            ];
        }

        @chmod($path, 0600);
        file_put_contents($claimPath, json_encode(['pid' => getmypid(), 'started_utc' => gmdate('c')])."\n");
        @chmod($claimPath, 0600);
        $this->lockHandle = $handle;

        return null;
    }

    private function releaseLock(): void
    {
        if ($this->lockHandle !== null) {
            // The claim is emptied before unlocking, so the next run can tell "held by a live holder" from "left over
            // from a run that finished". Without this an old claim would make a momentary overlap look stuck.
            @file_put_contents($this->store->lockClaimPath(), '');
            flock($this->lockHandle, LOCK_UN);
            fclose($this->lockHandle);
            $this->lockHandle = null;
        }
    }
}
