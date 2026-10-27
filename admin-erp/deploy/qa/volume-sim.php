<?php
/**
 * FILLS A LOCAL DATABASE WITH DISPOSABLE VOLUME, SO SCALING QUESTIONS CAN BE MEASURED INSTEAD OF GUESSED.
 * (docs/SYSTEM_ARCHITECTURE_AND_SCALABILITY.md). CLI only, and it REFUSES to run against anything but a local
 * development or test database — see the guard below. Never upload this to the server.
 *
 * WHY. Two questions cannot be answered on an empty table: "does this page's query count grow with the number of
 * rows?" (an N+1 shows up only when there are rows) and "how many bytes is a row really?" (the growth model needs
 * a measured average, not a guess). This inserts plain rows with the right shapes, so both can be measured.
 *
 * It writes with the query builder, NOT with models: no observer, no event, no cache invalidation and no call out
 * to the public website can fire from filling a table.
 *
 *   fill <table> <rows>    Append that many generated rows.
 *   sizes <t1,t2,...>      Report measured bytes per row for the named tables (runs ANALYZE TABLE first).
 *   truncate <table>       Remove the generated rows again (only rows this tool can recognise).
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
ini_set('display_errors', 'stderr');
ini_set('memory_limit', '512M');

define('LARAVEL_START', microtime(true));
require dirname(__DIR__, 2).'/vendor/autoload.php';
$laravel = require dirname(__DIR__, 2).'/bootstrap/app.php';
$laravel->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function out(array $data, int $exit = 0): never
{
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), "\n";
    exit($exit);
}

// ---------------------------------------------------------------------------------------------------------- the guard
$schema = DB::connection()->getDatabaseName();
$env = (string) config('app.env');
$host = (string) config('database.connections.'.config('database.default').'.host');
$localHost = in_array($host, ['127.0.0.1', 'localhost', '::1'], true);
$localName = (bool) preg_match('/(_dev|_test|_sim)$/', $schema);
if ($env !== 'local' || ! $localHost || ! $localName) {
    out([
        'refused' => 'this tool only ever runs against a local development or test database',
        'app_env' => $env,
        'database' => $schema,
        'host_is_local' => $localHost,
        'name_looks_local' => $localName,
    ], 3);
}

/** A generated marker that makes this tool's own rows recognisable later. */
const MARK = 'volsim';

/** @return array<string,object> column metadata for one table, keyed by column name */
function columns(string $table): array
{
    $out = [];
    foreach (DB::select(
        'select column_name as name, data_type as dt, column_type as ct, is_nullable as nullable,
                column_default as dflt, extra, character_maximum_length as len, numeric_precision as np, numeric_scale as ns
         from information_schema.columns where table_schema = ? and table_name = ? order by ordinal_position',
        [DB::connection()->getDatabaseName(), $table]
    ) as $c) {
        $out[$c->name] = $c;
    }

    return $out;
}

/** Columns that are UNIQUE on their own, so the generated value must differ per row. */
function uniqueColumns(string $table): array
{
    $single = [];
    $byKey = [];
    foreach (DB::select('show index from `'.$table.'`') as $i) {
        if ((int) $i->Non_unique === 0) {
            $byKey[$i->Key_name][] = $i->Column_name;
        }
    }
    foreach ($byKey as $cols) {
        if (count($cols) === 1) {
            $single[] = $cols[0];
        }
    }

    return $single;
}

/**
 * Columns MariaDB guards with a `json_valid()` CHECK. Laravel's `json()` column type is longtext plus such a check
 * here, so the type alone does not say "this must be JSON" — the constraint does, and writing prose into one fails.
 *
 * @return array<int,string>
 */
function jsonColumns(string $table): array
{
    $cols = [];
    try {
        foreach (DB::select(
            'select constraint_name as cn, check_clause as cc from information_schema.check_constraints
             where constraint_schema = ? and table_name = ?',
            [DB::connection()->getDatabaseName(), $table]
        ) as $c) {
            if (preg_match('/json_valid\s*\(\s*`?([A-Za-z0-9_]+)`?\s*\)/i', (string) $c->cc, $m) === 1) {
                $cols[] = $m[1];
            } elseif (preg_match('/^[A-Za-z0-9_]+$/', (string) $c->cn) === 1 && str_contains((string) $c->cc, 'json_valid')) {
                $cols[] = $c->cn;
            }
        }
    } catch (Throwable) {
        // Older MariaDB without information_schema.check_constraints: fall back to the name heuristic below.
    }

    return array_unique($cols);
}

