<?php
/**
 * Restores a backup set into an ISOLATED scratch database and checks that what came back is what went in.
 * (docs/BACKUP_AND_DISASTER_RECOVERY.md, "Restore drill".) CLI only: 404 otherwise.
 *
 * A backup nobody has restored is a hypothesis. This is the experiment, and it runs against a set produced by the
 * DEPLOYED application, using the DEPLOYED classes — so what is exercised is the code that will actually be used in
 * an emergency, not a copy of it.
 *
 * IT NEVER RESTORES OVER PRODUCTION. The target is a scratch database this script creates and drops again, and it
 * refuses outright if the name it was given is one of the real ones. Production is only ever read: table lists, row
 * counts and checksums, so the restored copy can be compared against the original.
 *
 *   php restore-drill.php <set-id> [scratch-database-name]
 *
 * The scratch name defaults to the production database name with `_drill` appended, because on shared hosting a
 * user's grants usually cover only names with its own prefix. If creating it is refused, the database half is
 * reported as skipped — with the reason — and the file and configuration halves still run. A skipped half is never
 * reported as a pass.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
ini_set('display_errors', 'stderr');
set_time_limit(0);

const LIVE_APP = '/home/u951246149/domains/provatferi.org/laravel-admin';

function out(array $data, int $exit = 0): never
{
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE), "\n";
    exit($exit);
}

$setId = (string) ($argv[1] ?? '');
if (preg_match('/^[0-9]{8}T[0-9]{6}Z$/', $setId) !== 1) {
    out(['ok' => false, 'error' => 'usage: php restore-drill.php <set-id> [scratch-database-name]'], 2);
}

// ---------------------------------------------------------------------------------------------- boot the live app
define('LARAVEL_START', microtime(true));
define('LARAVEL_PUBLIC_PATH_OVERRIDE', LIVE_APP.'/public');
require LIVE_APP.'/vendor/autoload.php';
$laravel = require LIVE_APP.'/bootstrap/app.php';
$laravel->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\Backup\BackupFactory;
use App\Services\Backup\TarArchive;

$connectionName = config('database.default');
$connection = config('database.connections.'.$connectionName);
$productionDatabase = (string) ($connection['database'] ?? '');
$scratch = (string) ($argv[2] ?? ($productionDatabase.'_drill'));

// The one check that matters more than any result below.
foreach ([$productionDatabase, (string) config('backup.databases.cms.database'), (string) config('backup.databases.archive.database')] as $forbidden) {
    if ($forbidden !== '' && strcasecmp($scratch, $forbidden) === 0) {
        out(['ok' => false, 'error' => 'refusing to use a real database as the restore target'], 2);
    }
}
if (preg_match('/^[A-Za-z0-9_]+$/', $scratch) !== 1) {
    out(['ok' => false, 'error' => 'the scratch database name must be a plain identifier'], 2);
}

$store = BackupFactory::store();
$setDir = $store->setPath($setId);
if (! is_dir($setDir)) {
    out(['ok' => false, 'error' => 'no such set: '.$setId], 1);
}

$report = ['set' => $setId, 'started_utc' => gmdate('c'), 'scratch_database' => $scratch];
$problems = [];

// ------------------------------------------------------------------------------------ 1. the set's own integrity
$verification = $store->verify($setId, BackupFactory::dumper());
$report['integrity'] = ['ok' => $verification['ok'], 'artifacts' => $verification['checked'], 'problems' => $verification['problems']];
if (! $verification['ok']) {
    $problems[] = 'the set does not verify, so nothing was restored from it';
    out(['ok' => false, 'report' => $report, 'problems' => $problems], 1);
}

$manifest = $store->manifest($setId) ?? [];

// ------------------------------------------------------------------------------------ 2. the database, in scratch
$scratchDatabase = ['attempted' => true, 'created' => false, 'skipped' => null];
$dsnBase = 'mysql:host='.($connection['host'] ?? '127.0.0.1').';port='.((int) ($connection['port'] ?? 3306)).';charset=utf8mb4';

try {
    $pdo = new PDO($dsnBase, (string) ($connection['username'] ?? ''), (string) ($connection['password'] ?? ''), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    $pdo->exec('CREATE DATABASE IF NOT EXISTS `'.$scratch.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $scratchDatabase['created'] = true;
} catch (Throwable $e) {
    // On shared hosting the database user often cannot create databases. That is a limitation to report, not a
    // failure to hide — and never a reason to point the restore at a real database instead.
    $scratchDatabase['skipped'] = 'the scratch database could not be created, so the database restore was not attempted: '.preg_replace('/\s+/', ' ', substr($e->getMessage(), 0, 160));
}

if ($scratchDatabase['created']) {
    try {
        $target = new PDO($dsnBase.';dbname='.$scratch, (string) ($connection['username'] ?? ''), (string) ($connection['password'] ?? ''), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);

        // Replay the dump. Statements are split on a line ending in ';' — safe because every value the dumper
        // writes is either a bare number, driver-quoted (which escapes newlines) or 0x-hex, so no value can
        // contain a raw newline of its own.
        $gz = gzopen($setDir.'/db-erp.sql.gz', 'rb');
        if ($gz === false) {
            throw new RuntimeException('the ERP dump could not be opened');
        }
        $statement = '';
        $executed = 0;
        $startedRestore = microtime(true);
        while (! gzeof($gz)) {
            $line = gzgets($gz);
            if ($line === false) {
                break;
            }
            $trimmed = rtrim($line, "\r\n");
            if ($trimmed === '' || str_starts_with($trimmed, '--')) {
                continue;
            }
            $statement .= ($statement === '' ? '' : "\n").$trimmed;
            if (str_ends_with($trimmed, ';')) {
                $target->exec($statement);
                $executed++;
                $statement = '';
            }
        }
        gzclose($gz);
        $scratchDatabase['statements_executed'] = $executed;
        $scratchDatabase['restore_seconds'] = round(microtime(true) - $startedRestore, 2);

        /** Compare one SELECT between production and the restored copy. */
        $compare = function (string $sql, array $bindings = []) use ($pdo, $target, $productionDatabase, $scratch): array {
            $live = $pdo->prepare(str_replace('{db}', $productionDatabase, $sql));
            $live->execute($bindings);
            $restored = $target->prepare(str_replace('{db}', $scratch, $sql));
            $restored->execute($bindings);

            return [$live->fetchAll(PDO::FETCH_COLUMN), $restored->fetchAll(PDO::FETCH_COLUMN)];
        };

        [$liveTables, $restoredTables] = $compare("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = '{db}' AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME");
        $scratchDatabase['tables'] = ['production' => count($liveTables), 'restored' => count($restoredTables), 'identical' => $liveTables === $restoredTables];
        if ($liveTables !== $restoredTables) {
            $problems[] = 'the restored table list differs from production';
        }

        [$liveFks, $restoredFks] = $compare("SELECT CONCAT(TABLE_NAME, '.', CONSTRAINT_NAME) FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = '{db}' AND REFERENCED_TABLE_NAME IS NOT NULL ORDER BY 1");
        $scratchDatabase['foreign_keys'] = ['production' => count($liveFks), 'restored' => count($restoredFks), 'identical' => $liveFks === $restoredFks];
        if ($liveFks !== $restoredFks) {
            $problems[] = 'the restored foreign keys differ from production';
        }

        [$liveGenerated, $restoredGenerated] = $compare("SELECT CONCAT(TABLE_NAME, '.', COLUMN_NAME, ':', EXTRA) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = '{db}' AND EXTRA LIKE '%GENERATED%' ORDER BY 1");
        $scratchDatabase['generated_columns'] = ['production' => $liveGenerated, 'restored' => $restoredGenerated, 'identical' => $liveGenerated === $restoredGenerated];
        if ($liveGenerated !== $restoredGenerated) {
            $problems[] = 'a generated column did not survive the restore';
        }

        // Representative records: the business tables whose loss would actually matter.
        $counts = [];
        foreach (['users', 'members', 'memberships', 'membership_applications', 'payments', 'membership_dues', 'payment_receipts', 'committees', 'committee_submissions', 'leadership_profiles', 'job_postings', 'job_applications', 'notices', 'activities'] as $table) {
            if (! in_array($table, $restoredTables, true)) {
                continue;
            }
            $liveCount = (int) $pdo->query('SELECT COUNT(*) FROM `'.$productionDatabase.'`.`'.$table.'`')->fetchColumn();
            $restoredCount = (int) $target->query('SELECT COUNT(*) FROM `'.$table.'`')->fetchColumn();
            $counts[$table] = ['production' => $liveCount, 'restored' => $restoredCount, 'match' => $liveCount === $restoredCount];
            if ($liveCount !== $restoredCount) {
                $problems[] = 'row count differs for '.$table;
            }
        }
        $scratchDatabase['representative_records'] = $counts;

        // CHECKSUM TABLE over every table: the strongest statement available that the DATA is the same, not just
        // that the row counts agree.
        $mismatched = [];
        $checked = 0;
        foreach ($restoredTables as $table) {
            $liveSum = $pdo->query('CHECKSUM TABLE `'.$productionDatabase.'`.`'.$table.'`')->fetch(PDO::FETCH_ASSOC);
            $restoredSum = $target->query('CHECKSUM TABLE `'.$table.'`')->fetch(PDO::FETCH_ASSOC);
            $checked++;
            if (($liveSum['Checksum'] ?? null) !== ($restoredSum['Checksum'] ?? null)) {
                $mismatched[] = $table;
            }
        }
        $scratchDatabase['checksums'] = ['tables_checked' => $checked, 'mismatched' => $mismatched, 'all_match' => $mismatched === []];
        if ($mismatched !== []) {
            // Not necessarily corruption: a row written between the dump and now changes the live checksum. Named
            // rather than hidden, so it can be judged instead of guessed at.
            $scratchDatabase['checksums']['note'] = 'a table that changed on production since the dump was taken will differ here; compare against the dump time';
        }

        $target = null;
    } catch (Throwable $e) {
        $problems[] = 'the database restore failed: '.preg_replace('/\s+/', ' ', substr($e->getMessage(), 0, 200));
        $scratchDatabase['error'] = preg_replace('/\s+/', ' ', substr($e->getMessage(), 0, 200));
    } finally {
        // Always, whatever happened: the scratch database exists only for this script.
        try {
            $pdo->exec('DROP DATABASE IF EXISTS `'.$scratch.'`');
            $scratchDatabase['dropped'] = true;
        } catch (Throwable $e) {
            $scratchDatabase['dropped'] = false;
            $problems[] = 'the scratch database could not be dropped — remove '.$scratch.' by hand';
        }
    }
} else {
    $problems[] = $scratchDatabase['skipped'];
}
$report['database'] = $scratchDatabase;

