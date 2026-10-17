<?php
/**
 * PRODUCTION ACCEPTANCE KIT for the Leadership / Key Persons system (docs/LEADERSHIP_SYSTEM.md). CLI only: 404 otherwise.
 * Upload it under a one-off name (`php deploy/qa/cron-guard.php new-session`) and run each mode from a cron. It prints no
 * secret. The browser half is institutional/scripts/leadership-qa.mjs; this half proves what a browser cannot see (the rows
 * and files behind it) and removes the run's data afterwards.
 *
 * It touches ONLY what belongs to a QA run, recognised by a marker the browser kit puts into every name it creates:
 *   - a profile whose name_bn or name_en starts with "QA-LEAD-" (or whose slug starts with "qa-lead-"), its retired slugs
 *     (they cascade with the profile) and its two photo files;
 *   - a user whose e-mail matches qa-lead-*@example.test (soft-deleted ones too) with their sessions and role links;
 *   - a role whose name starts with "QA-LEAD-";
 *   - files in the two leadership photo folders that the baseline snapshot did not list and no profile references.
 * Real profiles (the owner's) are never matched: they carry no marker.
 *
 *   inventory
 *       Read-only. Table presence, profile counts (real / QA), the leadership.* permission rows and the roles holding them
 *       (proof that the data migration ran), QA users and roles, and the photo folders' files.
 *   where [slug]
 *       Read-only. Where each QA photo (or the photo of [slug]) physically is: the uploads root, whether the file lies
 *       INSIDE the running release tree (it must not: a release switch would then take it away), its size and the sibling
 *       folders of the uploads root.
 *   snapshot <tag>
 *       Writes the state of everything that is NOT a QA row to storage/app/leadership-qa/<tag>.json (once per tag; a later
 *       run replays): a hash of every real profile row, the slug history, the permission rows and their role links, every
 *       user's id and language, the roles, and the photo folders' file lists.
 *   diff <tag>
 *       Read-only. Compares the live state with the snapshot and lists what changed. `identical_to_snapshot` is true only
 *       when nothing real changed AND no QA profile, user, role or stray file is left.
 *   purge <tag> go
 *       Deletes the QA data (above) in one transaction, then the photo files, then queues and flushes a public-site
 *       revalidation so the site does not keep a cached QA page. Without the word "go" it only lists what it would delete.
 *   forget <tag>
 *       Deletes the snapshot file (and the folder once empty).
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
ini_set('display_errors', 'stderr');

const QA_DOMAIN_ROOT = '/home/u951246149/domains/provatferi.org';
const QA_MARK = 'QA-LEAD-';
$APP = getenv('QA_APP') ?: (is_dir(QA_DOMAIN_ROOT.'/laravel-admin') ? QA_DOMAIN_ROOT.'/laravel-admin' : dirname(__DIR__, 2));
$STATE = $APP.'/storage/app/leadership-qa';
$mode = $argv[1] ?? '';
$tag = (string) ($argv[2] ?? '');

function out(array $data, int $exit = 0): never
{
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE), "\n";
    exit($exit);
}

const MODES = ['inventory', 'where', 'snapshot', 'diff', 'purge', 'forget'];
if (! in_array($mode, MODES, true)) {
    fwrite(STDERR, "modes: inventory | where [slug] | snapshot <tag> | diff <tag> | purge <tag> go | forget <tag>\n");
    exit(2);
}
if (in_array($mode, ['snapshot', 'diff', 'purge', 'forget'], true) && preg_match('/^[a-z0-9]{6,24}$/', $tag) !== 1) {
    out(['error' => "usage: {$mode} <tag: 6-24 of a-z0-9>"], 2);
}

define('LARAVEL_START', microtime(true));
define('LARAVEL_PUBLIC_PATH_OVERRIDE', $APP.'/public');
require $APP.'/vendor/autoload.php';
$laravel = require $APP.'/bootstrap/app.php';
$laravel->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\PublicSiteRevalidator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

const PUBLIC_FOLDER = 'leadership';
const PRIVATE_FOLDER = 'leadership-originals';

/** @return list<int> */
function qaProfileIds(): array
{
    if (! Schema::hasTable('leadership_profiles')) {
        return [];
    }

    return DB::table('leadership_profiles')
        ->where(fn ($q) => $q->where('name_bn', 'like', QA_MARK.'%')->orWhere('name_en', 'like', QA_MARK.'%')->orWhere('slug', 'like', 'qa-lead-%'))
        ->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
}

/** @return list<int> */
function qaUserIds(): array
{
    return DB::table('users')->where('email', 'like', 'qa-lead-%@example.test')->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
}

