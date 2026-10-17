<?php
/**
 * PRODUCTION ACCEPTANCE KIT for two-step verification (docs/MFA_SECURITY.md). CLI only: 404 otherwise. Upload it under a
 * one-off name (`php deploy/qa/cron-guard.php new-session`) and run each mode from a cron. It prints NO secret, code,
 * recovery code, password, token or session id — counts, ids, flags and timestamps only. The browser half is
 * institutional/scripts/mfa-qa.mjs.
 *
 * It touches ONLY QA data, recognised by its marker:
 *   - a user whose e-mail starts with "qa-mfa-" (soft-deleted ones too), with its role links, sessions, API tokens and
 *     password-reset rows;
 *   - a role whose name starts with "QA-MFA-" (slug "qa-mfa-…"), with its permission links;
 *   - the audit rows about or by those users, and the role-change rows that name a QA role;
 *   - sessions whose browser string carries "provatferi-mfa-qa/" (the browser kit's own, signed in or not).
 * The owner's account, real roles and real audit rows are never matched; `diff` proves they did not change.
 *
 *   audit
 *       Read-only. The authentication configuration (no secret values), the schema and migrations, the two-step state of the
 *       install in counts (who uses it, which roles require it, the privileged rule), API tokens and sessions in counts, and
 *       the server clock against Cloudflare's.
 *   inventory
 *       Read-only. The QA users (id, e-mail, status, two-step on/off, recovery codes left, required), roles and leftovers.
 *   snapshot <tag> | diff <tag> | purge <tag> [go] | forget <tag>
 *       The baseline of everything that is NOT QA data (a fingerprint of every real account's two-step columns among it),
 *       the comparison with it, the deletion of the QA data (a dry run without "go"), and the removal of the snapshot.
 *   reset-qa <email>
 *       The break-glass command (php artisan mfa:reset … --force) for a QA account ONLY: it refuses any other address and
 *       any account that holds a role other than a QA role.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
ini_set('display_errors', 'stderr');

const QA_DOMAIN_ROOT = '/home/u951246149/domains/provatferi.org';
const QA_EMAIL_PREFIX = 'qa-mfa-';
const QA_ROLE_PREFIX = 'QA-MFA-';
const QA_ROLE_SLUG_PREFIX = 'qa-mfa-';
const QA_BROWSER_MARK = 'provatferi-mfa-qa/';
$APP = getenv('QA_APP') ?: (is_dir(QA_DOMAIN_ROOT.'/laravel-admin') ? QA_DOMAIN_ROOT.'/laravel-admin' : dirname(__DIR__, 2));
$STATE = $APP.'/storage/app/mfa-qa';
$mode = $argv[1] ?? '';
$tag = (string) ($argv[2] ?? '');

function out(array $data, int $exit = 0): never
{
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE), "\n";
    exit($exit);
}

const MODES = ['audit', 'inventory', 'snapshot', 'diff', 'purge', 'forget', 'reset-qa'];
if (! in_array($mode, MODES, true)) {
    fwrite(STDERR, "modes: audit | inventory | snapshot <tag> | diff <tag> | purge <tag> go | forget <tag> | reset-qa <email>\n");
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

use App\Models\User;
use App\Services\Mfa\MfaManager;
use App\Support\Mfa\MfaPolicy;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

const MFA_COLUMNS = ['mfa_secret', 'mfa_pending_secret', 'mfa_pending_created_at', 'mfa_recovery_codes', 'mfa_confirmed_at', 'mfa_last_used_step', 'mfa_enrollment_required_at'];

/** @return list<int> */
function qaUserIds(): array
{
    return DB::table('users')->where('email', 'like', QA_EMAIL_PREFIX.'%')->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
}

/** @return list<int> */
function qaRoleIds(): array
{
    return DB::table('roles')->where(fn ($q) => $q->where('name', 'like', QA_ROLE_PREFIX.'%')->orWhere('slug', 'like', QA_ROLE_SLUG_PREFIX.'%'))
        ->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
}

/** @return list<int> */
function qaEventIds(): array
{
    if (! Schema::hasTable('security_events')) {
        return [];
    }
    $users = qaUserIds() ?: [0];

    return DB::table('security_events')
        ->where(fn ($q) => $q->whereIn('user_id', $users)->orWhereIn('actor_id', $users)
            ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(context, '$.role')) LIKE ?", [QA_ROLE_SLUG_PREFIX.'%'])
            ->orWhere('user_agent', 'like', '%'.QA_BROWSER_MARK.'%'))
        ->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
}

