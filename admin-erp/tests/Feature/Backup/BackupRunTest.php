<?php

namespace Tests\Feature\Backup;

use App\Notifications\BackupFailedNotification;
use App\Services\Backup\BackupRunner;
use App\Services\Backup\BackupStore;
use App\Services\Backup\ConfigVault;
use App\Services\Backup\DatabaseDumper;
use App\Services\Backup\FileSetArchiver;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * What one run does, and what it must never do (docs/BACKUP_AND_DISASTER_RECOVERY.md).
 *
 * The database dump is stubbed here on purpose: these tests are about the ORCHESTRATION — the atomic publication of a
 * set, the state file, the failure alert, the overlap lock, and above all the rule that no credential may reach a
 * manifest. Whether mysqldump itself works is proven by running it for real, and whether a backup can actually be
 * restored is proven by the restore drill, which no amount of stubbing can replace.
 */
class BackupRunTest extends TestCase
{
    private string $root;

    private string $tree;

    protected function setUp(): void
    {
        parent::setUp();
        $base = sys_get_temp_dir().'/pf-run-'.bin2hex(random_bytes(5));
        $this->root = $base.'/backups';
        $this->tree = $base.'/uploads';
        mkdir($this->tree.'/applications', 0700, true);
        file_put_contents($this->tree.'/applications/cv.pdf', 'a pretend cv');
    }

    protected function tearDown(): void
    {
        (new BackupStore($this->root))->removeDirectory(dirname($this->root));
        parent::tearDown();
    }

    /** A dumper that writes a valid, tiny dump without needing mysqldump or a database. */
    private function stubDumper(): DatabaseDumper
    {
        return new class extends DatabaseDumper
        {
            public function dump(array $credentials, string $destination): array
            {
                $gz = gzopen($destination, 'wb');
                gzwrite($gz, "CREATE TABLE `stub` (`id` int);\n-- Dump completed\n");
                gzclose($gz);

                return ['ok' => true, 'bytes' => (int) filesize($destination), 'uncompressed_bytes' => 50, 'tables' => 1, 'seconds' => 0.01];
            }
        };
    }

    /** @param array<string, mixed> $overrides */
    private function runner(array $overrides = [], ?DatabaseDumper $dumper = null): BackupRunner
    {
        $config = array_merge([
            'root' => $this->root,
            'site_root' => dirname($this->root).'/site',
            'retention' => ['daily' => 7, 'weekly' => 4, 'monthly' => 3],
            'lock_stale_after_seconds' => 7200,
            'databases' => ['erp' => ['driver' => 'laravel', 'connection' => config('database.default')]],
            'file_sets' => ['erp-private-uploads' => $this->tree],
            'config_files' => [],
            'exclude' => [],
        ], $overrides);

        return new BackupRunner(
            $config,
            new BackupStore($config['root'], $config['retention']),
            $dumper ?? $this->stubDumper(),
            new FileSetArchiver,
            new ConfigVault(null),
        );
    }

    public function test_a_run_produces_a_verified_set_with_a_manifest(): void
    {
        $manifest = $this->runner()->run();

        $this->assertTrue($manifest['ok'], 'the run should succeed: '.json_encode($manifest['error'] ?? null));
        $this->assertTrue($manifest['verification']['ok']);
        $this->assertArrayHasKey('db-erp.sql.gz', $manifest['artifacts']);
        $this->assertArrayHasKey('files-erp-private-uploads.tar.gz', $manifest['artifacts']);
        $this->assertSame(1, $manifest['file_sets']['erp-private-uploads']['files']);

        $store = new BackupStore($this->root);
        $this->assertCount(1, $store->setIds());
        $this->assertFileExists($store->setPath($manifest['id']).'/manifest.json');

        // Required manifest fields, because a manifest is the only record of what a set contains.
        foreach (['id', 'started_utc', 'finished_utc', 'seconds', 'bytes', 'app', 'artifacts', 'databases', 'file_sets'] as $field) {
            $this->assertArrayHasKey($field, $manifest, "the manifest must record {$field}");
        }
    }

    public function test_the_backup_root_is_created_private_and_denies_http(): void
    {
        $this->runner()->run();

        $this->assertFileExists($this->root.'/.htaccess');
        $deny = (string) file_get_contents($this->root.'/.htaccess');
        $this->assertStringContainsString('Require all denied', $deny);
        $this->assertStringContainsString('Options -Indexes', $deny);
    }

    public function test_no_database_credential_reaches_the_manifest_the_state_or_the_index(): void
    {
        $marker = 'Pf-Backup-Secret-Marker-7f3a91';
        config(['database.connections.'.config('database.default').'.password' => $marker]);

        $manifest = $this->runner()->run();
        $this->assertTrue($manifest['ok']);

        $store = new BackupStore($this->root);
        $setDir = $store->setPath($manifest['id']);

        $haystacks = [
            'the returned manifest' => json_encode($manifest),
            'the manifest on disk' => (string) file_get_contents($setDir.'/manifest.json'),
            'the state file' => (string) file_get_contents($store->statePath()),
            'the file index' => (string) file_get_contents($setDir.'/files-erp-private-uploads.index.json'),
        ];
        foreach ($haystacks as $what => $contents) {
            $this->assertStringNotContainsString($marker, $contents, "the database password must not appear in {$what}");
        }
    }

    public function test_a_failing_dump_fails_the_run_and_is_recorded(): void
    {
        $failing = new class extends DatabaseDumper
        {
            public function dump(array $credentials, string $destination): array
            {
                return ['ok' => false, 'bytes' => 0, 'uncompressed_bytes' => 0, 'tables' => 0, 'seconds' => 0.0, 'error' => 'mysqldump could not be started (is it installed?)'];
            }
        };

        $manifest = $this->runner([], $failing)->run();

        $this->assertFalse($manifest['ok']);
        $this->assertStringContainsString('database erp', (string) $manifest['error']);

        $state = (new BackupStore($this->root))->state();
        $this->assertFalse($state['last_run']['ok']);
        $this->assertArrayHasKey('last_failure', $state);
        $this->assertArrayNotHasKey('last_success', $state, 'a failure must never look like a success');
    }