/** @return list<int> */
function qaRoleIds(): array
{
    return DB::table('roles')->where('name', 'like', QA_MARK.'%')->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
}

/** @return array<string, int> file name (folder-relative) => size */
function folderFiles(string $disk, string $folder): array
{
    $storage = Storage::disk($disk);
    $files = [];
    foreach ($storage->allFiles($folder) as $path) {
        $files[$path] = (int) $storage->size($path);
    }
    ksort($files);

    return $files;
}

/** @return array<string, array{id: int, roles: list<string>}> */
function permissionRows(): array
{
    $rows = [];
    foreach (DB::table('permissions')->where('module', 'leadership')->orderBy('slug')->get(['id', 'slug']) as $permission) {
        $roles = DB::table('role_permissions')->join('roles', 'roles.id', '=', 'role_permissions.role_id')
            ->where('role_permissions.permission_id', $permission->id)->orderBy('roles.slug')->pluck('roles.slug')->all();
        $rows[$permission->slug] = ['id' => (int) $permission->id, 'roles' => $roles];
    }

    return $rows;
}

/** Paths referenced by every profile that stays (or, with $only, by the given ids). @return list<string> */
function referencedPhotos(array $onlyIds = [], array $exceptIds = []): array
{
    if (! Schema::hasTable('leadership_profiles')) {
        return [];
    }
    $query = DB::table('leadership_profiles')->select('photo_path', 'photo_sm_path');
    if ($onlyIds !== []) {
        $query->whereIn('id', $onlyIds);
    }
    if ($exceptIds !== []) {
        $query->whereNotIn('id', $exceptIds);
    }
    $paths = [];
    foreach ($query->get() as $row) {
        foreach ([$row->photo_path, $row->photo_sm_path] as $path) {
            if (is_string($path) && $path !== '') {
                $paths[] = $path;
            }
        }
    }

    return array_values(array_unique($paths));
}

/** What a diff compares: everything that is not a QA row. */
function state(): array
{
    $qaProfiles = qaProfileIds();
    $qaUsers = qaUserIds();
    $qaRoles = qaRoleIds();
    $has = Schema::hasTable('leadership_profiles');

    $profiles = [];
    $history = ['rows' => 0, 'max_id' => 0];
    if ($has) {
        $query = DB::table('leadership_profiles')->orderBy('id');
        if ($qaProfiles !== []) {
            $query->whereNotIn('id', $qaProfiles);
        }
        foreach ($query->get() as $row) {
            $profiles[(string) $row->id] = sha1((string) json_encode($row));
        }
        $slugs = DB::table('leadership_profile_slugs');
        if ($qaProfiles !== []) {
            $slugs->whereNotIn('leadership_profile_id', $qaProfiles);
        }
        $history = ['rows' => (clone $slugs)->count(), 'max_id' => (int) (clone $slugs)->max('id')];
    }

    $users = [];
    $userQuery = DB::table('users')->orderBy('id');
    if ($qaUsers !== []) {
        $userQuery->whereNotIn('id', $qaUsers);
    }
    foreach ($userQuery->get(['id', 'ui_locale', 'status', 'deleted_at']) as $user) {
        $users[(string) $user->id] = [$user->ui_locale, $user->status, $user->deleted_at];
    }

    $roleQuery = DB::table('roles')->orderBy('id');
    if ($qaRoles !== []) {
        $roleQuery->whereNotIn('id', $qaRoles);
    }
    $roles = $roleQuery->pluck('slug', 'id')->all();

    $rolePermissions = DB::table('role_permissions')->orderBy('role_id')->orderBy('permission_id')->get(['role_id', 'permission_id'])
        ->when($qaRoles !== [], fn ($c) => $c->reject(fn ($r) => in_array((int) $r->role_id, $qaRoles, true)))
        ->map(fn ($r) => $r->role_id.':'.$r->permission_id)->values()->all();

    return [
        'profiles' => $profiles,
        'slug_history' => $history,
        'permissions' => permissionRows(),
        'users' => $users,
        'roles' => $roles,
        'role_permission_links' => sha1(json_encode($rolePermissions)),
        'public_files' => folderFiles('public', PUBLIC_FOLDER),
        'private_files' => folderFiles('uploads_private', PRIVATE_FOLDER),
    ];
}

/** @return array<string, mixed> */
function leftovers(): array
{
    return [
        'qa_profiles' => qaProfileIds(),
        'qa_users' => qaUserIds(),
        'qa_roles' => qaRoleIds(),
    ];
}

$file = $STATE.'/'.$tag.'.json';

