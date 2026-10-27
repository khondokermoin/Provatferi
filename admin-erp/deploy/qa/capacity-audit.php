<?php
/**
 * READ-ONLY PRODUCTION CAPACITY / SCALABILITY AUDIT (docs/SYSTEM_ARCHITECTURE_AND_SCALABILITY.md). CLI only: 404 otherwise.
 *
 * WHY THIS EXISTS. "Will this hold when visitors increase?" cannot be answered from the code alone: it depends on the
 * row counts, the row SIZES, the indexes the optimiser actually chooses, the PHP and MariaDB limits this plan really
 * has, and how many queries one page costs. This reads all of that from the running production system.
 *
 * WHAT IT WILL NOT DO. It never writes business data, never deletes anything, never changes schema or configuration,
 * and never prints a credential (no .env value, no DB password, no token — only names, counts, sizes and digests).
 * The only rows it can cause are the ones Laravel itself writes while serving an ordinary public GET (the database
 * cache and rate-limiter keys, which expire by themselves); that happens in `routes`/`plan` only, and those modes ask
 * exactly what the public website already asks every few minutes.
 *
 *   host              PHP, OS and process facts: versions, limits, extensions, CPU, memory, load, free disk.
 *   db                MariaDB server variables and status that bound capacity, plus every table's rows/size/row width.
 *   idx [t1,t2,...]   Indexes and foreign keys (all tables, or only the named ones — output stays readable).
 *   fs [root]         File and byte counts per immediate child of the upload / release / docroot trees.
 *   routes            Handles representative PUBLIC GET routes through the real HTTP kernel and reports, per route,
 *                     the status, the QUERY COUNT, the total query time and the slowest statement. This is the N+1
 *                     measurement: a count that grows with the number of rows on the page is the symptom.
 *   plan              The same capture, then EXPLAIN for each distinct SELECT, against production statistics.
 *   agg               Times the aggregate queries a future dashboard would run, so "charts later" has a real number.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
ini_set('display_errors', 'stderr');

const QA_DOMAIN_ROOT = '/home/u951246149/domains/provatferi.org';
$APP = getenv('QA_APP') ?: (is_dir(QA_DOMAIN_ROOT.'/laravel-admin') ? QA_DOMAIN_ROOT.'/laravel-admin' : dirname(__DIR__, 2));

function out(array $data, int $exit = 0): never
{
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE), "\n";
    exit($exit);
}

function mb(int|float|null $bytes): float
{
    return round(((float) $bytes) / 1048576, 2);
}

$mode = $argv[1] ?? '';
$arg = $argv[2] ?? null;

// ------------------------------------------------------------------------------------------------- host (no framework)
if ($mode === 'host') {
    $meminfo = [];
    foreach (preg_split('/\n/', (string) @file_get_contents('/proc/meminfo')) ?: [] as $line) {
        if (preg_match('/^(MemTotal|MemAvailable|SwapTotal):\s+(\d+) kB/', $line, $m) === 1) {
            $meminfo[$m[1].'_mb'] = round(((int) $m[2]) / 1024, 1);
        }
    }
    $cpuModel = null;
    $cpuCount = 0;
    foreach (preg_split('/\n/', (string) @file_get_contents('/proc/cpuinfo')) ?: [] as $line) {
        if (str_starts_with($line, 'processor')) {
            $cpuCount++;
        }
        if ($cpuModel === null && preg_match('/^model name\s*:\s*(.+)$/', $line, $m) === 1) {
            $cpuModel = trim($m[1]);
        }
    }
    // CloudLinux publishes this account's own LVE limits here when the extension is present; absent = unknown, not zero.
    $lve = function_exists('lve_get_info') ? @lve_get_info() : null;

    out([
        'mode' => 'host',
        'checked_at_utc' => gmdate('c'),
        'php' => [
            'version' => PHP_VERSION,
            'sapi_for_this_run' => PHP_SAPI,
            'memory_limit' => ini_get('memory_limit'),
            'max_execution_time' => ini_get('max_execution_time'),
            'upload_max_filesize' => ini_get('upload_max_filesize'),
            'post_max_size' => ini_get('post_max_size'),
            'max_file_uploads' => ini_get('max_file_uploads'),
            'opcache_enabled' => (bool) ini_get('opcache.enable'),
            'opcache_memory_mb' => ini_get('opcache.memory_consumption'),
            'opcache_status' => function_exists('opcache_get_status') ? (function () {
                $s = @opcache_get_status(false);
                return $s === false ? 'unavailable_in_cli' : [
                    'used_mb' => mb($s['memory_usage']['used_memory'] ?? 0),
                    'free_mb' => mb($s['memory_usage']['free_memory'] ?? 0),
                    'cached_scripts' => $s['opcache_statistics']['num_cached_scripts'] ?? null,
                    'hit_rate' => $s['opcache_statistics']['opcache_hit_rate'] ?? null,
                ];
            })() : 'no_opcache_extension',
            'extensions_of_interest' => array_values(array_filter(
                ['redis', 'apcu', 'memcached', 'pcntl', 'posix', 'imagick', 'gd', 'intl', 'opcache', 'zip', 'bcmath', 'sodium', 'curl', 'fileinfo'],
                'extension_loaded'
            )),
        ],
        'os' => [
            'uname' => php_uname('s').' '.php_uname('r'),
            'cpu_count_visible' => $cpuCount ?: null,
            'cpu_model' => $cpuModel,
            'load_average_1_5_15' => function_exists('sys_getloadavg') ? sys_getloadavg() : null,
            'memory' => $meminfo ?: 'unreadable',
            'lve_limits' => $lve ?: 'unknown (no CloudLinux LVE extension in this PHP)',
        ],
        'disk_of_home' => [
            'free_mb' => mb((int) @disk_free_space('/home/u951246149')),
            'total_mb' => mb((int) @disk_total_space('/home/u951246149')),
            'note' => 'the device figures are the SERVER filesystem, not this account quota; the account quota comes from release-manager usage',
        ],
    ]);
}

// --------------------------------------------------------------------------------- probe (no framework): load from HERE
// WHY FROM THE SERVER. Measuring the public site from a laptop measures the laptop's uplink: a Cloudflare cache HIT and
// a dynamically rendered page came back equally slowly from Bangladesh at concurrency 10, which proves the client was
// the limit, not the origin. Run from the server, the network term nearly disappears and what is left is the origin's
// own capacity. Budgets are small and fixed, and every URL is read-only.
//
//   probe [requests:concurrency]   default 24:1 — e.g. `probe 24:6`
if ($mode === 'probe') {
    if (! function_exists('curl_multi_init')) {
        out(['error' => 'curl is not available in this PHP'], 2);
    }
    [$total, $conc] = array_pad(array_map('intval', explode(':', (string) ($arg ?: '24:1'))), 2, 1);
    $total = max(1, min(60, $total));          // never a flood: hard ceiling
    $conc = max(1, min(12, $conc));

    $urls = [
        'site_home' => 'https://provatferi.org/',
        'site_leadership' => 'https://provatferi.org/leadership',
        'site_notices' => 'https://provatferi.org/notices',
        'api_settings' => 'https://admin.provatferi.org/api/v1/settings',
        'api_leadership' => 'https://admin.provatferi.org/api/v1/public/leadership',
        'admin_login' => 'https://admin.provatferi.org/login',
    ];

    $loadBefore = function_exists('sys_getloadavg') ? sys_getloadavg() : null;
    $report = [];
    foreach ($urls as $label => $url) {
        $times = [];
        $statuses = [];
        $bytes = 0;
        $sent = 0;
        $wall = microtime(true);
        $multi = curl_multi_init();
        $active = 0;
        $starts = [];

        $add = function () use ($multi, $url, &$sent, &$active, &$starts, $total, $conc) {
            while ($active < $conc && $sent < $total) {
                $h = curl_init($url);
                curl_setopt_array($h, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_FOLLOWLOCATION => false,
                    CURLOPT_TIMEOUT => 25,
                    CURLOPT_ENCODING => '',
                    CURLOPT_USERAGENT => 'provatferi-capacity-audit/1.0',
                ]);
                curl_multi_add_handle($multi, $h);
                $starts[(int) $h] = microtime(true);
                $sent++;
                $active++;
            }
        };
        $add();

        do {
            curl_multi_exec($multi, $running);
            curl_multi_select($multi, 0.2);
            while ($info = curl_multi_info_read($multi)) {
                $h = $info['handle'];
                $times[] = round((microtime(true) - ($starts[(int) $h] ?? microtime(true))) * 1000, 1);
                $code = (int) curl_getinfo($h, CURLINFO_RESPONSE_CODE);
                $key = $code === 0 ? 'error:'.curl_errno($h) : (string) $code;
                $statuses[$key] = ($statuses[$key] ?? 0) + 1;
                $bytes += strlen((string) curl_multi_getcontent($h));
                curl_multi_remove_handle($multi, $h);
                curl_close($h);
                $active--;
                $add();
                curl_multi_exec($multi, $running);
            }
        } while ($running > 0 || $active > 0);
        curl_multi_close($multi);

        $wall = microtime(true) - $wall;
        sort($times);
        $at = fn (int $p) => $times === [] ? null : $times[min(count($times) - 1, (int) floor($p / 100 * count($times)))];
        $report[$label] = [
            'url' => $url,
            'requests' => $total,
            'concurrency' => $conc,
            'rps' => round($total / max($wall, 0.001), 2),
            'p50_ms' => $at(50),
            'p95_ms' => $at(95),
            'p99_ms' => $at(99),
            'max_ms' => $times === [] ? null : end($times),
            'mean_kb' => round($bytes / $total / 1024, 1),
            'statuses' => $statuses,
        ];
    }

    out([
        'mode' => 'probe',
        'checked_at_utc' => gmdate('c'),
        'measured_from' => 'the hosting account itself, so the client network is not the limit',
        'load_average_before' => $loadBefore,
        'load_average_after' => function_exists('sys_getloadavg') ? sys_getloadavg() : null,
        'caveat' => 'the load average belongs to the whole shared server, not this account',
        'results' => $report,
    ]);
}

// ------------------------------------------------------------------------------------------------ fs (no framework)
if ($mode === 'fs') {
    $roots = [
        'live_private_uploads' => QA_DOMAIN_ROOT.'/laravel-admin/storage/app/private/uploads',
        'live_public_uploads' => QA_DOMAIN_ROOT.'/public_html/admin/storage',
        'release_trees' => QA_DOMAIN_ROOT.'/laravel-admin-releases',
        'domain_root' => QA_DOMAIN_ROOT,
        'apex_docroot' => QA_DOMAIN_ROOT.'/public_html',
    ];
    if ($arg !== null && isset($roots[$arg])) {
        $roots = [$arg => $roots[$arg]];
    }

    /** Files and bytes under one directory. Capped so a huge tree cannot turn this into a long-running job. */
    $walk = function (string $dir, int $cap = 400000): array {
        if (! is_dir($dir)) {
            return ['exists' => false];
        }
        $files = 0;
        $dirs = 0;
        $bytes = 0;
        $newest = null;
        $oldest = null;
        try {
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS | FilesystemIterator::UNIX_PATHS),
                RecursiveIteratorIterator::SELF_FIRST
            );
            foreach ($it as $entry) {
                /** @var SplFileInfo $entry */
                if ($entry->isDir()) {
                    $dirs++;
                    continue;
                }
                $files++;
                $bytes += $entry->getSize() ?: 0;
                $t = $entry->getMTime();
                $newest = $newest === null ? $t : max($newest, $t);
                $oldest = $oldest === null ? $t : min($oldest, $t);
                if ($files + $dirs >= $cap) {
                    return ['exists' => true, 'capped_at' => $cap, 'files' => $files, 'dirs' => $dirs, 'mb' => mb($bytes)];
                }
            }
        } catch (Throwable $e) {
            return ['exists' => true, 'error' => $e::class];
        }

        return [
            'exists' => true,
            'files' => $files,
            'dirs' => $dirs,
            'inodes' => $files + $dirs,
            'mb' => mb($bytes),
            'mean_file_kb' => $files > 0 ? round($bytes / $files / 1024, 1) : null,
            'oldest_utc' => $oldest ? gmdate('Y-m-d', $oldest) : null,
            'newest_utc' => $newest ? gmdate('Y-m-d', $newest) : null,
        ];
    };

    $report = [];
    foreach ($roots as $label => $root) {
        if (! is_dir($root)) {
            $report[$label] = ['path' => $root, 'exists' => false];
            continue;
        }
        $children = [];
        foreach (scandir($root) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $path = $root.'/'.$name;
            $children[$name] = is_dir($path) ? $walk($path) : ['file_kb' => round((filesize($path) ?: 0) / 1024, 1)];
        }
        $report[$label] = ['path' => $root, 'children' => $children];
    }

    out(['mode' => 'fs', 'checked_at_utc' => gmdate('c'), 'roots' => $report]);
}