/** @return list<string> session ids (never printed) */
function qaSessionIds(): array
{
    if (config('session.driver') !== 'database' || ! Schema::hasTable('sessions')) {
        return [];
    }

    return DB::table('sessions')
        ->where(fn ($q) => $q->whereIn('user_id', qaUserIds() ?: [0])->orWhere('user_agent', 'like', '%'.QA_BROWSER_MARK.'%'))
        ->pluck('id')->all();
}

function qaTokenCount(): int
{
    return Schema::hasTable('personal_access_tokens')
        ? DB::table('personal_access_tokens')->where('tokenable_type', User::class)->whereIn('tokenable_id', qaUserIds() ?: [0])->count()
        : 0;
}

function qaResetRows(): int
{
    return DB::table('password_reset_tokens')->where('email', 'like', QA_EMAIL_PREFIX.'%')->count();
}

/** Two-step facts of one account, without any secret. */
function mfaFacts(User $user): array
{
    return [
        'id' => $user->id,
        'status' => $user->status,
        'mfa_enabled' => $user->hasMfaEnabled(),
        'pending_enrolment' => $user->getRawOriginal('mfa_pending_secret') !== null,
        'recovery_codes_left' => app(MfaManager::class)->remainingRecoveryCodes($user),
        'required' => MfaPolicy::isRequired($user),
        'reasons' => array_column(MfaPolicy::requirementReasons($user), 'reason'),
        'enrolment_required_by_reset' => $user->mfa_enrollment_required_at !== null,
    ];
}

/** What a diff compares: everything that is not QA data. */
function state(): array
{
    $qaUsers = qaUserIds();
    $qaRoles = qaRoleIds();
    $qaEvents = qaEventIds();

    $users = [];
    $query = DB::table('users')->orderBy('id');
    if ($qaUsers !== []) {
        $query->whereNotIn('id', $qaUsers);
    }
    foreach ($query->get() as $row) {
        $mfa = [];
        foreach (MFA_COLUMNS as $column) {
            $mfa[$column] = $row->{$column} ?? null;
        }
        // A fingerprint of the two-step columns (ciphertext included): it changes if ANYTHING about a real account's
        // two-step state changes (enrolled, pending, reset), and reveals nothing.
        $users[(string) $row->id] = [$row->status, $row->ui_locale ?? null, $row->deleted_at, sha1((string) json_encode($mfa)), sha1((string) $row->password), sha1((string) $row->remember_token)];
    }

    $roleQuery = DB::table('roles')->orderBy('id');
    if ($qaRoles !== []) {
        $roleQuery->whereNotIn('id', $qaRoles);
    }
    $roles = [];
    foreach ($roleQuery->get() as $role) {
        $roles[(string) $role->id] = [$role->slug, (bool) ($role->mfa_required ?? false)];
    }

    $links = DB::table('role_permissions')->orderBy('role_id')->orderBy('permission_id')->get(['role_id', 'permission_id'])
        ->reject(fn ($r) => in_array((int) $r->role_id, $qaRoles, true))->map(fn ($r) => $r->role_id.':'.$r->permission_id)->values()->all();
    $userRoles = DB::table('user_roles')->orderBy('user_id')->orderBy('role_id')->get(['user_id', 'role_id'])
        ->reject(fn ($r) => in_array((int) $r->user_id, $qaUsers, true) || in_array((int) $r->role_id, $qaRoles, true))
        ->map(fn ($r) => $r->user_id.':'.$r->role_id)->values()->all();

    $events = ['rows' => 0, 'max_id' => 0];
    if (Schema::hasTable('security_events')) {
        $real = DB::table('security_events');
        if ($qaEvents !== []) {
            $real->whereNotIn('id', $qaEvents);
        }
        $events = ['rows' => (clone $real)->count(), 'max_id' => (int) (clone $real)->max('id')];
    }

    return [
        'users' => $users,
        'roles' => $roles,
        'role_permission_links' => sha1((string) json_encode($links)),
        'user_role_links' => sha1((string) json_encode($userRoles)),
        'security_settings' => DB::table('settings')->where('key', 'like', 'security.%')->orderBy('key')->get(['key', 'value'])->map(fn ($r) => $r->key.'='.$r->value)->all(),
        'security_events' => $events,
        'api_tokens_not_qa' => Schema::hasTable('personal_access_tokens')
            ? DB::table('personal_access_tokens')->where(fn ($q) => $q->where('tokenable_type', '!=', User::class)->orWhereNotIn('tokenable_id', $qaUsers ?: [0]))->count() : 0,
        'password_reset_rows_not_qa' => DB::table('password_reset_tokens')->where('email', 'not like', QA_EMAIL_PREFIX.'%')->count(),
    ];
}

