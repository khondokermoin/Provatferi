<?php
/**
 * PRODUCTION ACCEPTANCE KIT for the admin-managed public contact & social settings (docs/PUBLIC_CONTACT_SETTINGS.md).
 * CLI only: 404 otherwise. Upload it under a one-off name (`php deploy/qa/cron-guard.php new-session`) and run each mode
 * from a cron. It reads and writes ONLY the `site.*` rows of the `settings` table, and prints no secret: those rows are
 * the organisation's public contact data.
 *
 *   inventory
 *       Read-only. Every `site.*` row (key, value, type, group, is_public, updated_by, created_at, updated_at) and the
 *       NAMES of the other settings keys. `never_saved_through_the_form` is true while every row still has
 *       updated_by = NULL and updated_at = created_at: nobody has ever saved this table from the admin screen.
 *   snapshot <tag>
 *       Writes every `site.*` row to storage/app/contact-settings-qa/<tag>.json (once per tag; a later run replays).
 *   diff <tag>
 *       Read-only. Compares the live rows with the snapshot: changed / added / missing keys, values included.
 *   restore <tag> go
 *       Puts every snapshot row back exactly (value, is_public, updated_by, updated_at). Never deletes a row, and never
 *       touches a key that is not in the snapshot. Prints the diff afterwards (which must be empty).
 *   cleanup <tag>
 *       Deletes the snapshot file (and the folder once empty).
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
ini_set('display_errors', 'stderr');

const QA_DOMAIN_ROOT = '/home/u951246149/domains/provatferi.org';
$APP = getenv('QA_APP') ?: (is_dir(QA_DOMAIN_ROOT.'/laravel-admin') ? QA_DOMAIN_ROOT.'/laravel-admin' : dirname(__DIR__, 2));
$STATE = $APP.'/storage/app/contact-settings-qa';
$mode = $argv[1] ?? '';
$tag = (string) ($argv[2] ?? '');

function out(array $data, int $exit = 0): never
{
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE), "\n";
    exit($exit);
}

if (! in_array($mode, ['inventory', 'snapshot', 'diff', 'restore', 'cleanup'], true)) {
    fwrite(STDERR, "modes: inventory | snapshot <tag> | diff <tag> | restore <tag> go | cleanup <tag>\n");
    exit(2);
}
if ($mode !== 'inventory' && preg_match('/^[a-z0-9]{6,24}$/', $tag) !== 1) {
    out(['error' => "usage: {$mode} <tag: 6-24 of a-z0-9>"], 2);
}

define('LARAVEL_START', microtime(true));
define('LARAVEL_PUBLIC_PATH_OVERRIDE', $APP.'/public');
require $APP.'/vendor/autoload.php';
$laravel = require $APP.'/bootstrap/app.php';
$laravel->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

/** @return array<string, array<string, mixed>> */
function siteRows(): array
{
    $rows = [];
    foreach (DB::table('settings')->where('key', 'like', 'site.%')->orderBy('key')->get() as $row) {
        $rows[$row->key] = [
            'value' => $row->value, 'value_type' => $row->value_type, 'group_name' => $row->group_name,
            'is_public' => (bool) $row->is_public, 'updated_by' => $row->updated_by,
            'created_at' => $row->created_at, 'updated_at' => $row->updated_at,
        ];
    }

    return $rows;
}

/** @param array<string, array<string, mixed>> $then @param array<string, array<string, mixed>> $now */
function diffRows(array $then, array $now): array
{
    $changed = [];
    foreach ($then as $key => $old) {
        if (! isset($now[$key])) {
            continue;
        }
        foreach (['value', 'is_public', 'updated_by', 'updated_at'] as $field) {
            if ($old[$field] != $now[$key][$field]) {
                $changed[$key][$field] = ['was' => $old[$field], 'now' => $now[$key][$field]];
            }
        }
    }

    return [
        'changed' => $changed,
        'added' => array_values(array_diff(array_keys($now), array_keys($then))),
        'missing' => array_values(array_diff(array_keys($then), array_keys($now))),
    ];
}

$file = $STATE.'/'.$tag.'.json';

switch ($mode) {
    case 'inventory':
        $rows = siteRows();
        $others = DB::table('settings')->where('key', 'not like', 'site.%')->orderBy('key')->pluck('key')->all();
        $touched = array_filter($rows, fn (array $r) => $r['updated_by'] !== null || $r['updated_at'] !== $r['created_at']);
        out([
            'mode' => 'inventory',
            'app_env' => config('app.env'), 'cache_store' => config('cache.default'), 'settings_rows' => DB::table('settings')->count(),
            'never_saved_through_the_form' => $touched === [],
            'rows_with_an_editor_or_a_later_update' => array_keys($touched),
            'site_rows' => $rows,
            'other_settings_keys' => $others,
        ]);

    case 'snapshot':
        if (is_file($file)) {
            out(['replayed_first_run' => true, 'snapshot' => $tag, 'rows' => count(json_decode((string) file_get_contents($file), true) ?: [])]);
        }
        if (! is_dir($STATE)) {
            mkdir($STATE, 0700, true);
        }
        $rows = siteRows();
        file_put_contents($file, json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
        out(['mode' => 'snapshot', 'tag' => $tag, 'rows' => count($rows), 'keys' => array_keys($rows)]);

    case 'diff':
    case 'restore':
        $then = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        if (! is_array($then)) {
            out(['error' => "no snapshot named {$tag}"], 1);
        }
        $restored = [];
        if ($mode === 'restore') {
            if (($argv[3] ?? '') !== 'go') {
                out(['error' => 'restore refuses to run without the word "go"'], 2);
            }
            foreach ($then as $key => $old) {
                $affected = DB::table('settings')->where('key', $key)->update([
                    'value' => $old['value'], 'is_public' => $old['is_public'] ? 1 : 0, 'updated_by' => $old['updated_by'], 'updated_at' => $old['updated_at'],
                ]);
                if ($affected > 0) {
                    $restored[] = $key;
                }
                // The model's own cache entry for this key (App\Models\Setting::get) must not outlive a direct write.
                Illuminate\Support\Facades\Cache::forget('setting:'.$key);
            }
        }
        $diff = diffRows($then, siteRows());
        out(['mode' => $mode, 'tag' => $tag, 'rows_written' => count($restored)] + $diff + ['identical_to_snapshot' => $diff['changed'] === [] && $diff['missing'] === []]);

    case 'cleanup':
        $existed = is_file($file);
        if ($existed) {
            unlink($file);
        }
        if (is_dir($STATE) && (glob($STATE.'/*') ?: []) === []) {
            rmdir($STATE);
        }
        out(['mode' => 'cleanup', 'tag' => $tag, 'snapshot_removed' => $existed, 'state_dir_left' => is_dir($STATE)]);
}