// -------------------------------------------------------------------------------------------------- the running app
define('LARAVEL_START', microtime(true));
define('LARAVEL_PUBLIC_PATH_OVERRIDE', $APP.'/public');
require $APP.'/vendor/autoload.php';
$laravel = require $APP.'/bootstrap/app.php';
$laravel->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

if ($mode === 'db') {
    $schema = DB::connection()->getDatabaseName();

    $tables = [];
    $totals = ['tables' => 0, 'rows' => 0, 'data_mb' => 0.0, 'index_mb' => 0.0, 'free_mb' => 0.0];
    foreach (DB::select(
        'select table_name as t, engine, row_format as rf, table_rows as r, avg_row_length as arl,
                data_length as dl, index_length as il, data_free as df, auto_increment as ai, create_time as ct
         from information_schema.tables where table_schema = ? order by (data_length + index_length) desc',
        [$schema]
    ) as $row) {
        $tables[$row->t] = [
            'engine' => $row->engine,
            'row_format' => $row->rf,
            'rows_estimate' => (int) $row->r,
            'avg_row_bytes' => (int) $row->arl,
            'data_mb' => mb($row->dl),
            'index_mb' => mb($row->il),
            'free_mb' => mb($row->df),
            'index_share' => ((int) $row->dl + (int) $row->il) > 0 ? round(((int) $row->il) / ((int) $row->dl + (int) $row->il), 2) : null,
            'auto_increment' => $row->ai === null ? null : (int) $row->ai,
            'created_utc' => $row->ct === null ? null : substr((string) $row->ct, 0, 10),
        ];
        $totals['tables']++;
        $totals['rows'] += (int) $row->r;
        $totals['data_mb'] += mb($row->dl);
        $totals['index_mb'] += mb($row->il);
        $totals['free_mb'] += mb($row->df);
    }
    $totals['data_mb'] = round($totals['data_mb'], 2);
    $totals['index_mb'] = round($totals['index_mb'], 2);
    $totals['free_mb'] = round($totals['free_mb'], 2);
    $totals['total_mb'] = round($totals['data_mb'] + $totals['index_mb'], 2);

    // EXACT counts for the tables whose growth actually matters. information_schema.table_rows is an InnoDB estimate
    // and can be wildly wrong on small tables, so the growth model must not be built on it.
    $exact = [];
    foreach ([
        'users', 'sessions', 'cache', 'cache_locks', 'jobs', 'failed_jobs',
        'settings', 'content_blocks', 'activities', 'notices', 'job_postings',
        'job_applications', 'committees', 'committee_submissions', 'committee_members',
        'membership_applications', 'memberships', 'members', 'membership_dues', 'payments', 'payment_receipts',
        'leadership_profiles', 'security_events', 'mail_audit_events', 'mail_sent_messages', 'mailboxes',
        'mail_identities', 'approval_histories', 'number_sequences', 'personal_access_tokens',
        'roles', 'permissions', 'volunteer_applications',
    ] as $t) {
        if (isset($tables[$t])) {
            $exact[$t] = (int) DB::table($t)->count();
        }
    }

    $vars = [];
    foreach (DB::select("show variables where Variable_name in (
        'version','version_comment','max_connections','max_user_connections','thread_cache_size',
        'innodb_buffer_pool_size','innodb_buffer_pool_instances','innodb_log_file_size','innodb_flush_log_at_trx_commit',
        'innodb_file_per_table','innodb_io_capacity','table_open_cache','open_files_limit',
        'tmp_table_size','max_heap_table_size','sort_buffer_size','join_buffer_size','read_buffer_size',
        'slow_query_log','long_query_time','log_queries_not_using_indexes','wait_timeout','interactive_timeout',
        'character_set_server','collation_server','sql_mode','transaction_isolation','max_allowed_packet',
        'performance_schema','query_cache_type','query_cache_size'
    )") as $v) {
        $vars[$v->Variable_name] = $v->Value;
    }

    $status = [];
    foreach (DB::select("show global status where Variable_name in (
        'Uptime','Threads_connected','Threads_running','Max_used_connections','Max_used_connections_time',
        'Connections','Aborted_connects','Aborted_clients','Questions','Queries','Slow_queries',
        'Com_select','Com_insert','Com_update','Com_delete','Com_commit','Com_rollback',
        'Created_tmp_tables','Created_tmp_disk_tables','Select_full_join','Select_scan','Sort_merge_passes',
        'Innodb_buffer_pool_reads','Innodb_buffer_pool_read_requests','Innodb_row_lock_waits','Innodb_row_lock_time_avg',
        'Innodb_deadlocks','Table_locks_waited','Handler_read_rnd_next','Handler_read_key','Opened_tables'
    )") as $s) {
        $status[$s->Variable_name] = $s->Value;
    }
    // The two ratios that say whether this server is actually under pressure.
    $reads = (float) ($status['Innodb_buffer_pool_read_requests'] ?? 0);
    $status['_buffer_pool_hit_rate'] = $reads > 0 ? round(1 - ((float) ($status['Innodb_buffer_pool_reads'] ?? 0)) / $reads, 5) : null;
    $status['_tmp_tables_on_disk_share'] = ((float) ($status['Created_tmp_tables'] ?? 0)) > 0
        ? round(((float) ($status['Created_tmp_disk_tables'] ?? 0)) / ((float) $status['Created_tmp_tables']), 3) : null;
    $status['_uptime_days'] = round(((float) ($status['Uptime'] ?? 0)) / 86400, 2);
    $status['_questions_per_second_since_start'] = ((float) ($status['Uptime'] ?? 0)) > 0
        ? round(((float) ($status['Questions'] ?? 0)) / ((float) $status['Uptime']), 2) : null;

    out([
        'mode' => 'db',
        'checked_at_utc' => gmdate('c'),
        'schema' => $schema,
        'app_env' => (string) config('app.env'),
        'drivers' => [
            'cache_store' => (string) config('cache.default'),
            'queue_connection' => (string) config('queue.default'),
            'session_driver' => (string) config('session.driver'),
            'filesystem_disk' => (string) config('filesystems.default'),
            'note' => 'cache, queue and session on the database means every cache read and every page view is SQL',
        ],
        'totals' => $totals,
        'exact_row_counts' => $exact,
        'tables' => $tables,
        'server_variables' => $vars,
        'server_status' => $status,
        'shared_server_caveat' => 'these server-wide variables and status counters belong to a SHARED MariaDB instance; they are not this account alone',
    ]);
}