    public function test_a_second_run_while_the_lock_is_held_changes_nothing(): void
    {
        $this->runner()->run();
        $store = new BackupStore($this->root);
        $before = $store->setIds();

        // Hold the lock the way another process would.
        $handle = fopen($store->lockPath(), 'c');
        $this->assertTrue(flock($handle, LOCK_EX | LOCK_NB));

        $result = $this->runner()->run();

        $this->assertArrayHasKey('skipped', $result);
        $this->assertTrue($result['ok'], 'a skip is not a failure: the backup already running will do the work');
        $this->assertSame($before, $store->setIds(), 'the blocked run must create nothing');

        flock($handle, LOCK_UN);
        fclose($handle);
    }

    public function test_a_lock_held_far_too_long_is_reported_as_a_failure(): void
    {
        $store = new BackupStore($this->root);
        $store->ensureRoot([]);

        // A claim written long ago, as a hung run would leave, with the lock genuinely held.
        file_put_contents($store->lockClaimPath(), json_encode(['pid' => 1, 'started_utc' => gmdate('c', time() - 20000)]));
        $handle = fopen($store->lockPath(), 'c');
        $this->assertTrue(flock($handle, LOCK_EX | LOCK_NB));

        $result = $this->runner(['lock_stale_after_seconds' => 60])->run();

        $this->assertFalse($result['ok'], 'a backup stuck for hours must be a failure, not a quiet skip');
        $this->assertStringContainsString('stuck', (string) $result['error']);

        flock($handle, LOCK_UN);
        fclose($handle);
    }

    public function test_a_run_that_dies_leaves_no_set_that_looks_finished(): void
    {
        $store = new BackupStore($this->root);
        $store->ensureRoot([]);

        // A leftover temporary directory from a killed run.
        mkdir($store->setsDir().'/.tmp-20261101T010000Z/inner', 0700, true);
        file_put_contents($store->setsDir().'/.tmp-20261101T010000Z/half.sql.gz', 'partial');

        $this->assertSame([], $store->setIds(), 'a .tmp- directory is never a set');

        $manifest = $this->runner()->run();

        $this->assertContains('.tmp-20261101T010000Z', $manifest['abandoned_sets_swept']);
        $this->assertDirectoryDoesNotExist($store->setsDir().'/.tmp-20261101T010000Z');
    }

    public function test_an_unchanged_upload_tree_is_reused_instead_of_archived_again(): void
    {
        $first = $this->runner()->run();
        $this->assertFalse($first['file_sets']['erp-private-uploads']['reused'], 'the first run has nothing to reuse');

        $second = $this->runner()->run();
        $this->assertTrue($second['file_sets']['erp-private-uploads']['reused'], 'an unchanged tree must not be archived twice');

        // And a change breaks the reuse, so a modified tree is always captured afresh.
        file_put_contents($this->tree.'/applications/new.pdf', 'another document');
        $third = $this->runner()->run();
        $this->assertFalse($third['file_sets']['erp-private-uploads']['reused']);
        $this->assertSame(2, $third['file_sets']['erp-private-uploads']['files']);
    }

    public function test_the_command_alerts_when_a_run_fails(): void
    {
        Notification::fake();

        // The dump is made to fail by naming a database that does not exist. Deliberately NOT by pointing at a
        // missing mysqldump binary: the default dumper is pure PHP (this host forbids proc_open), so a bogus binary
        // would simply be ignored and the run would succeed — the test would then pass for the wrong reason on one
        // machine and fail on another.
        config([
            'backup.root' => $this->root,
            'backup.site_root' => dirname($this->root).'/site',
            'backup.file_sets' => ['erp-private-uploads' => $this->tree],
            'backup.config_files' => [],
            'backup.databases' => ['erp' => ['driver' => 'laravel', 'connection' => 'backup_broken_probe']],
            'database.connections.backup_broken_probe' => array_merge(
                (array) config('database.connections.'.config('database.default')),
                ['database' => 'a_database_that_does_not_exist_'.bin2hex(random_bytes(4))]
            ),
            'backup.alert_to' => 'staff@example.test',
        ]);

        $this->artisan('backup:run')->assertExitCode(1);

        Notification::assertSentOnDemand(
            BackupFailedNotification::class,
            function (BackupFailedNotification $notification, array $channels, object $notifiable) {
                $mail = $notification->toMail($notifiable);
                // The security class of the e-mail architecture: Reply-To security@, and no backup content.
                $this->assertSame([[config('institution.email.security'), config('institution.email_names.security')]], $mail->replyTo);
                $this->assertStringContainsString('backup', strtolower((string) $mail->subject));

                return true;
            }
        );
    }

    public function test_the_command_does_not_alert_when_told_not_to(): void
    {
        Notification::fake();

        config([
            'backup.root' => $this->root,
            'backup.file_sets' => [],
            'backup.config_files' => [],
            'backup.databases' => ['erp' => ['driver' => 'laravel', 'connection' => 'backup_broken_probe']],
            'database.connections.backup_broken_probe' => array_merge(
                (array) config('database.connections.'.config('database.default')),
                ['database' => 'a_database_that_does_not_exist_'.bin2hex(random_bytes(4))]
            ),
            'backup.alert_to' => 'staff@example.test',
        ]);

        $this->artisan('backup:run --no-alert')->assertExitCode(1);

        Notification::assertNothingSent();
    }
}