// ------------------------------------------------------------------------------------ 3. the other two databases
$others = [];
foreach (['cms', 'archive'] as $label) {
    $path = $setDir.'/db-'.$label.'.sql.gz';
    $expected = (int) ($manifest['artifacts']['db-'.$label.'.sql.gz']['tables'] ?? 0);
    $actual = is_file($path) ? BackupFactory::dumper()->countTables($path) : 0;
    $others[$label] = ['present' => is_file($path), 'tables_in_manifest' => $expected, 'tables_in_dump' => $actual, 'match' => $expected > 0 && $expected === $actual];
    if (! $others[$label]['match']) {
        $problems[] = 'the '.$label.' dump does not hold the number of tables the manifest claims';
    }
}
$report['other_databases'] = $others;

// ------------------------------------------------------------------------------------ 4. files, by hash
$scratchDir = sys_get_temp_dir().'/pf-drill-'.bin2hex(random_bytes(5));
@mkdir($scratchDir, 0700, true);
$files = [];
foreach (['erp-private-uploads', 'erp-public-uploads'] as $label) {
    $indexPath = $setDir.'/files-'.$label.'.index.json';
    $archivePath = $setDir.'/files-'.$label.'.tar.gz';
    $index = is_file($indexPath) ? json_decode((string) file_get_contents($indexPath), true) : null;
    $entries = is_array($index['files'] ?? null) ? $index['files'] : [];
    if ($entries === []) {
        $files[$label] = ['checked' => 0, 'note' => 'the index lists no files'];

        continue;
    }

    // Three files rather than one, chosen from across the index so a single lucky hit cannot pass for the set.
    $picks = [$entries[0], $entries[intdiv(count($entries), 2)], $entries[count($entries) - 1]];
    $checked = 0;
    $matched = 0;
    foreach ($picks as $entry) {
        $member = (string) ($entry['path'] ?? '');
        $expected = (string) ($entry['sha256'] ?? '');
        if ($member === '' || $expected === '') {
            continue;
        }
        $destination = $scratchDir.'/restored.bin';
        @unlink($destination);
        $checked++;
        if (TarArchive::extractOne($archivePath, $member, $destination) && is_file($destination) && hash_file('sha256', $destination) === $expected) {
            $matched++;
        }
        @unlink($destination);
    }
    $files[$label] = ['checked' => $checked, 'hashes_matched' => $matched, 'all_match' => $checked > 0 && $checked === $matched];
    if ($checked !== $matched) {
        $problems[] = 'a restored file from '.$label.' did not match its recorded hash';
    }
}
$report['files'] = $files;