if ($mode === 'idx') {
    $schema = DB::connection()->getDatabaseName();
    $only = $arg === null || $arg === 'all' ? null : array_filter(array_map('trim', explode(',', $arg)));

    $names = array_map(
        fn ($r) => $r->t,
        DB::select('select table_name as t from information_schema.tables where table_schema = ? order by table_name', [$schema])
    );
    if ($only !== null) {
        $names = array_values(array_intersect($names, $only));
    }

    $indexes = [];
    foreach ($names as $t) {
        $byName = [];
        foreach (DB::select('show index from `'.$t.'`') as $i) {
            $byName[$i->Key_name]['unique'] = ((int) $i->Non_unique) === 0;
            $byName[$i->Key_name]['cols'][(int) $i->Seq_in_index] = $i->Column_name;
            $byName[$i->Key_name]['cardinality'] = max((int) ($byName[$i->Key_name]['cardinality'] ?? 0), (int) $i->Cardinality);
        }
        $compact = [];
        foreach ($byName as $name => $meta) {
            ksort($meta['cols']);
            $compact[$name] = implode(',', $meta['cols']).($meta['unique'] ? ' [UNIQUE]' : '').' card='.$meta['cardinality'];
        }
        $indexes[$t] = $compact;
    }

    $fks = [];
    foreach (DB::select(
        'select k.table_name as t, k.column_name as c, k.referenced_table_name as rt, k.referenced_column_name as rc,
                r.delete_rule as dr, r.update_rule as ur, k.constraint_name as cn
         from information_schema.key_column_usage k
         join information_schema.referential_constraints r
           on r.constraint_name = k.constraint_name and r.constraint_schema = k.constraint_schema
         where k.table_schema = ? and k.referenced_table_name is not null
         order by k.table_name, k.column_name',
        [$schema]
    ) as $f) {
        if ($only !== null && ! in_array($f->t, $only, true)) {
            continue;
        }
        $fks[] = $f->t.'.'.$f->c.' -> '.$f->rt.'.'.$f->rc.' (del '.$f->dr.', upd '.$f->ur.')';
    }

    out(['mode' => 'idx', 'checked_at_utc' => gmdate('c'), 'tables' => count($indexes), 'indexes' => $indexes, 'foreign_keys' => $fks]);
}

