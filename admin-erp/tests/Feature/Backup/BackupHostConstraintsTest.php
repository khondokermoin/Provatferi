<?php

namespace Tests\Feature\Backup;

use Tests\TestCase;

/**
 * The backup must not call a function this host forbids (docs/BACKUP_AND_DISASTER_RECOVERY.md, "What this host does
 * not allow").
 *
 * WHY THIS EXISTS. Two production runs failed in a row for the same underlying reason, and neither could have failed
 * locally:
 *
 *   1. `Call to undefined function proc_open()` — the shared host has every process-spawning function in
 *      `disable_functions`, so mysqldump could not be started at all;
 *   2. `Call to undefined function link()` — the hard-link optimisation had a copy fallback, but `@link(...)` cannot
 *      reach it: calling a function that does not exist is an `Error` in PHP 8 and `@` does not suppress that.
 *
 * The local machine has both functions, so the only thing that would have caught either before a production run is a
 * test that knows what the host forbids. This is that test. The list is the real `disable_functions` value read from
 * the server on 2026-11-01.
 */
class BackupHostConstraintsTest extends TestCase
{
    /** Exactly what production reports in `disable_functions`. */
    private const DISABLED_ON_PRODUCTION = [
        'system', 'exec', 'shell_exec', 'passthru', 'mysql_list_dbs', 'ini_alter', 'dl',
        'symlink', 'link', 'chgrp', 'leak', 'popen', 'apache_child_terminate', 'virtual',
        'mb_send_mail', 'proc_open',
    ];

    /** @return list<string> */
    private function backupFiles(): array
    {
        $files = array_merge(
            (array) glob(app_path('Services/Backup/*.php')),
            (array) glob(app_path('Console/Commands/Backup*.php')),
            (array) glob(app_path('Notifications/BackupFailedNotification.php')),
            (array) glob(base_path('deploy/qa/backup-standalone.php')),
            (array) glob(base_path('deploy/qa/release-tree-audit.php')),
        );

        return array_values(array_filter(array_map(fn ($f) => is_string($f) ? $f : null, $files)));
    }

    public function test_every_call_to_a_function_this_host_disables_is_guarded(): void
    {
        $files = $this->backupFiles();
        $this->assertNotEmpty($files, 'the backup sources should be found');

        $problems = [];
        foreach ($files as $file) {
            $source = (string) file_get_contents($file);
            // Strip comments, doc-blocks AND string literals: a function named in an explanation is not a call, and
            // neither is the word "symlink(s)" inside a warning message — which is what the first version flagged.
            $code = (string) preg_replace(['#/\*.*?\*/#s', '#//[^\n]*#'], '', $source);
            $code = (string) preg_replace(["/'(?:[^'\\\\]|\\\\.)*'/", '/"(?:[^"\\\\]|\\\\.)*"/'], "''", $code);

            foreach (self::DISABLED_ON_PRODUCTION as $function) {
                // A bare call, not a method call ($pdo->exec(...)) and not a longer name (symlink vs link).
                if (preg_match('/(?<![\w>$:])'.preg_quote($function, '/').'\s*\(/', $code) !== 1) {
                    continue;
                }
                // The guard is looked for in the ORIGINAL source: its argument is a string literal, which the
                // stripping above deliberately removed.
                if (! str_contains($source, "function_exists('".$function."')")
                    && ! str_contains($source, 'function_exists("'.$function.'")')) {
                    $problems[] = basename($file).' calls '.$function.'() without a function_exists() guard';
                }
            }
        }

        $this->assertSame([], $problems, implode("\n", array_merge(
            ['A function in production\'s disable_functions is called unguarded. Calling a missing function is a fatal'],
            ['Error in PHP 8 — @ does not suppress it — so this would take the backup down on the server only.'],
            $problems
        )));
    }

    public function test_the_default_dumper_needs_no_external_process(): void
    {
        // The configured default must be the pure-PHP dumper, because mysqldump cannot run on this host at all.
        $this->assertSame('php', config('backup.dumper'), 'the default dumper must not require an external process');

        // And asking for mysqldump where proc_open is missing must not blow up: it falls back.
        config(['backup.dumper' => 'mysqldump']);
        $dumper = \App\Services\Backup\BackupFactory::dumper();
        if (! function_exists('proc_open')) {
            $this->assertInstanceOf(\App\Services\Backup\PhpSqlDumper::class, $dumper);
        } else {
            $this->assertInstanceOf(\App\Services\Backup\DatabaseDumper::class, $dumper);
        }
    }

    public function test_the_mysqldump_path_refuses_cleanly_when_proc_open_is_missing(): void
    {
        // Even reached directly, it must report a failure rather than raise a fatal.
        $result = (new \App\Services\Backup\DatabaseDumper('mysqldump', 5))
            ->dump(['database' => 'anything'], sys_get_temp_dir().'/pf-never-written-'.bin2hex(random_bytes(4)).'.sql.gz');

        if (! function_exists('proc_open')) {
            $this->assertFalse($result['ok']);
            $this->assertStringContainsString('proc_open is disabled', (string) $result['error']);
        } else {
            // Where proc_open exists this path is exercised for real elsewhere; here it simply must not throw.
            $this->assertIsBool($result['ok']);
        }
    }
}
