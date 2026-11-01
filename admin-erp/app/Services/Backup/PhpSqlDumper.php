<?php

namespace App\Services\Backup;

use PDO;
use Throwable;

/**
 * Dumps a MariaDB database with no external process at all (docs/BACKUP_AND_DISASTER_RECOVERY.md).
 *
 * WHY THIS EXISTS, and why it is the DEFAULT rather than a fallback. The first production run of this backup failed
 * with `Call to undefined function proc_open()`: the shared host disables every process-spawning function, so
 * `mysqldump` cannot be reached from PHP at all. Locally `proc_open` is available, which is exactly why the problem
 * only appeared in production. Making this the default everywhere means the dump path the tests and the restore drill
 * exercise is the same one production runs.
 *
 * It also removes the credential problem instead of managing it: there is no command line, so there is nothing for a
 * password to leak into.
 *
 * CONSISTENCY. `START TRANSACTION WITH CONSISTENT SNAPSHOT` at REPEATABLE READ gives InnoDB the same guarantee
 * `mysqldump --single-transaction` does — every table is read as of one instant — and takes no locks, so production
 * keeps writing throughout.
 *
 * CORRECTNESS, the parts that are easy to get wrong:
 *   - a GENERATED column must not appear in an INSERT: the database computes it, and writing it is an error
 *     (`membership_dues.outstanding_amount` is STORED GENERATED);
 *   - binary and blob values are written as `0x…` hex, so no byte can be mangled by quoting or character sets;
 *   - rows stream through an unbuffered query, so a large table never has to fit in memory;
 *   - views are emitted after the tables they read, and the whole dump is wrapped in FOREIGN_KEY_CHECKS=0 so the
 *     restore order cannot matter;
 *   - the output ends with mysqldump's own `Dump completed` marker, so every integrity check already written keeps
 *     working unchanged.
 */
final class PhpSqlDumper extends DatabaseDumper
{
    /** Flush the buffer to the gzip stream at about this size. */
    private const FLUSH_AT = 262144;

    public function __construct(private readonly int $rowBatchBytes = 524288)
    {
        parent::__construct('(php)', 0);
    }

    /**
     * @param  array{host?: string, port?: int|string, database: string, username?: string, password?: string}  $credentials
     * @return array{ok: bool, bytes: int, uncompressed_bytes: int, tables: int, seconds: float, error?: string}
     */
    public function dump(array $credentials, string $destination): array
    {
        $started = microtime(true);
        $database = (string) ($credentials['database'] ?? '');
        if ($database === '') {
            return $this->result(false, $destination, 0, 0, $started, 'the database name is missing');
        }

        $gz = gzopen($destination, 'wb6');
        if ($gz === false) {
            return $this->result(false, $destination, 0, 0, $started, 'the dump file could not be opened for writing');
        }

        $written = 0;
        $buffer = '';
        $tables = 0;
        $emit = function (string $sql) use ($gz, &$buffer, &$written): void {
            $buffer .= $sql;
            if (strlen($buffer) >= self::FLUSH_AT) {
                gzwrite($gz, $buffer);
                $written += strlen($buffer);
                $buffer = '';
            }
        };

        try {
            $pdo = new PDO(
                'mysql:host='.($credentials['host'] ?? '127.0.0.1').';port='.((int) ($credentials['port'] ?? 3306)).';dbname='.$database.';charset=utf8mb4',
                (string) ($credentials['username'] ?? ''),
                (string) ($credentials['password'] ?? ''),
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_STRINGIFY_FETCHES => true]
            );

            // Metadata first, while queries are still buffered: an unbuffered read (below) occupies the connection.
            $meta = $this->metadata($pdo, $database);

            $emit($this->header($database));

            $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');

            foreach ($meta['tables'] as $table => $info) {
                $emit($this->tableSchema($pdo, $table));
                $tables++;
                $emit($this->tableRows($pdo, $table, $info['columns'], $emit));
            }

            foreach ($meta['views'] as $view) {
                $emit($this->viewSchema($pdo, $view));
            }
            $emit($this->triggers($pdo, $meta['trigger_names']));

            $pdo->commit();

            $emit($this->footer());
        } catch (Throwable $e) {
            if (isset($pdo) && $pdo->inTransaction()) {
                try {
                    $pdo->rollBack();
                } catch (Throwable) {
                    // Nothing was written to the database; the dump is what failed.
                }
            }
            gzwrite($gz, $buffer);
            gzclose($gz);

            return $this->result(false, $destination, 0, 0, $started, 'the dump failed: '.$this->scrubMessage($e->getMessage()));
        }