// ------------------------------------------------------------------- representative PUBLIC GET routes (read-only)
/** @return array<int,string> */
function publicGetPaths(): array
{
    return [
        '/api/v1/settings',
        '/api/v1/about',
        '/api/v1/public/leadership',
        '/api/v1/public/notices',
        '/api/v1/public/notices/sitemap',
        '/api/v1/activities',
        '/api/v1/job-postings',
        '/api/v1/public/committees',
        '/api/v1/public/members',
        '/api/v1/public/homepage-carousel',
        '/api/v1/public/membership/campaigns/current',
        '/api/v1/membership-types',
        '/api/v1/organization-units',
    ];
}

if ($mode === 'routes' || $mode === 'plan') {
    $kernel = $laravel->make(Illuminate\Contracts\Http\Kernel::class);
    $captured = [];
    $perRoute = [];

    // ONE listener for the whole run. Registering it per iteration would add a second, third … listener and count the
    // same query once per route already visited — which is exactly the kind of inflated number this audit must not produce.
    $log = [];
    DB::listen(function ($q) use (&$log) {
        $log[] = ['sql' => $q->sql, 'ms' => round($q->time, 2), 'bindings' => $q->bindings];
    });

    foreach (publicGetPaths() as $path) {
        $log = [];

        $request = Illuminate\Http\Request::create('https://admin.provatferi.org'.$path, 'GET', server: [
            'HTTP_ACCEPT' => 'application/json',
            'REMOTE_ADDR' => '127.0.0.1',
        ]);
        $started = microtime(true);
        try {
            $response = $kernel->handle($request);
            $status = $response->getStatusCode();
            $bytes = strlen((string) $response->getContent());
        } catch (Throwable $e) {
            $status = 'threw '.$e::class;
            $bytes = 0;
        }
        $wallMs = round((microtime(true) - $started) * 1000, 1);

        $queryMs = array_sum(array_column($log, 'ms'));
        usort($log, fn ($a, $b) => $b['ms'] <=> $a['ms']);
        $perRoute[$path] = [
            'status' => $status,
            'response_kb' => round($bytes / 1024, 1),
            'queries' => count($log),
            'query_ms_total' => round($queryMs, 2),
            'wall_ms' => $wallMs,
            'slowest' => array_slice(array_map(fn ($q) => $q['ms'].' ms  '.substr($q['sql'], 0, 150), $log), 0, 3),
            'distinct_statements' => count(array_unique(array_column($log, 'sql'))),
        ];
        foreach ($log as $q) {
            // Keep the REAL bindings with the statement. EXPLAIN on a parameterised query is only meaningful with the
            // values the application actually passed: substituting a placeholder value makes the optimiser estimate
            // for a row set that does not exist, and the plan it prints is then not the plan production runs.
            $captured[$q['sql']] = $q['bindings'];
        }
    }

    if ($mode === 'routes') {
        out([
            'mode' => 'routes',
            'checked_at_utc' => gmdate('c'),
            'what_this_is' => 'each PUBLIC GET route handled through the real HTTP kernel; a query count that grows with row count is the N+1 symptom',
            'routes' => $perRoute,
            'distinct_statements_overall' => count($captured),
        ]);
    }

    // plan: EXPLAIN every distinct SELECT we just saw, against production statistics.
    $plans = [];
    $pdo = DB::connection()->getPdo();
    foreach ($captured as $sql => $bindings) {
        if (stripos(ltrim($sql), 'select') !== 0) {
            continue;
        }
        try {
            $rows = DB::select('explain '.$sql, $bindings);
        } catch (Throwable $e) {
            $plans[substr($sql, 0, 150)] = 'EXPLAIN failed: '.$e::class;
            continue;
        }
        $flags = [];
        $plan = array_map(function ($r) use (&$flags) {
            $extra = (string) ($r->Extra ?? '');
            if (($r->type ?? '') === 'ALL') {
                $flags[] = 'full scan of '.($r->table ?? '?');
            }
            foreach (['Using temporary', 'Using filesort'] as $needle) {
                if (str_contains($extra, $needle)) {
                    $flags[] = $needle.' on '.($r->table ?? '?');
                }
            }

            return trim(($r->table ?? '?').' type='.($r->type ?? '?').' key='.($r->key ?? 'NULL').' rows='.($r->rows ?? '?').' '.$extra);
        }, $rows);
        $plans[substr($sql, 0, 150)] = ['plan' => $plan, 'flags' => array_values(array_unique($flags))];
    }

    out([
        'mode' => 'plan',
        'checked_at_utc' => gmdate('c'),
        'reading_it' => 'type=ALL with no key is a full table scan; "Using filesort"/"Using temporary" on a growing table is the thing that breaks later',
        'plans' => $plans,
    ]);
}

