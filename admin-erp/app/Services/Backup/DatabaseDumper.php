<?php

namespace App\Services\Backup;

use RuntimeException;

/**
 * Dumps one MariaDB database to a gzipped file (docs/BACKUP_AND_DISASTER_RECOVERY.md).
 *
 * CREDENTIAL SAFETY, which is the whole reason this class exists rather than a one-line exec:
 *   - the password is written to a `--defaults-extra-file` created at 0600 inside a private directory, and deleted in a
 *     `finally`, so it never appears in the command line, in `ps`, in cron output, in an exception or in a log;
 *   - the program is started with proc_open and an ARGUMENT ARRAY, so no shell parses anything and a path containing a
 *     quote or a semicolon cannot become a command;
 *   - stdout is streamed straight into the gzip file, so a large dump never sits in memory;
 *   - stderr is captured, and the message is scrubbed before it is ever returned (mysqldump echoes the defaults-file
 *     path, and some builds echo connection details).
 *
 * CONSISTENCY: `--single-transaction` takes the dump inside one transaction, so InnoDB tables are mutually consistent
 * WITHOUT locking anything — production keeps writing throughout. `--no-tablespaces` avoids needing the PROCESS
 * privilege, which a shared-hosting user does not have.
 */
/*
 * Not final, deliberately: it is the one piece that needs an external program and a live database, so the tests that
 * cover the orchestration around it substitute a stub. Everything else in this namespace is final.
 */
class DatabaseDumper
{
    public function __construct(
        private readonly string $binary = 'mysqldump',
        private readonly int $timeout = 900,
    ) {
    }

    /**
     * @param  array{host?: string, port?: int|string, database: string, username?: string, password?: string}  $credentials
     * @return array{ok: bool, bytes: int, uncompressed_bytes: int, tables: int, seconds: float, error?: string}
     */
    public function dump(array $credentials, string $destination): array
    {
        $database = (string) ($credentials['database'] ?? '');
        if ($database === '' || preg_match('/^[A-Za-z0-9_$-]+$/', $database) !== 1) {
            return ['ok' => false, 'bytes' => 0, 'uncompressed_bytes' => 0, 'tables' => 0, 'seconds' => 0.0, 'error' => 'the database name is missing or not a plain identifier'];
        }

        $defaults = $this->writeDefaultsFile($credentials);
        $started = microtime(true);

        try {
            $command = [
                $this->binary,
                '--defaults-extra-file='.$defaults,
                '--single-transaction',
                '--quick',
                '--routines',
                '--triggers',
                '--events',
                '--no-tablespaces',
                '--default-character-set=utf8mb4',
                '--skip-lock-tables',
                $database,
            ];

            // stderr goes to a FILE, not a second pipe. Two pipes would have to be drained in lockstep or the child
            // blocks when one fills — and stream_select(), the usual way to do that, does not work on pipes on
            // Windows, so a two-pipe loop cannot be both portable and deadlock-free. One pipe can simply be read to
            // the end.
            $errorLog = dirname($defaults).'/stderr.log';
            $descriptors = [1 => ['pipe', 'w'], 2 => ['file', $errorLog, 'w']];
            $process = proc_open($command, $descriptors, $pipes);
            if (! is_resource($process)) {
                return $this->failure('mysqldump could not be started (is it installed?)', $started);
            }

            $gz = gzopen($destination, 'wb6');
            if ($gz === false) {
                @fclose($pipes[1]);
                proc_close($process);

                return $this->failure('the dump file could not be opened for writing', $started);
            }

            $uncompressed = 0;
            $deadline = microtime(true) + $this->timeout;
            $timedOut = false;

            while (! feof($pipes[1])) {
                $chunk = fread($pipes[1], 262144);
                if ($chunk === false) {
                    break;
                }
                if ($chunk !== '') {
                    gzwrite($gz, $chunk);
                    $uncompressed += strlen($chunk);
                }
                // Checked between chunks. A child that hangs having produced nothing at all is not caught here but by
                // the run lock going stale, which is reported as a failure (App\Services\Backup\BackupRunner).
                if (microtime(true) > $deadline) {
                    $timedOut = true;
                    proc_terminate($process);
                    break;
                }
            }

            gzclose($gz);
            @fclose($pipes[1]);
            $exit = proc_close($process);
            $stderr = is_file($errorLog) ? (string) file_get_contents($errorLog) : '';

            if ($timedOut) {
                return $this->failure('mysqldump timed out after '.$this->timeout.'s', $started);
            }
            if ($exit !== 0) {
                return $this->failure('mysqldump exited with status '.$exit.': '.$this->scrub($stderr, $defaults), $started);
            }
        } finally {
            // Before anything else can read it, and whatever happened above: overwritten, then removed, then the
            // whole private directory taken with it.
            if (is_file($defaults)) {
                @file_put_contents($defaults, str_repeat('0', (int) (filesize($defaults) ?: 1)));
                @unlink($defaults);
            }
            $errorLog = dirname($defaults).'/stderr.log';
            if (is_file($errorLog)) {
                @unlink($errorLog);
            }
            @rmdir(dirname($defaults));
        }

        $seconds = round(microtime(true) - $started, 2);
        $sanity = $this->sanity($destination, $uncompressed);
        if ($sanity !== null) {
            return ['ok' => false, 'bytes' => (int) (filesize($destination) ?: 0), 'uncompressed_bytes' => $uncompressed, 'tables' => 0, 'seconds' => $seconds, 'error' => $sanity];
        }

        return [
            'ok' => true,
            'bytes' => (int) (filesize($destination) ?: 0),
            'uncompressed_bytes' => $uncompressed,
            'tables' => $this->countTables($destination),
            'seconds' => $seconds,
        ];
    }