/** For a foreign key, an existing id to point at — or null when the parent table is empty. */
function parentIds(string $table): array
{
    $map = [];
    foreach (DB::select(
        'select column_name as c, referenced_table_name as rt, referenced_column_name as rc
         from information_schema.key_column_usage
         where table_schema = ? and table_name = ? and referenced_table_name is not null',
        [DB::connection()->getDatabaseName(), $table]
    ) as $f) {
        $ids = DB::table($f->rt)->orderBy($f->rc)->limit(50)->pluck($f->rc)->all();
        $map[$f->c] = $ids;
    }

    return $map;
}

$mode = $argv[1] ?? '';

if ($mode === 'sizes') {
    $tables = array_filter(array_map('trim', explode(',', (string) ($argv[2] ?? ''))));
    $report = [];
    foreach ($tables as $t) {
        try {
            DB::statement('analyze table `'.$t.'`');
        } catch (Throwable) {
            // ANALYZE is advisory here; the figures below are still worth reporting.
        }
        $row = DB::selectOne(
            'select table_rows as r, avg_row_length as arl, data_length as dl, index_length as il
             from information_schema.tables where table_schema = ? and table_name = ?',
            [DB::connection()->getDatabaseName(), $t]
        );
        $exact = (int) DB::table($t)->count();
        $report[$t] = [
            'exact_rows' => $exact,
            'avg_row_bytes_reported' => (int) ($row->arl ?? 0),
            'data_bytes' => (int) ($row->dl ?? 0),
            'index_bytes' => (int) ($row->il ?? 0),
            'measured_bytes_per_row' => $exact > 0 ? round(((int) $row->dl + (int) $row->il) / $exact, 1) : null,
            'index_share' => ((int) $row->dl + (int) $row->il) > 0 ? round(((int) $row->il) / ((int) $row->dl + (int) $row->il), 2) : null,
        ];
    }
    out(['mode' => 'sizes', 'database' => $schema, 'tables' => $report]);
}

if ($mode === 'truncate') {
    $table = (string) ($argv[2] ?? '');
    $cols = columns($table);
    if ($cols === []) {
        out(['error' => 'unknown table '.$table], 2);
    }
    // Only rows this tool could have written: one of its text columns still carries the marker.
    $marked = null;
    foreach ($cols as $name => $c) {
        if (in_array($c->dt, ['varchar', 'char', 'text'], true) && ! in_array($name, ['password', 'remember_token'], true)) {
            $marked = $name;
            break;
        }
    }
    if ($marked === null) {
        out(['error' => 'no text column to recognise generated rows in '.$table], 2);
    }
    $deleted = DB::table($table)->where($marked, 'like', '%'.MARK.'%')->delete();
    out(['mode' => 'truncate', 'table' => $table, 'deleted' => $deleted, 'remaining' => DB::table($table)->count()]);
}

if ($mode !== 'fill') {
    out(['error' => 'modes: fill <table> <rows> | sizes <t1,t2,...> | truncate <table>'], 2);
}

$table = (string) ($argv[2] ?? '');
$want = (int) ($argv[3] ?? 0);
$cols = columns($table);
if ($cols === [] || $want < 1) {
    out(['error' => 'usage: fill <table> <rows>'], 2);
}

$unique = uniqueColumns($table);
$parents = parentIds($table);
$jsonCols = jsonColumns($table);
$lorem = str_repeat('প্রভাতফেরী সাহিত্য ও সংস্কৃতি চর্চা কেন্দ্র। ', 6);