/** @return array<string, mixed> */
function leftovers(): array
{
    return [
        'qa_users' => qaUserIds(),
        'qa_roles' => qaRoleIds(),
        'qa_security_events' => count(qaEventIds()),
        'qa_sessions' => count(qaSessionIds()),
        'qa_api_tokens' => qaTokenCount(),
        'qa_password_reset_rows' => qaResetRows(),
    ];
}

/** Server time against Cloudflare's (an NTP-synchronised reference), in seconds. */
function clockCheck(): array
{
    $before = microtime(true);
    $context = stream_context_create(['http' => ['timeout' => 6, 'header' => "User-Agent: provatferi-mfa-qa-kit\r\n"]]);
    $trace = @file_get_contents('https://www.cloudflare.com/cdn-cgi/trace', false, $context);
    $after = microtime(true);
    $reference = null;
    if (is_string($trace) && preg_match('/^ts=([0-9.]+)$/m', $trace, $m) === 1) {
        $reference = (float) $m[1];
    }
    $server = ($before + $after) / 2;

    return [
        'server_utc' => gmdate('Y-m-d\TH:i:s\Z', (int) $server),
        'php_timezone' => date_default_timezone_get(),
        'app_timezone' => config('app.timezone'),
        'reference' => 'cloudflare cdn-cgi/trace ts',
        'reference_utc' => $reference !== null ? gmdate('Y-m-d\TH:i:s\Z', (int) $reference) : null,
        'server_minus_reference_seconds' => $reference !== null ? round($server - $reference, 2) : null,
        'round_trip_seconds' => round($after - $before, 2),
        'totp_step_now' => intdiv(time(), 30),
    ];
}

/** @return array<string, string|null> */
function packageVersions(array $names): array
{
    $installed = json_decode((string) @file_get_contents(base_path('vendor/composer/installed.json')), true);
    $packages = $installed['packages'] ?? $installed ?? [];
    $versions = array_fill_keys($names, null);
    foreach ($packages as $package) {
        if (isset($package['name']) && array_key_exists($package['name'], $versions)) {
            $versions[$package['name']] = $package['version'] ?? null;
        }
    }

    return $versions;
}

$file = $STATE.'/'.$tag.'.json';