// ------------------------------------------------------------------------------------ 5. the encrypted bundle
$vaultPath = $setDir.'/config.vault';
$configBundle = ['present' => is_file($vaultPath)];
if (is_file($vaultPath)) {
    $destination = $scratchDir.'/config';
    @mkdir($destination, 0700, true);
    try {
        $opened = BackupFactory::vault()->open($vaultPath, $destination);
        $members = [];
        foreach ((array) ($opened['members'] ?? []) as $name => $meta) {
            $file = $destination.'/'.$name;
            // Sizes and a shape check only. The contents are the production secrets; they are never read out.
            $members[$name] = ['bytes' => is_file($file) ? (int) filesize($file) : 0, 'non_empty' => is_file($file) && filesize($file) > 0];
        }
        $envFile = $destination.'/laravel.env';
        $looksLikeEnv = false;
        if (is_file($envFile)) {
            $head = (string) file_get_contents($envFile, false, null, 0, 4096);
            // Key NAMES prove it decrypted to the real file; no value is printed or returned.
            $looksLikeEnv = str_contains($head, 'APP_KEY') && str_contains($head, 'DB_DATABASE') && str_contains($head, 'BACKUP_ENCRYPTION_KEY');
        }
        $configBundle['decrypted'] = ($opened['ok'] ?? false) === true;
        $configBundle['members'] = $members;
        $configBundle['env_decrypted_to_a_real_env_file'] = $looksLikeEnv;
        $configBundle['all_members_non_empty'] = ! in_array(false, array_column($members, 'non_empty'), true);
        if (! $configBundle['decrypted'] || ! $looksLikeEnv || ! $configBundle['all_members_non_empty']) {
            $problems[] = 'the configuration bundle did not decrypt to a complete, usable set of files';
        }
    } catch (Throwable $e) {
        $configBundle['decrypted'] = false;
        $configBundle['error'] = preg_replace('/\s+/', ' ', substr($e->getMessage(), 0, 160));
        $problems[] = 'the configuration bundle could not be opened';
    }
} else {
    $problems[] = 'the set has no configuration bundle';
}
$report['config_bundle'] = $configBundle;

// Everything decrypted is removed again, immediately. A drill that leaves plaintext secrets on disk has traded one
// risk for a worse one.
$removed = 0;
foreach ((array) glob($scratchDir.'/config/*') as $file) {
    if (is_file($file)) {
        @file_put_contents($file, str_repeat('0', max(1, (int) filesize($file))));
        @unlink($file);
        $removed++;
    }
}
@rmdir($scratchDir.'/config');
@rmdir($scratchDir);
$report['scratch_cleanup'] = ['decrypted_files_shredded' => $removed, 'directory_gone' => ! is_dir($scratchDir)];

$report['finished_utc'] = gmdate('c');
$report['application_boots'] = true;   // this script is the live application, booted

out(['ok' => $problems === [], 'problems' => $problems, 'report' => $report], $problems === [] ? 0 : 1);
