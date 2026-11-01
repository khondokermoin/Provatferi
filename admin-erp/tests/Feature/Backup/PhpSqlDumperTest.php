<?php

namespace Tests\Feature\Backup;

use App\Services\Backup\PhpSqlDumper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The dump path production actually uses (docs/BACKUP_AND_DISASTER_RECOVERY.md).
 *
 * WHY IT IS PURE PHP. The first production backup failed with `Call to undefined function proc_open()`: this shared
 * host disables every process-spawning function, so `mysqldump` cannot be started from PHP at all. Locally proc_open
 * exists, which is precisely why nothing caught it until the code ran on the server. The PHP dumper is therefore the
 * default on every machine, so this test and the restore drill exercise the same path production runs.
 *
 * The restore itself is proven by the drill, not here: a dump was restored into a scratch database and `CHECKSUM
 * TABLE` matched the source for all 59 tables. What this test pins down is the handful of details that would make a
 * dump quietly wrong — above all a GENERATED column, which must never appear in an INSERT.
 */
class PhpSqlDumperTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/pf-dump-'.bin2hex(random_bytes(5));
        mkdir($this->dir, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach ((array) glob($this->dir.'/*') as $file) {
            @unlink((string) $file);
        }
        @rmdir($this->dir);
        parent::tearDown();
    }

    /** @return array{path: string, sql: string, result: array<string, mixed>} */
    private function dump(): array
    {
        $connection = config('database.default');
        $path = $this->dir.'/db.sql.gz';

        $result = (new PhpSqlDumper)->dump([
            'host' => config('database.connections.'.$connection.'.host'),
            'port' => config('database.connections.'.$connection.'.port'),
            'database' => config('database.connections.'.$connection.'.database'),
            'username' => config('database.connections.'.$connection.'.username'),
            'password' => config('database.connections.'.$connection.'.password'),
        ], $path);

        return ['path' => $path, 'sql' => (string) gzdecode((string) file_get_contents($path)), 'result' => $result];
    }

    public function test_it_produces_a_dump_that_passes_every_integrity_check(): void
    {
        ['path' => $path, 'result' => $result] = $this->dump();

        $this->assertTrue($result['ok'], 'the dump should succeed: '.json_encode($result['error'] ?? null));
        $this->assertGreaterThan(10, $result['tables'], 'the schema has many tables');
        $this->assertGreaterThan(0, $result['bytes']);

        // The same checks the manifest verification applies to a set weeks later.
        $this->assertNull((new PhpSqlDumper)->sanity($path, $result['uncompressed_bytes']));
        $this->assertSame($result['tables'], (new PhpSqlDumper)->countTables($path));
    }

    /**
     * Generated columns, Bengali text and binary bytes, in one pass.
     *
     * Written against a probe table created on its OWN connection, for a reason worth recording: the dumper opens its
     * own PDO connection, so it cannot see anything written inside this test's uncommitted RefreshDatabase
     * transaction. The first version of this test inserted rows the normal way, and the dump legitimately did not
     * contain them. DDL commits implicitly, so the probe is visible to every connection.
     */
    public function test_the_dumper_handles_generated_columns_bengali_text_and_binary_bytes(): void
    {
        $bengali = 'প্রভাতফেরী সাহিত্য ও সংস্কৃতি চর্চা কেন্দ্র';
        $bytes = "\x00\xFF\x10\x41";
        $probe = new \PDO(
            'mysql:host='.config('database.connections.'.config('database.default').'.host')
                .';dbname='.config('database.connections.'.config('database.default').'.database').';charset=utf8mb4',
            (string) config('database.connections.'.config('database.default').'.username'),
            (string) config('database.connections.'.config('database.default').'.password'),
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
        );

        $probe->exec('DROP TABLE IF EXISTS pf_dump_probe');
        $probe->exec('CREATE TABLE pf_dump_probe (
            id int unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY,
            label varchar(191) NOT NULL,
            amount decimal(10,2) NOT NULL,
            doubled decimal(10,2) AS (amount * 2) STORED,
            payload varbinary(32) NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

        $insert = $probe->prepare('INSERT INTO pf_dump_probe (label, amount, payload) VALUES (?, ?, ?)');
        $insert->execute([$bengali, '12.50', $bytes]);

        try {
            ['sql' => $sql] = $this->dump();

            $this->assertMatchesRegularExpression('/INSERT INTO `pf_dump_probe` \(([^)]+)\) VALUES/', $sql);
            preg_match('/INSERT INTO `pf_dump_probe` \(([^)]+)\) VALUES/', $sql, $m);
            $columns = array_map(fn (string $c) => trim($c, ' `'), explode(',', $m[1]));

            // The point of the test: a STORED GENERATED column must not be named, or the restore fails outright.
            $this->assertNotContains('doubled', $columns, 'a GENERATED column must never appear in an INSERT');
            $this->assertSame(['id', 'label', 'amount', 'payload'], $columns);

            // The schema still carries the generated column, so a restore recreates it.
            $this->assertStringContainsString('`doubled`', $sql);
            $this->assertStringContainsStringIgnoringCase('GENERATED', $sql);

            $this->assertStringContainsString($bengali, $sql, 'Bengali text must appear verbatim');
            $this->assertStringContainsString('0x'.bin2hex($bytes), $sql, 'binary must be written as hex, not quoted text');
        } finally {
            $probe->exec('DROP TABLE IF EXISTS pf_dump_probe');
        }
    }

    public function test_the_dump_is_wrapped_so_restore_order_cannot_matter(): void
    {
        ['sql' => $sql] = $this->dump();

        $this->assertStringContainsString('FOREIGN_KEY_CHECKS=0', $sql, 'a restore must not depend on table order');
        $this->assertStringContainsString('SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS', $sql, 'and must put it back');
        $this->assertStringContainsString('SET NAMES utf8mb4', $sql);
        $this->assertStringContainsString('Dump completed on', $sql, 'the completion marker is what proves it is not truncated');
        $this->assertStringContainsString('DROP TABLE IF EXISTS `users`', $sql);
        $this->assertStringContainsString('CREATE TABLE `users`', $sql);
    }

    public function test_no_credential_appears_anywhere_in_the_dump(): void
    {
        $connection = config('database.default');
        $password = (string) config('database.connections.'.$connection.'.password');

        ['sql' => $sql] = $this->dump();

        if ($password !== '') {
            $this->assertStringNotContainsString($password, $sql);
        }
        // Nor the shape of one: the dumper issues no CREATE USER / GRANT and names no credential.
        $this->assertStringNotContainsString('IDENTIFIED BY', strtoupper($sql));
        $this->assertStringNotContainsString('CREATE USER', strtoupper($sql));
    }

    public function test_a_failure_is_reported_rather_than_producing_a_half_dump(): void
    {
        $result = (new PhpSqlDumper)->dump([
            'host' => '127.0.0.1', 'port' => 3306,
            'database' => 'a_database_that_does_not_exist_'.bin2hex(random_bytes(4)),
            'username' => 'root', 'password' => '',
        ], $this->dir.'/bad.sql.gz');

        $this->assertFalse($result['ok']);
        $this->assertArrayHasKey('error', $result);
        $this->assertNotNull((new PhpSqlDumper)->sanity($this->dir.'/bad.sql.gz'), 'and the file it left must not pass sanity');
    }
}