        gzwrite($gz, $buffer);
        $written += strlen($buffer);
        gzclose($gz);

        $sanity = $this->sanity($destination, $written);
        if ($sanity !== null) {
            return $this->result(false, $destination, $written, $tables, $started, $sanity);
        }

        return $this->result(true, $destination, $written, $tables, $started);
    }

    /**
     * @return array{tables: array<string, array{columns: list<array{name: string, binary: bool}>}>, views: list<string>, trigger_names: list<string>}
     */
    private function metadata(PDO $pdo, string $database): array
    {
        $tables = [];
        $views = [];
        foreach ($pdo->query('SHOW FULL TABLES')->fetchAll(PDO::FETCH_NUM) as $row) {
            if (($row[1] ?? '') === 'VIEW') {
                $views[] = (string) $row[0];
            } else {
                $tables[(string) $row[0]] = ['columns' => []];
            }
        }

        // Column list per table, GENERATED columns excluded: the database computes those and refuses an INSERT that
        // names them. `binary` marks the types that must be written as hex rather than quoted text.
        $statement = $pdo->prepare(
            'select table_name, column_name, data_type, extra from information_schema.columns
             where table_schema = ? order by table_name, ordinal_position'
        );
        $statement->execute([$database]);
        foreach ($statement->fetchAll(PDO::FETCH_NUM) as [$table, $column, $type, $extra]) {
            if (! isset($tables[(string) $table])) {
                continue;
            }
            if (stripos((string) $extra, 'GENERATED') !== false) {
                continue;
            }
            $tables[(string) $table]['columns'][] = [
                'name' => (string) $column,
                'binary' => in_array(strtolower((string) $type), ['blob', 'tinyblob', 'mediumblob', 'longblob', 'binary', 'varbinary'], true),
            ];
        }

        $triggers = [];
        try {
            foreach ($pdo->query('SHOW TRIGGERS')->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if (isset($row['Trigger'])) {
                    $triggers[] = (string) $row['Trigger'];
                }
            }
        } catch (Throwable) {
            // No TRIGGER privilege on a shared host is normal; the schema has none anyway.
        }

        return ['tables' => $tables, 'views' => $views, 'trigger_names' => $triggers];
    }

    private function header(string $database): string
    {
        return "-- Provatferi backup (pure-PHP dumper: this host disables proc_open)\n"
            ."-- Database: ".$this->ident($database)."\n"
            ."-- Started: ".gmdate('c')."\n"
            ."--\n"
            ."/*!40101 SET NAMES utf8mb4 */;\n"
            ."SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0;\n"
            ."SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n"
            ."SET @OLD_TIME_ZONE=@@TIME_ZONE, TIME_ZONE='+00:00';\n\n";
    }

    private function footer(): string
    {
        return "\nSET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS;\n"
            ."SET SQL_MODE=@OLD_SQL_MODE;\n"
            ."SET TIME_ZONE=@OLD_TIME_ZONE;\n\n"
            ."-- Dump completed on ".gmdate('Y-m-d H:i:s')." UTC\n";
    }

    private function tableSchema(PDO $pdo, string $table): string
    {
        $row = $pdo->query('SHOW CREATE TABLE '.$this->ident($table))->fetch(PDO::FETCH_NUM);
        $create = (string) ($row[1] ?? '');

        return "--\n-- Table structure for ".$this->ident($table)."\n--\n\n"
            ."DROP TABLE IF EXISTS ".$this->ident($table).";\n"
            .$create.";\n\n";
    }

    private function viewSchema(PDO $pdo, string $view): string
    {
        try {
            $row = $pdo->query('SHOW CREATE VIEW '.$this->ident($view))->fetch(PDO::FETCH_NUM);
            $create = (string) ($row[1] ?? '');
        } catch (Throwable) {
            return "-- view ".$this->ident($view)." could not be read\n";
        }

        return "--\n-- View ".$this->ident($view)."\n--\n\nDROP VIEW IF EXISTS ".$this->ident($view).";\n".$create.";\n\n";
    }

    /** @param list<string> $names */
    private function triggers(PDO $pdo, array $names): string
    {
        $sql = '';
        foreach ($names as $name) {
            try {
                $row = $pdo->query('SHOW CREATE TRIGGER '.$this->ident($name))->fetch(PDO::FETCH_ASSOC);
                $create = (string) ($row['SQL Original Statement'] ?? '');
                if ($create !== '') {
                    $sql .= "DROP TRIGGER IF EXISTS ".$this->ident($name).";\nDELIMITER ;;\n".$create.";;\nDELIMITER ;\n\n";
                }
            } catch (Throwable) {
                $sql .= '-- trigger '.$this->ident($name)." could not be read\n";
            }
        }

        return $sql;
    }

    /**
     * Streams one table's rows as multi-row INSERTs. Returns whatever is left in its own buffer; everything before
     * that has already gone through $emit, so memory stays flat however large the table is.
     *
     * @param  list<array{name: string, binary: bool}>  $columns
     */
    private function tableRows(PDO $pdo, string $table, array $columns, callable $emit): string
    {
        if ($columns === []) {
            return '';
        }
        $names = array_map(fn (array $c) => $this->ident($c['name']), $columns);
        $prefix = 'INSERT INTO '.$this->ident($table).' ('.implode(', ', $names).') VALUES ';

        // Unbuffered so a big table is not loaded at once. Nothing else may query this connection until it is done.
        $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
        $statement = $pdo->query('SELECT '.implode(', ', $names).' FROM '.$this->ident($table));

        $out = "--\n-- Rows of ".$this->ident($table)."\n--\n\n";
        $values = [];
        $size = 0;
        while (($row = $statement->fetch(PDO::FETCH_NUM)) !== false) {
            $tuple = '(';
            foreach ($columns as $index => $column) {
                $tuple .= ($index > 0 ? ',' : '').$this->literal($pdo, $row[$index] ?? null, $column['binary']);
            }
            $tuple .= ')';
            $values[] = $tuple;
            $size += strlen($tuple) + 1;

            if ($size >= $this->rowBatchBytes) {
                $emit($out.$prefix.implode(",\n", $values).";\n");
                $out = '';
                $values = [];
                $size = 0;
            }
        }
        $statement->closeCursor();
        $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);

        if ($values !== []) {
            $out .= $prefix.implode(",\n", $values).";\n";
        }

        return $out."\n";
    }

    private function literal(PDO $pdo, mixed $value, bool $binary): string
    {
        if ($value === null) {
            return 'NULL';
        }
        $value = (string) $value;
        if ($binary) {
            // Hex, so no character set or quoting rule can alter a single byte.
            return $value === '' ? "''" : '0x'.bin2hex($value);
        }
        // A plain integer or decimal is written bare; everything else is quoted by the driver.
        if ($value !== '' && preg_match('/^-?(0|[1-9][0-9]{0,18})(\.[0-9]+)?$/', $value) === 1) {
            return $value;
        }

        return $pdo->quote($value);
    }

    /** Back-quoted, with any back-quote doubled — the only escaping an identifier needs. */
    private function ident(string $name): string
    {
        return '`'.str_replace('`', '``', $name).'`';
    }

    /** A driver message can name the user and the host; neither belongs in a manifest. */
    private function scrubMessage(string $message): string
    {
        $message = (string) preg_replace("/user\s*'[^']*'/i", "user '<redacted>'", $message);
        $message = (string) preg_replace('/(password|identified by)\s*\S*/i', '$1 <redacted>', $message);
        $message = (string) preg_replace('/[\x00-\x20]+/', ' ', $message);

        return trim(substr($message, 0, 300));
    }

    /** @return array{ok: bool, bytes: int, uncompressed_bytes: int, tables: int, seconds: float, error?: string} */
    private function result(bool $ok, string $destination, int $written, int $tables, float $started, ?string $error = null): array
    {
        $result = [
            'ok' => $ok,
            'bytes' => (int) (@filesize($destination) ?: 0),
            'uncompressed_bytes' => $written,
            'tables' => $tables,
            'seconds' => round(microtime(true) - $started, 2),
        ];
        if ($error !== null) {
            $result['error'] = $error;
        }

        return $result;
    }
}