// ---------------------------------------------------------------------------------- authenticated admin screens
// LOCAL ONLY. Profiling an admin screen needs a signed-in session, and this audit never signs in as a real person on
// production (and never creates an account there to do it). Run it against the development database instead: the
// schema, the controllers and the middleware are identical, so the QUERY COUNT per screen is the same measurement.
if ($mode === 'admin') {
    if ((string) config('app.env') !== 'local') {
        out(['refused' => 'the admin profiler only runs against a local development database'], 3);
    }

    $user = App\Models\User::query()->orderBy('id')->first();
    if ($user === null) {
        out(['error' => 'no user to sign in as'], 2);
    }

    $kernel = $laravel->make(Illuminate\Contracts\Http\Kernel::class);
    $log = [];
    DB::listen(function ($q) use (&$log) {
        $log[] = ['sql' => $q->sql, 'ms' => round($q->time, 2)];
    });

    // Every admin GET route that needs no route parameter: the listings and dashboards, found from the route table
    // rather than a hand-written list, so a screen added later is measured too.
    $paths = [];
    foreach (Illuminate\Support\Facades\Route::getRoutes() as $route) {
        if (! in_array('GET', $route->methods(), true)) {
            continue;
        }
        $uri = $route->uri();
        if (! str_starts_with($uri, 'admin') || str_contains($uri, '{')) {
            continue;
        }
        $paths[] = '/'.$uri;
    }
    sort($paths);

    $report = [];
    foreach ($paths as $path) {
        $log = [];
        $request = Illuminate\Http\Request::create('https://admin.provatferi.org'.$path, 'GET');
        Illuminate\Support\Facades\Auth::login($user);
        $request->setUserResolver(fn () => $user);
        $t0 = microtime(true);
        try {
            $response = $kernel->handle($request);
            $status = $response->getStatusCode();
        } catch (Throwable $e) {
            $status = 'threw '.$e::class;
        }
        $wall = round((microtime(true) - $t0) * 1000, 1);
        usort($log, fn ($a, $b) => $b['ms'] <=> $a['ms']);
        $report[$path] = [
            'status' => $status,
            'queries' => count($log),
            'query_ms' => round(array_sum(array_column($log, 'ms')), 2),
            'wall_ms' => $wall,
            'distinct' => count(array_unique(array_column($log, 'sql'))),
            'slowest' => array_slice(array_map(fn ($q) => $q['ms'].'ms '.substr($q['sql'], 0, 110), $log), 0, 2),
        ];
    }

    out([
        'mode' => 'admin',
        'environment' => 'local development database',
        'signed_in_as_user_id' => $user->getKey(),
        'reading_it' => 'queries far above distinct means the same statement repeats — the N+1 signature',
        'screens' => $report,
    ]);
}