/** One generated value for one column, shaped by its real type. */
$value = function (object $c, int $i) use ($unique, $parents, $jsonCols, $lorem, $table) {
    $name = $c->name;
    if ($name === 'id') {
        return null; // auto-increment
    }
    if (isset($parents[$name])) {
        $ids = $parents[$name];
        // A foreign key that is ALSO unique on its own (notices.job_posting_id is one) can hold each parent at most
        // once: cycling through the parents would collide on the second pass, so leave it empty instead.
        if (in_array($name, $unique, true)) {
            return $c->nullable === 'YES' ? null : ($ids[$i] ?? null);
        }
        if ($ids === []) {
            return $c->nullable === 'YES' ? null : 1;
        }

        return $ids[$i % count($ids)];
    }
    if (in_array($name, ['created_at', 'updated_at'], true)) {
        return gmdate('Y-m-d H:i:s', time() - ($i % 900) * 86400);
    }
    if ($name === 'deleted_at') {
        return null;
    }

    $suffix = MARK.'-'.$i;
    $isUnique = in_array($name, $unique, true);

    return match (true) {
        str_starts_with($c->ct, 'enum(') => (function () use ($c) {
            preg_match_all("/'([^']*)'/", $c->ct, $m);
            return $m[1][0] ?? '';
        })(),
        $c->dt === 'tinyint' => $i % 2,
        in_array($c->dt, ['int', 'bigint', 'smallint', 'mediumint'], true) => $isUnique ? $i + 1 : ($i % 97),
        in_array($c->dt, ['decimal', 'float', 'double'], true) => (float) (($i % 500) + 0.5),
        in_array($c->dt, ['date'], true) => gmdate('Y-m-d', time() - ($i % 900) * 86400),
        in_array($c->dt, ['datetime', 'timestamp'], true) => gmdate('Y-m-d H:i:s', time() - ($i % 900) * 86400),
        $c->dt === 'json' || in_array($name, $jsonCols, true) => json_encode([MARK => $i], JSON_UNESCAPED_UNICODE),
        in_array($c->dt, ['text', 'mediumtext', 'longtext'], true) => $isUnique ? $suffix : $lorem.' '.$suffix,
        $c->dt === 'char' && (int) $c->len === 36 => (string) Str::uuid(),
        default => (function () use ($c, $suffix, $name, $table) {
            $max = (int) ($c->len ?: 255);
            $base = match (true) {
                str_contains($name, 'email') => 'vs'.substr(md5($suffix), 0, 10).'@example.test',
                str_contains($name, 'slug') => Str::slug($table.'-'.$suffix),
                str_contains($name, 'phone') => '0171'.substr(md5($suffix), 0, 7),
                str_contains($name, 'password') => '$2y$10$'.substr(md5($suffix), 0, 53 - 7),
                default => $table.' '.$suffix,
            };

            return substr($base, 0, max(1, $max));
        })(),
    };
};

/**
 * Inserts one chunk, and on a constraint clash falls back to row-by-row so the run reports how many rows a table
 * could actually take instead of dumping a multi-megabyte SQL statement into the output.
 */
function insertChunk(string $table, array $chunk): int
{
    try {
        DB::table($table)->insert($chunk);

        return count($chunk);
    } catch (Throwable $e) {
        $ok = 0;
        foreach ($chunk as $row) {
            try {
                DB::table($table)->insert($row);
                $ok++;
            } catch (Throwable) {
                // A generated row this table cannot accept (a unique clash, a check constraint): skipped on purpose.
            }
        }
        // The message, not the statement: a failed bulk insert of 500 generated rows would otherwise print megabytes.
        $why = (string) preg_replace('/\s*\(Connection:.*/s', '', $e->getMessage());
        fwrite(STDERR, 'chunk fell back to row-by-row ('.$ok.'/'.count($chunk).' accepted): '.substr($why, 0, 220)."\n");

        return $ok;
    }
}

$start = (int) DB::table($table)->count();
$inserted = 0;
$t0 = microtime(true);
$chunk = [];
for ($i = $start; $i < $start + $want; $i++) {
    $row = [];
    foreach ($cols as $name => $c) {
        if ($name === 'id' && str_contains((string) $c->extra, 'auto_increment')) {
            continue;
        }
        $v = $value($c, $i);
        if ($v === null && $c->nullable === 'NO' && $c->dflt === null && ! str_contains((string) $c->extra, 'auto_increment')) {
            $v = ''; // a NOT NULL column with no default still needs something
        }
        $row[$name] = $v;
    }
    $chunk[] = $row;
    if (count($chunk) >= 500) {
        $inserted += insertChunk($table, $chunk);
        $chunk = [];
    }
}
if ($chunk !== []) {
    $inserted += insertChunk($table, $chunk);
}

out([
    'mode' => 'fill',
    'database' => $schema,
    'table' => $table,
    'rows_before' => $start,
    'inserted' => $inserted,
    'rows_after' => (int) DB::table($table)->count(),
    'seconds' => round(microtime(true) - $t0, 2),
]);