switch ($mode) {
    case 'inventory':
        $has = Schema::hasTable('leadership_profiles');
        $qa = qaProfileIds();
        $byStatus = $has ? DB::table('leadership_profiles')->select('status', DB::raw('count(*) as n'))->groupBy('status')->pluck('n', 'status')->all() : [];
        $total = $has ? DB::table('leadership_profiles')->count() : 0;
        out([
            'mode' => 'inventory',
            'app_env' => config('app.env'), 'app_path' => base_path(), 'cache_store' => config('cache.default'), 'session_driver' => config('session.driver'),
            'tables' => ['leadership_profiles' => $has, 'leadership_profile_slugs' => Schema::hasTable('leadership_profile_slugs')],
            'profiles' => ['total' => $total, 'real' => $total - count($qa), 'qa' => count($qa), 'by_status' => $byStatus,
                'featured' => $has ? DB::table('leadership_profiles')->where('featured', true)->count() : 0],
            'slug_history_rows' => Schema::hasTable('leadership_profile_slugs') ? DB::table('leadership_profile_slugs')->count() : 0,
            'leadership_permissions' => permissionRows(),
            'qa_users' => qaUserIds(), 'qa_roles' => qaRoleIds(),
            'public_files' => folderFiles('public', PUBLIC_FOLDER), 'private_files' => folderFiles('uploads_private', PRIVATE_FOLDER),
        ]);

    case 'where':
        $slug = $tag !== '' ? $tag : null;
        $query = DB::table('leadership_profiles');
        if ($slug !== null) {
            $query->where('slug', $slug);
        } else {
            $query->whereIn('id', qaProfileIds() ?: [0]);
        }
        $disk = Storage::disk('public');
        $release = realpath(base_path()) ?: base_path();
        $photos = [];
        foreach ($query->orderBy('id')->get(['id', 'slug', 'photo_path', 'photo_sm_path']) as $row) {
            foreach (['page' => $row->photo_path, 'card' => $row->photo_sm_path] as $kind => $path) {
                if (! is_string($path) || $path === '') {
                    continue;
                }
                $absolute = $disk->path($path);
                $real = is_file($absolute) ? (realpath($absolute) ?: $absolute) : null;
                $photos[] = [
                    'slug' => $row->slug, 'kind' => $kind, 'path' => $path, 'exists' => $real !== null, 'bytes' => $real ? filesize($real) : null,
                    'real_path' => $real, 'inside_release_tree' => $real !== null && str_starts_with($real, $release.DIRECTORY_SEPARATOR),
                ];
            }
        }
        $root = rtrim(public_uploads_root(), '/');
        out([
            'mode' => 'where', 'running_release' => $release, 'uploads_root' => $root,
            'uploads_root_inside_release_tree' => str_starts_with(realpath($root) ?: $root, $release.DIRECTORY_SEPARATOR),
            'uploads_root_folders' => is_dir($root) ? array_values(array_map('basename', glob($root.'/*', GLOB_ONLYDIR) ?: [])) : [],
            'photos' => $photos,
        ]);

    case 'snapshot':
        if (is_file($file)) {
            out(['replayed_first_run' => true, 'snapshot' => $tag, 'profiles' => count((json_decode((string) file_get_contents($file), true) ?: [])['profiles'] ?? [])]);
        }
        if (! is_dir($STATE)) {
            mkdir($STATE, 0700, true);
        }
        $state = state();
        file_put_contents($file, json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
        out(['mode' => 'snapshot', 'tag' => $tag, 'real_profiles' => count($state['profiles']), 'users' => count($state['users']), 'roles' => count($state['roles']),
            'public_files' => count($state['public_files']), 'private_files' => count($state['private_files']), 'permissions' => array_keys($state['permissions'])]);

    case 'diff':
    case 'purge':
        $then = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        if (! is_array($then)) {
            out(['error' => "no snapshot named {$tag}"], 1);
        }

        $purged = null;
        if ($mode === 'purge') {
            $go = ($argv[3] ?? '') === 'go';
            $qaProfiles = qaProfileIds();
            $qaUsers = qaUserIds();
            $qaRoles = qaRoleIds();
            $photoFiles = referencedPhotos($qaProfiles);
            $keepers = referencedPhotos([], $qaProfiles);
            // A file no profile references and the baseline did not list: a replaced or rejected upload that was never cleaned up.
            $stray = [];
            foreach (folderFiles('public', PUBLIC_FOLDER) as $path => $size) {
                if (! in_array($path, $keepers, true) && ! in_array($path, $photoFiles, true) && ! array_key_exists($path, $then['public_files'] ?? [])) {
                    $stray[] = $path;
                }
            }
            $strayPrivate = array_values(array_diff(array_keys(folderFiles('uploads_private', PRIVATE_FOLDER)), array_keys($then['private_files'] ?? [])));

            $plan = ['profiles' => $qaProfiles, 'users' => $qaUsers, 'roles' => $qaRoles, 'photo_files' => $photoFiles, 'stray_public_files' => $stray, 'stray_private_files' => $strayPrivate];
            if (! $go) {
                out(['mode' => 'purge', 'dry_run' => true, 'would_delete' => $plan, 'hint' => 'run again with the word "go" to delete']);
            }

            DB::transaction(function () use ($qaProfiles, $qaUsers, $qaRoles): void {
                if ($qaProfiles !== []) {
                    DB::table('leadership_profile_slugs')->whereIn('leadership_profile_id', $qaProfiles)->delete();
                    DB::table('leadership_profiles')->whereIn('id', $qaProfiles)->delete();
                }
                if ($qaUsers !== []) {
                    if (Schema::hasTable('sessions')) {
                        DB::table('sessions')->whereIn('user_id', $qaUsers)->delete();
                    }
                    if (Schema::hasTable('personal_access_tokens')) {
                        DB::table('personal_access_tokens')->where('tokenable_type', 'App\\Models\\User')->whereIn('tokenable_id', $qaUsers)->delete();
                    }
                    DB::table('user_roles')->whereIn('user_id', $qaUsers)->delete();
                    DB::table('users')->whereIn('id', $qaUsers)->delete();
                }
                if ($qaRoles !== []) {
                    DB::table('user_roles')->whereIn('role_id', $qaRoles)->delete();
                    DB::table('role_permissions')->whereIn('role_id', $qaRoles)->delete();
                    DB::table('roles')->whereIn('id', $qaRoles)->delete();
                }
            });

            $removed = [];
            foreach ($photoFiles as $path) {
                if (Storage::disk('public')->exists($path) && Storage::disk('public')->delete($path)) {
                    $removed[] = $path;
                }
            }
            foreach ($stray as $path) {
                if (Storage::disk('public')->delete($path)) {
                    $removed[] = $path;
                }
            }
            foreach ($strayPrivate as $path) {
                if (Storage::disk('uploads_private')->delete($path)) {
                    $removed[] = 'private:'.$path;
                }
            }

            // Rows were deleted below the model layer, so no observer queued anything: tell the public site once, explicitly.
            $revalidator = app(PublicSiteRevalidator::class);
            $revalidator->queue(PublicSiteRevalidator::LEADERSHIP_TAG);
            $revalidator->flush();

            $purged = ['profiles' => count($qaProfiles), 'users' => count($qaUsers), 'roles' => count($qaRoles), 'files_removed' => $removed];
        }

        $now = state();
        $changed = [];
        foreach (['profiles', 'users', 'roles', 'permissions', 'public_files', 'private_files'] as $section) {
            $before = $then[$section] ?? [];
            $after = $now[$section];
            $diff = [
                'changed' => array_keys(array_filter($before, fn ($value, $key) => array_key_exists($key, $after) && $after[$key] != $value, ARRAY_FILTER_USE_BOTH)),
                'added' => array_values(array_diff(array_keys($after), array_keys($before))),
                'missing' => array_values(array_diff(array_keys($before), array_keys($after))),
            ];
            if ($diff['changed'] !== [] || $diff['added'] !== [] || $diff['missing'] !== []) {
                $changed[$section] = $diff;
            }
        }
        foreach (['slug_history', 'role_permission_links'] as $section) {
            if (($then[$section] ?? null) != $now[$section]) {
                $changed[$section] = ['was' => $then[$section] ?? null, 'now' => $now[$section]];
            }
        }
        $left = leftovers();
        $identical = $changed === [] && $left['qa_profiles'] === [] && $left['qa_users'] === [] && $left['qa_roles'] === [];
        out(['mode' => $mode, 'tag' => $tag, 'purged' => $purged, 'differences_from_snapshot' => $changed, 'qa_leftovers' => $left, 'identical_to_snapshot' => $identical]);

    case 'forget':
        $existed = is_file($file);
        if ($existed) {
            unlink($file);
        }
        if (is_dir($STATE) && (glob($STATE.'/*') ?: []) === []) {
            rmdir($STATE);
        }
        out(['mode' => 'forget', 'tag' => $tag, 'snapshot_removed' => $existed, 'state_dir_left' => is_dir($STATE)]);
}
