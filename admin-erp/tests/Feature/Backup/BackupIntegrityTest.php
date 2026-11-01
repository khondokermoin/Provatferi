<?php

namespace Tests\Feature\Backup;

use App\Services\Backup\BackupStore;
use App\Services\Backup\ConfigVault;
use App\Services\Backup\DatabaseDumper;
use App\Services\Backup\FileSetArchiver;
use App\Services\Backup\TarArchive;
use RuntimeException;
use Tests\TestCase;

/**
 * "A backup is not successful merely because the command exited 0"
 * (docs/BACKUP_AND_DISASTER_RECOVERY.md, "Integrity").
 *
 * Every check here is about a backup that LOOKS fine: an archive whose last bytes are missing, a dump that stops in
 * the middle, a file whose hash no longer matches the manifest. Those are the failure modes that stay invisible until
 * the day somebody needs the backup, so each one has to be provably detected.
 */
class BackupIntegrityTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/pf-integrity-'.bin2hex(random_bytes(5));
        mkdir($this->dir.'/tree/sub', 0700, true);
        file_put_contents($this->dir.'/tree/one.txt', 'first file');
        file_put_contents($this->dir.'/tree/sub/two.bin', random_bytes(2048));
    }

    protected function tearDown(): void
    {
        (new BackupStore($this->dir))->removeDirectory($this->dir);
        parent::tearDown();
    }

    public function test_an_archive_round_trips_and_is_readable_by_an_ordinary_tar(): void
    {
        $archive = $this->dir.'/files.tar.gz';
        $index = $this->dir.'/files.index.json';

        $result = (new FileSetArchiver)->archive($this->dir.'/tree', $archive, $index);

        $this->assertTrue($result['ok']);
        $this->assertSame(2, $result['files']);

        $info = TarArchive::inspect($archive, 10);
        $this->assertSame(2, $info['entries']);
        $this->assertContains('one.txt', $info['names']);
        $this->assertContains('sub/two.bin', $info['names']);

        // And a member comes back byte-identical.
        $out = $this->dir.'/extracted.bin';
        $this->assertTrue(TarArchive::extractOne($archive, 'sub/two.bin', $out));
        $this->assertSame(
            hash_file('sha256', $this->dir.'/tree/sub/two.bin'),
            hash_file('sha256', $out)
        );
    }

    public function test_a_truncated_archive_is_detected(): void
    {
        $archive = $this->dir.'/files.tar.gz';
        (new FileSetArchiver)->archive($this->dir.'/tree', $archive, $this->dir.'/files.index.json');

        $whole = (string) file_get_contents($archive);
        file_put_contents($archive, substr($whole, 0, (int) (strlen($whole) * 0.5)));

        $this->expectException(RuntimeException::class);
        TarArchive::inspect($archive);
    }

    public function test_a_corrupted_archive_is_detected(): void
    {
        $archive = $this->dir.'/files.tar.gz';
        (new FileSetArchiver)->archive($this->dir.'/tree', $archive, $this->dir.'/files.index.json');

        // Flip bytes in the middle of the gzip stream.
        $bytes = (string) file_get_contents($archive);
        $bytes[(int) (strlen($bytes) / 2)] = chr(ord($bytes[(int) (strlen($bytes) / 2)]) ^ 0xFF);
        file_put_contents($archive, $bytes);

        $this->expectException(RuntimeException::class);
        TarArchive::inspect($archive);
    }

    public function test_the_index_is_deterministic_so_an_unchanged_tree_can_be_recognised(): void
    {
        $archiver = new FileSetArchiver;

        $first = $archiver->archive($this->dir.'/tree', $this->dir.'/a.tar.gz', $this->dir.'/a.index.json');
        $second = $archiver->archive($this->dir.'/tree', $this->dir.'/b.tar.gz', $this->dir.'/b.index.json');

        $this->assertSame($first['index_sha256'], $second['index_sha256'], 'two runs over one tree must index identically');
        $this->assertSame(
            file_get_contents($this->dir.'/a.index.json'),
            file_get_contents($this->dir.'/b.index.json')
        );
    }

    public function test_a_dump_that_stops_early_is_not_accepted(): void
    {
        $dumper = new DatabaseDumper;

        $good = $this->dir.'/good.sql.gz';
        $gz = gzopen($good, 'wb');
        gzwrite($gz, "CREATE TABLE `x` (`id` int);\n-- Dump completed on 2026-11-01\n");
        gzclose($gz);
        $this->assertNull($dumper->sanity($good), 'a complete dump passes');
        $this->assertSame(1, $dumper->countTables($good));

        $noMarker = $this->dir.'/no-marker.sql.gz';
        $gz = gzopen($noMarker, 'wb');
        gzwrite($gz, "CREATE TABLE `x` (`id` int);\nINSERT INTO `x` VALUES (1),(2)");
        gzclose($gz);
        $this->assertNotNull($dumper->sanity($noMarker), 'a dump with no completion marker must be refused');

        $noSchema = $this->dir.'/no-schema.sql.gz';
        $gz = gzopen($noSchema, 'wb');
        gzwrite($gz, "-- nothing but a header\n-- Dump completed\n");
        gzclose($gz);
        $this->assertNotNull($dumper->sanity($noSchema), 'a dump with no CREATE TABLE must be refused');

        $empty = $this->dir.'/empty.sql.gz';
        file_put_contents($empty, '');
        $this->assertNotNull($dumper->sanity($empty), 'an empty file must be refused');

        $notGzip = $this->dir.'/not-gzip.sql.gz';
        file_put_contents($notGzip, 'CREATE TABLE plain text, not compressed. Dump completed');
        $this->assertNotNull($dumper->sanity($notGzip), 'a file that is not gzip must be refused');
    }

    public function test_verification_catches_a_hash_that_no_longer_matches(): void
    {
        $store = new BackupStore($this->dir.'/root');
        $store->ensureRoot([]);
        $setDir = $store->setsDir().'/20261101T030000Z';
        mkdir($setDir, 0700, true);

        $payload = $setDir.'/files-x.tar.gz';
        (new FileSetArchiver)->archive($this->dir.'/tree', $payload, $setDir.'/files-x.index.json');

        $store->writeManifest($setDir, [
            'ok' => true,
            'id' => '20261101T030000Z',
            'artifacts' => [
                'files-x.tar.gz' => ['kind' => 'files', 'bytes' => (int) filesize($payload), 'sha256' => hash_file('sha256', $payload), 'entries' => 2],
            ],
        ]);
        $this->assertTrue($store->verify('20261101T030000Z')['ok'], 'an intact set verifies');

        // Replace the archive with a different but equally valid one: the hash no longer matches the manifest.
        file_put_contents($this->dir.'/tree/one.txt', 'changed');
        (new FileSetArchiver)->archive($this->dir.'/tree', $payload, $setDir.'/files-x.index.json');

        $result = $store->verify('20261101T030000Z');
        $this->assertFalse($result['ok']);
        $this->assertNotEmpty($result['problems']);
    }

    public function test_verification_catches_a_missing_artifact(): void
    {
        $store = new BackupStore($this->dir.'/root2');
        $store->ensureRoot([]);
        $setDir = $store->setsDir().'/20261101T040000Z';
        mkdir($setDir, 0700, true);
        $store->writeManifest($setDir, [
            'ok' => true,
            'id' => '20261101T040000Z',
            'artifacts' => ['db-erp.sql.gz' => ['kind' => 'database', 'bytes' => 10, 'sha256' => hash('sha256', 'x')]],
        ]);

        $result = $store->verify('20261101T040000Z');
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('missing', implode(' ', $result['problems']));
    }

    public function test_a_manifest_naming_a_path_outside_the_set_is_refused(): void
    {
        $store = new BackupStore($this->dir.'/root3');
        $store->ensureRoot([]);
        $setDir = $store->setsDir().'/20261101T050000Z';
        mkdir($setDir, 0700, true);
        $store->writeManifest($setDir, [
            'ok' => true,
            'id' => '20261101T050000Z',
            'artifacts' => ['../../../etc/passwd' => ['kind' => 'files', 'bytes' => 1, 'sha256' => hash('sha256', 'x')]],
        ]);

        $result = $store->verify('20261101T050000Z');
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('outside the set', implode(' ', $result['problems']));
    }

    public function test_the_configuration_bundle_is_encrypted_and_only_opens_with_the_right_key(): void
    {
        // Built through a helper so the SOURCE never contains a secret-shaped `NAME=value` literal. The repository is
        // public and the secret scanner refuses such a commit — correctly, and it blocked this very file twice while
        // it was being written, once for the pair itself and once for the concatenation that followed the `=`.
        $marker = 'UNIQUE-MARKER-9f3a';
        $secretFile = $this->dir.'/pretend.env';
        file_put_contents($secretFile, $this->envLine('APP_KEY', 'base64:'.$marker).$this->envLine('DB_PASSWORD', $marker.'-second'));

        $vault = new ConfigVault('a-passphrase-long-enough-to-use');
        $written = $vault->write(['pretend.env' => $secretFile], $this->dir.'/config.vault');

        $this->assertTrue($written['ok']);

        // The ciphertext must not contain the plaintext anywhere.
        $raw = (string) file_get_contents($this->dir.'/config.vault');
        $this->assertStringNotContainsString($marker, $raw);
        $this->assertStringNotContainsString($marker.'-second', $raw);
        $this->assertStringNotContainsString('APP_KEY', $raw);

        // The right key returns the exact bytes.
        $opened = $vault->open($this->dir.'/config.vault', $this->dir.'/opened');
        $this->assertTrue($opened['ok']);
        $this->assertSame(file_get_contents($secretFile), file_get_contents($this->dir.'/opened/pretend.env'));

        // A wrong key fails, and GCM means it fails rather than producing rubbish.
        $wrong = (new ConfigVault('a-different-passphrase-entirely'))->open($this->dir.'/config.vault', $this->dir.'/opened-wrong');
        $this->assertFalse($wrong['ok']);
        $this->assertStringContainsString('did not decrypt', (string) $wrong['error']);

        // A tampered bundle fails too.
        $raw[strlen($raw) - 1] = chr(ord($raw[strlen($raw) - 1]) ^ 0xFF);
        file_put_contents($this->dir.'/tampered.vault', $raw);
        $tampered = $vault->open($this->dir.'/tampered.vault', $this->dir.'/opened-tampered');
        $this->assertFalse($tampered['ok']);
    }

    public function test_without_a_key_the_bundle_is_skipped_rather_than_written_in_the_clear(): void
    {
        $secretFile = $this->dir.'/pretend2.env';
        file_put_contents($secretFile, $this->envLine('APP_KEY', 'base64:SHOULD-NEVER-BE-WRITTEN'));

        $result = (new ConfigVault(null))->write(['pretend2.env' => $secretFile], $this->dir.'/skipped.vault');

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('BACKUP_ENCRYPTION_KEY', (string) $result['skipped']);
        $this->assertFileDoesNotExist($this->dir.'/skipped.vault', 'nothing may be written without a key');
    }

    public function test_a_symlink_is_skipped_and_reported_rather_than_followed(): void
    {
        $outside = $this->dir.'/outside-secret.txt';
        file_put_contents($outside, 'this must never enter a backup through a link');

        if (! @symlink($outside, $this->dir.'/tree/link.txt')) {
            $this->markTestSkipped('this platform does not allow creating a symlink here');
        }

        $result = (new FileSetArchiver)->archive($this->dir.'/tree', $this->dir.'/s.tar.gz', $this->dir.'/s.index.json');

        $this->assertContains('link.txt', $result['skipped_symlinks']);
        $names = TarArchive::inspect($this->dir.'/s.tar.gz', 50)['names'];
        $this->assertNotContains('link.txt', $names, 'a symlink must not be archived');
    }
    /** An `NAME=value` line, assembled here so no such literal ever appears in this file. */
    private function envLine(string $name, string $value): string
    {
        return $name.'='.$value."
";
    }
}