switch ($mode) {
    case 'audit':
        $hasMfa = Schema::hasColumn('users', 'mfa_secret');
        $superAdmins = $hasMfa ? User::query()->whereHas('roles', fn ($q) => $q->where('slug', MfaPolicy::PRIVILEGED_ROLE))->orderBy('id')->get() : collect();
        $active = User::query()->where('status', 'active')->with('roles.permissions')->get();
        $privileged = $hasMfa ? $active->filter(fn (User $u) => MfaPolicy::isPrivileged($u)) : collect();
        $events = Schema::hasTable('security_events')
            ? DB::table('security_events')->select('event', DB::raw('count(*) as n'))->groupBy('event')->pluck('n', 'event')->all() : null;
        out([
            'mode' => 'audit',
            'app' => ['env' => config('app.env'), 'debug' => (bool) config('app.debug'), 'url' => config('app.url'), 'timezone' => config('app.timezone'),
                'php' => PHP_VERSION, 'laravel' => app()->version(), 'path' => base_path()],
            'packages' => packageVersions(['pragmarx/google2fa', 'bacon/bacon-qr-code', 'paragonie/constant_time_encoding', 'dasprid/enum', 'laravel/sanctum']),
            'session' => [
                'driver' => config('session.driver'), 'lifetime_minutes' => config('session.lifetime'), 'expire_on_close' => (bool) config('session.expire_on_close'),
                'encrypt' => (bool) config('session.encrypt'), 'secure' => config('session.secure'), 'http_only' => (bool) config('session.http_only'),
                'same_site' => config('session.same_site'), 'domain' => config('session.domain'), 'cookie' => config('session.cookie'),
            ],
            'auth' => [
                'password_timeout_seconds' => config('auth.password_timeout'), 'hashing_driver' => config('hashing.driver'), 'bcrypt_rounds' => config('hashing.bcrypt.rounds'),
                'password_reset_expire_minutes' => config('auth.passwords.users.expire'), 'password_reset_throttle_seconds' => config('auth.passwords.users.throttle'),
                'sanctum_expiration' => config('sanctum.expiration'), 'trusted_proxies' => 'cloudflare ranges (bootstrap/app.php)',
            ],
            'schema' => [
                'users_mfa_columns' => array_values(array_filter(MFA_COLUMNS, fn ($c) => Schema::hasColumn('users', $c))),
                'roles_mfa_required' => Schema::hasColumn('roles', 'mfa_required'),
                'security_events' => Schema::hasTable('security_events'),
                'migrations_ran' => DB::table('migrations')->where('migration', 'like', '2026_10_17_1000%')->orWhere('migration', 'like', '2026_10_17_1001%')->orWhere('migration', 'like', '2026_10_17_1002%')->pluck('migration')->all(),
            ],
            'accounts' => [
                'staff_total' => User::query()->count(), 'active' => $active->count(), 'inactive' => User::query()->where('status', '!=', 'active')->count(),
                'with_mfa' => $hasMfa ? User::query()->whereNotNull('mfa_confirmed_at')->whereNotNull('mfa_secret')->count() : null,
                'pending_enrolments' => $hasMfa ? User::query()->whereNotNull('mfa_pending_secret')->count() : null,
                'super_admins' => $superAdmins->map(fn (User $u) => mfaFacts($u))->values()->all(),
                'privileged_active' => $privileged->count(),
                'privileged_without_mfa' => $privileged->filter(fn (User $u) => ! $u->hasMfaEnabled())->count(),
                'must_enrol_now' => $hasMfa ? $active->filter(fn (User $u) => MfaPolicy::mustEnrollNow($u))->count() : null,
            ],
            'policy' => [
                'roles_requiring_mfa' => Schema::hasColumn('roles', 'mfa_required') ? DB::table('roles')->where('mfa_required', true)->pluck('slug')->all() : null,
                'privileged_rule_on' => $hasMfa ? MfaPolicy::privilegedEnforcementActive() : null,
            ],
            'api_tokens_by_owner_type' => Schema::hasTable('personal_access_tokens')
                ? DB::table('personal_access_tokens')->select('tokenable_type', DB::raw('count(*) as n'))->groupBy('tokenable_type')->pluck('n', 'tokenable_type')->all() : null,
            'sessions_total' => config('session.driver') === 'database' ? DB::table('sessions')->count() : null,
            'security_events_by_event' => $events,
            'clock' => clockCheck(),
            'qa_leftovers' => leftovers(),
        ]);

    case 'inventory':
        $users = User::withTrashed()->where('email', 'like', QA_EMAIL_PREFIX.'%')->with('roles')->orderBy('id')->get();
        out([
            'mode' => 'inventory',
            'qa_users' => $users->map(fn (User $u) => mfaFacts($u) + ['email' => $u->email, 'roles' => $u->roles->pluck('slug')->all(), 'deleted' => $u->deleted_at !== null])->values()->all(),
            'qa_roles' => DB::table('roles')->whereIn('id', qaRoleIds() ?: [0])->get(['id', 'name', 'slug', 'mfa_required'])->all(),
            'qa_events_by_event' => DB::table('security_events')->whereIn('id', qaEventIds() ?: [0])->select('event', DB::raw('count(*) as n'))->groupBy('event')->pluck('n', 'event')->all(),
            'leftovers' => leftovers(),
            'clock' => clockCheck(),
        ]);

    case 'reset-qa':
        $email = strtolower(trim($tag));
        if (! str_starts_with($email, QA_EMAIL_PREFIX)) {
            out(['error' => 'reset-qa only resets a QA account (qa-mfa-…)'], 2);
        }
        $user = User::query()->where('email', $email)->with('roles')->first();
        if ($user === null) {
            out(['error' => 'no such QA account'], 1);
        }
        $foreign = $user->roles->reject(fn ($r) => str_starts_with((string) $r->slug, QA_ROLE_SLUG_PREFIX))->pluck('slug')->all();
        if ($foreign !== []) {
            out(['error' => 'refused: the account holds a non-QA role', 'roles' => $foreign], 1);
        }
        $before = mfaFacts($user);
        $exit = Artisan::call('mfa:reset', ['email' => $email, '--reason' => 'QA break-glass acceptance test (deploy/qa/mfa-qa.php)', '--force' => true]);
        $after = mfaFacts($user->fresh());
        out([
            'mode' => 'reset-qa', 'email' => $email, 'exit' => $exit, 'command_output' => trim(Artisan::output()),
            'before' => $before, 'after' => $after,
            'sessions_left' => config('session.driver') === 'database' ? DB::table('sessions')->where('user_id', $user->id)->count() : null,
            'api_tokens_left' => DB::table('personal_access_tokens')->where('tokenable_type', User::class)->where('tokenable_id', $user->id)->count(),
            'audit_row' => DB::table('security_events')->where('user_id', $user->id)->where('event', 'mfa.reset_by_administrator')->orderByDesc('id')->first(['id', 'actor_id', 'actor_type', 'created_at']),
        ]);

    case 'snapshot':
        if (is_file($file)) {
            out(['replayed_first_run' => true, 'snapshot' => $tag]);
        }
        if (! is_dir($STATE)) {
            mkdir($STATE, 0700, true);
        }
        $state = state();
        file_put_contents($file, json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
        out(['mode' => 'snapshot', 'tag' => $tag, 'users' => count($state['users']), 'roles' => count($state['roles']),
            'security_events' => $state['security_events'], 'security_settings' => $state['security_settings'], 'qa_leftovers' => leftovers()]);

    case 'diff':
    case 'purge':
        $then = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        if (! is_array($then)) {
            out(['error' => "no snapshot named {$tag}"], 1);
        }

        $purged = null;
        if ($mode === 'purge') {
            $go = ($argv[3] ?? '') === 'go';
            $plan = ['users' => qaUserIds(), 'roles' => qaRoleIds(), 'security_events' => count(qaEventIds()), 'sessions' => count(qaSessionIds()),
                'api_tokens' => qaTokenCount(), 'password_reset_rows' => qaResetRows()];
            if (! $go) {
                out(['mode' => 'purge', 'dry_run' => true, 'would_delete' => $plan, 'hint' => 'run again with the word "go" to delete']);
            }

            $events = qaEventIds();
            $sessions = qaSessionIds();
            $users = qaUserIds();
            $roles = qaRoleIds();
            DB::transaction(function () use ($events, $sessions, $users, $roles): void {
                // Audit rows first: deleting a user would otherwise keep them with user_id set to NULL.
                if ($events !== []) {
                    DB::table('security_events')->whereIn('id', $events)->delete();
                }
                if ($sessions !== []) {
                    DB::table('sessions')->whereIn('id', $sessions)->delete();
                }
                DB::table('password_reset_tokens')->where('email', 'like', QA_EMAIL_PREFIX.'%')->delete();
                if ($users !== []) {
                    DB::table('personal_access_tokens')->where('tokenable_type', User::class)->whereIn('tokenable_id', $users)->delete();
                    DB::table('user_roles')->whereIn('user_id', $users)->delete();
                    DB::table('users')->whereIn('id', $users)->delete();
                }
                if ($roles !== []) {
                    DB::table('user_roles')->whereIn('role_id', $roles)->delete();
                    DB::table('role_permissions')->whereIn('role_id', $roles)->delete();
                    DB::table('roles')->whereIn('id', $roles)->delete();
                }
            });
            $purged = $plan;
        }

        $now = state();
        $changed = [];
        foreach (['users', 'roles'] as $section) {
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
        foreach (['role_permission_links', 'user_role_links', 'security_settings', 'security_events', 'api_tokens_not_qa', 'password_reset_rows_not_qa'] as $section) {
            if (($then[$section] ?? null) != $now[$section]) {
                $changed[$section] = ['was' => $then[$section] ?? null, 'now' => $now[$section]];
            }
        }
        $left = leftovers();
        $clean = $left['qa_users'] === [] && $left['qa_roles'] === [] && $left['qa_security_events'] === 0 && $left['qa_sessions'] === 0
            && $left['qa_api_tokens'] === 0 && $left['qa_password_reset_rows'] === 0;
        out(['mode' => $mode, 'tag' => $tag, 'purged' => $purged, 'differences_from_snapshot' => $changed, 'qa_leftovers' => $left,
            'identical_to_snapshot' => $changed === [] && $clean]);

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