if ($mode === 'agg') {
    // The shape of query a KPI dashboard would run. Timed on production data so "charts later" has a measurement
    // behind it rather than a guess. All read-only.
    $probes = [
        'members_by_status' => 'select status, count(*) c from memberships group by status',
        'applications_by_month' => 'select date_format(created_at, "%Y-%m") m, count(*) c from membership_applications group by m order by m desc limit 24',
        'dues_outstanding' => 'select count(*) c, coalesce(sum(outstanding_amount),0) s from membership_dues where status <> "paid"',
        'dues_for_one_member' => 'select * from membership_dues where membership_id = 1 order by period_year desc, period_month desc limit 24',
        'payments_by_month' => 'select date_format(received_at, "%Y-%m") m, count(*) c, coalesce(sum(amount_received),0) s from payments where status = "verified" group by m order by m desc limit 24',
        'payments_unverified' => 'select count(*) c from payments where verified_at is null and cancelled_at is null',
        'receipts_total' => 'select count(*) c from payment_receipts',
        'security_events_recent' => 'select count(*) c from security_events where created_at >= date_sub(now(), interval 30 day)',
        'job_applications_by_posting' => 'select job_posting_id, count(*) c from job_applications group by job_posting_id order by c desc limit 20',
        'cache_rows' => 'select count(*) c from cache',
        'session_rows' => 'select count(*) c from sessions',
    ];
    $results = [];
    foreach ($probes as $name => $sql) {
        $t = microtime(true);
        try {
            $rows = DB::select($sql);
            $results[$name] = [
                'ms' => round((microtime(true) - $t) * 1000, 2),
                'result_rows' => count($rows),
                'first' => $rows === [] ? null : (array) $rows[0],
            ];
        } catch (Throwable $e) {
            $results[$name] = ['error' => $e::class.': '.substr($e->getMessage(), 0, 120)];
        }
    }

    out(['mode' => 'agg', 'checked_at_utc' => gmdate('c'), 'probes' => $results]);
}

out(['error' => 'modes: host | db | idx [tables|all] | fs [root] | probe [n:c] | routes | plan | agg'], 2);