    /**
     * Why this dump must not be trusted, or null when it is sound. Exit status 0 is not evidence: a dump can be
     * truncated by a disk filling up, or be an empty file, and mysqldump will still have exited cleanly.
     */
    public function sanity(string $path, int $expectedUncompressed = 0): ?string
    {
        if (! is_file($path) || (filesize($path) ?: 0) === 0) {
            return 'the dump file is missing or empty';
        }

        // gzopen() happily reads a file that is NOT gzip, handing back the raw bytes — so a dump written
        // uncompressed by a broken pipeline would otherwise sail through every check below. Found by the test that
        // feeds this plain text under a .sql.gz name.
        $head = (string) @file_get_contents($path, false, null, 0, 2);
        if (strlen($head) < 2 || $head[0] !== "\x1f" || $head[1] !== "\x8b") {
            return 'the dump file is not gzip-compressed';
        }

        $gz = gzopen($path, 'rb');
        if ($gz === false) {
            return 'the dump file cannot be opened';
        }
        $bytes = 0;
        $tail = '';
        $sawCreateTable = false;
        while (! gzeof($gz)) {
            $chunk = gzread($gz, 262144);
            if ($chunk === false) {
                gzclose($gz);

                return 'the dump file is not readable gzip: it is corrupt';
            }
            $bytes += strlen($chunk);
            if (! $sawCreateTable && stripos($chunk, 'CREATE TABLE') !== false) {
                $sawCreateTable = true;
            }
            $tail = substr($tail.$chunk, -4096);
        }
        gzclose($gz);

        if ($bytes === 0) {
            return 'the dump decompresses to nothing';
        }
        if ($expectedUncompressed > 0 && $bytes !== $expectedUncompressed) {
            return 'the dump is shorter than what was written: it is truncated';
        }
        if (! $sawCreateTable) {
            return 'the dump contains no CREATE TABLE: it is not a usable schema';
        }
        // mysqldump writes this as its very last line. Its absence means the dump stopped early.
        if (stripos($tail, 'Dump completed') === false) {
            return 'the dump has no completion marker: mysqldump stopped before the end';
        }

        return null;
    }

    public function countTables(string $path): int
    {
        $gz = gzopen($path, 'rb');
        if ($gz === false) {
            return 0;
        }
        $count = 0;
        $carry = '';
        while (! gzeof($gz)) {
            $chunk = $carry.(string) gzread($gz, 262144);
            $count += preg_match_all('/^CREATE TABLE /mi', $chunk);
            $carry = substr($chunk, -32);   // a marker split across two reads is still counted once
        }
        gzclose($gz);

        return $count;
    }

    /** @param array<string, mixed> $credentials */
    private function writeDefaultsFile(array $credentials): string
    {
        $dir = sys_get_temp_dir().'/pf-backup-'.bin2hex(random_bytes(6));
        if (! @mkdir($dir, 0700, true) && ! is_dir($dir)) {
            throw new RuntimeException('cannot create a private directory for the credentials file');
        }
        @chmod($dir, 0700);
        $path = $dir.'/my.cnf';

        // Created empty at 0600 BEFORE anything is written, so the secret never exists in a world-readable file.
        touch($path);
        @chmod($path, 0600);

        $lines = ['[client]'];
        foreach ([
            'user' => $credentials['username'] ?? null,
            'password' => $credentials['password'] ?? null,
            'host' => $credentials['host'] ?? null,
            'port' => $credentials['port'] ?? null,
        ] as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            // my.cnf is not shell: the only thing that must not appear is a newline.
            $lines[] = $key.'="'.str_replace(["\n", "\r", '"', '\\'], ['', '', '\"', '\\\\'], (string) $value).'"';
        }
        file_put_contents($path, implode("\n", $lines)."\n");
        @chmod($path, 0600);

        return $path;
    }

    /** @return array{ok: false, bytes: int, uncompressed_bytes: int, tables: int, seconds: float, error: string} */
    private function failure(string $error, float $started): array
    {
        return ['ok' => false, 'bytes' => 0, 'uncompressed_bytes' => 0, 'tables' => 0, 'seconds' => round(microtime(true) - $started, 2), 'error' => $error];
    }

    /** mysqldump's own messages quote the defaults-file path and sometimes the user: neither belongs in a report. */
    private function scrub(string $message, string $defaultsPath): string
    {
        $message = str_replace($defaultsPath, '<credentials file>', $message);
        $message = (string) preg_replace('/(--password|password)=\S*/i', '$1=<redacted>', $message);
        $message = (string) preg_replace("/user\s*'[^']*'/i", "user '<redacted>'", $message);
        $message = (string) preg_replace('/[\x00-\x20]+/', ' ', $message);

        return trim(substr($message, 0, 300));
    }
}
