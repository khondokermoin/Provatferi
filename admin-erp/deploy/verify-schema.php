<?php

/**
 * One-off production schema verification (2026-09-08 deployment).
 * Run via cron: php verify-schema.php
 * Delete this file from the server once the deployment is verified —
 * it is not part of the application and should not linger.
 */

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

$out = [];

$out['tables'] = DB::selectOne('select count(*) c from information_schema.tables where table_schema = database()')->c;

$badEngine = DB::select("select table_name from information_schema.tables where table_schema = database() and engine <> 'InnoDB'");
$out['non_innodb_tables'] = array_map(fn ($r) => $r->table_name, $badEngine);

$badCollation = DB::select("select table_name from information_schema.tables where table_schema = database() and table_collation not like 'utf8mb4%'");
$out['non_utf8mb4_tables'] = array_map(fn ($r) => $r->table_name, $badCollation);

$out['foreign_keys'] = DB::selectOne('select count(*) c from information_schema.referential_constraints where constraint_schema = database()')->c;

$restrictFks = DB::select("
    select concat(kcu.table_name, '.', kcu.column_name) k, rc.delete_rule r
    from information_schema.referential_constraints rc
    join information_schema.key_column_usage kcu
      on kcu.constraint_name = rc.constraint_name and kcu.constraint_schema = rc.constraint_schema
    where rc.constraint_schema = database() and rc.delete_rule = 'RESTRICT'
");
$out['restrict_fks'] = array_map(fn ($r) => $r->k, $restrictFks);

$out['composite_indexes'] = DB::select("
    select table_name, index_name from information_schema.statistics
    where table_schema = database() and index_name in (
        'activities_status_start_datetime_index',
        'job_postings_status_published_at_index',
        'membership_applications_status_created_at_index'
    ) group by table_name, index_name
");
$out['composite_indexes'] = array_map(fn ($r) => "{$r->table_name}.{$r->index_name}", $out['composite_indexes']);

$out['charset'] = DB::selectOne('select @@character_set_database cs, @@collation_database co')->cs
    .'/'.DB::selectOne('select @@character_set_database cs, @@collation_database co')->co;

$out['users_count'] = DB::table('users')->count();
$out['migrations_count'] = DB::table('migrations')->count();

echo json_encode($out, JSON_PRETTY_PRINT);
