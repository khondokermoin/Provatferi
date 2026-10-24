<?php
/**
 * PRODUCTION AUDIT / ACCEPTANCE KIT for the Mail Center (docs/MAIL_CENTER.md). CLI only: 404 otherwise.
 * Upload it under a one-off name (`php deploy/qa/cron-guard.php new-session` → `_qa_<id>.php`) and run each mode from a
 * one-shot cron. It prints addresses, counts, booleans and masked identities — NEVER a password, a token, a secret, a
 * message body or an attachment, not even a length.
 *
 *   audit
 *       Read-only. What the live application sees: whether the Mail API token / webhook secret are configured (booleans),
 *       the mailboxes the token can reach and their folder counts (no message is opened), the registered webhooks
 *       (address and events only), roles and their mail permissions, the two-step verification state per role, the
 *       Super Admin count, staff accounts whose name or address looks like a given person (masked), the Leadership
 *       profiles of that name (public data), mail notification / webhook rows, and the mail routes.
 *   rcpt
 *       Read-only. Asks the domain's own MX, in one SMTP session, whether it would accept mail for each institutional
 *       address and for a random address that cannot exist (a catch-all check). The session never reaches DATA:
 *       nothing is sent, nothing is delivered. (Port 25 is closed from the hosting account: run it off the server with
 *       QA_HELO=<a fully-qualified name>.)
 *   dsn-send <tag> <role>[,<role>…]
 *       ONCE per tag. One small, clearly labelled delivery-check message to each named address (roles: public, support,
 *       security, noreply, internal, partnerships), sent through the live SMTP login with a delivery status
 *       notification requested (NOTIFY=SUCCESS,FAILURE). The reports come back to the SMTP login's own mailbox.
 *   dsn-read <tag>
 *       Read-only. Opens the SMTP login's mailbox over IMAP and lists this tag's delivery reports (what the provider did
 *       with each message: delivered into a mailbox, or expanded as an alias, and where) and any test copy that was
 *       delivered into that mailbox itself (the address is an alias of it).
 *   dsn-cleanup <tag>
 *       Deletes exactly this tag's messages (reports and copies) from the SMTP login's mailbox, and the stored reports.
 *       A copy delivered into ANOTHER mailbox cannot be reached from here: the report says which, for its reader.
 *
 * Browser acceptance (institutional/scripts/mail-center-qa.mjs). Everything below touches ONLY QA data, recognised by its
 * marker: users "qa-mail-<tag>-…@example.com", roles "QA-MAIL-<tag> …" (slug "qa-mail-<tag>-…"), registry rows
 * "qa-<tag>-…@<domain>", their grants, sessions and audit rows. The owner's account and real data are never matched.
 *   snapshot <tag> | diff <tag>
 *       A fingerprint of everything that is NOT QA data (users, roles, permissions and their links, mailboxes, grants,
 *       audit and security events) — and the comparison with it after the run, which must show no change.
 *   qa-setup <tag> <hash-file>
 *       ONCE per tag. Two QA registry rows (a "partnerships" and an "internal" one; the provider cannot reach them), three
 *       roles — Partnerships (mail.access, every capability on the QA partnerships row), Mail admins (mail.manage_access,
 *       users.view, users.update), All mailboxes (mail.manage_all) — and one account for each. Passwords arrive only as
 *       bcrypt HASHES, from <hash-file>: a CLI-only PHP file uploaded next to this kit (the browser kit made it; the
 *       passwords never leave the laptop).
 *   qa-inventory <tag>
 *       Read-only: the QA rows and what the run left (grants, audit rows by action, sessions, two-step state — no secret).
 *   qa-cleanup <tag> [go]
 *       Deletes exactly the QA rows above (a dry run without "go").
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
ini_set('display_errors', 'stderr');

const QA_DOMAIN_ROOT = '/home/u951246149/domains/provatferi.org';
$APP = getenv('QA_APP') ?: (is_dir(QA_DOMAIN_ROOT.'/laravel-admin') ? QA_DOMAIN_ROOT.'/laravel-admin' : dirname(__DIR__, 2));
$STATE = $APP.'/storage/app/mail-center-qa';
$mode = $argv[1] ?? '';

function out(array $data, int $exit = 0): never
{
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE), "\n";
    exit($exit);
}

function tagOk(string $tag): bool
{
    return preg_match('/^[a-z0-9]{6,24}$/', $tag) === 1;
}

/** The first run does the work and stores its report; every later run prints that report and does nothing. */
function once(string $state, string $key, callable $work): never
{
    if (! is_dir($state)) {
        mkdir($state, 0700, true);
    }
    $file = $state.'/'.$key.'.json';
    if (is_file($file)) {
        $stored = json_decode((string) file_get_contents($file), true);
        out(['replayed_first_run' => true] + (is_array($stored) ? $stored : ['stored' => 'unreadable']));
    }
    $fh = @fopen($file, 'x');
    if ($fh === false) {
        out(['error' => 'another run holds this key', 'key' => $key], 1);
    }
    try {
        $report = $work();
    } catch (Throwable $e) {
        $report = ['ok' => false, 'error' => $e::class.': '.mb_substr($e->getMessage(), 0, 300)];
    }
    fwrite($fh, (string) json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));
    fclose($fh);
    out($report);
}

/** @return array{host: string, port: int, user: string, pass: string} */
function smtpSettings(): array
{
    $smtp = (array) config('mail.mailers.smtp');

    return ['host' => (string) ($smtp['host'] ?? ''), 'port' => (int) ($smtp['port'] ?? 465), 'user' => (string) ($smtp['username'] ?? ''), 'pass' => (string) ($smtp['password'] ?? '')];
}

function tls(string $host, int $port)
{
    $errno = 0;
    $errstr = '';
    $s = @stream_socket_client("ssl://{$host}:{$port}", $errno, $errstr, 25, STREAM_CLIENT_CONNECT,
        stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => $host]]));
    if ($s === false) {
        throw new RuntimeException("connect {$host}:{$port} failed: {$errno} {$errstr}");
    }
    stream_set_timeout($s, 40);

    return $s;
}

/** A minimal IMAP client (the one deploy/qa/mail-roles-qa.php uses): one TLS connection, literals read in full, never echoes what it sends. */
final class Imap
{
    private $s;

    private int $n = 0;

    /** @var list<string> */
    public array $capabilities = [];

    public function __construct(string $host, int $port)
    {
        $this->s = tls($host, $port);
        $greeting = (string) fgets($this->s);
        if (! str_starts_with($greeting, '* OK')) {
            throw new RuntimeException('IMAP greeting: '.substr($greeting, 0, 60));
        }
    }

    /** @return array{0: string, 1: list<string>} */
    public function cmd(string $command, array $continuations = []): array
    {
        $tag = 'q'.(++$this->n);
        fwrite($this->s, "{$tag} {$command}\r\n");
        $untagged = [];
        while (true) {
            $line = fgets($this->s);
            if ($line === false) {
                throw new RuntimeException('IMAP connection closed during '.strtok($command, ' '));
            }
            while (preg_match('/\{(\d+)\}\r\n$/', $line, $m)) {
                $line .= $this->bytes((int) $m[1]).(string) fgets($this->s);
            }
            if (str_starts_with($line, '+')) {
                fwrite($this->s, (array_shift($continuations) ?? '')."\r\n");

                continue;
            }
            if (str_starts_with($line, $tag.' ')) {
                return [rtrim($line), $untagged];
            }
            $untagged[] = $line;
        }
    }

    public function login(string $user, string $pass): void
    {
        [$status] = $this->cmd('AUTHENTICATE PLAIN', [base64_encode("\0{$user}\0{$pass}")]);
        if (preg_match('/^q\d+ OK/', $status) !== 1) {
            throw new RuntimeException('IMAP login refused');
        }
        [, $caps] = $this->cmd('CAPABILITY');
        foreach ($caps as $line) {
            if (str_starts_with($line, '* CAPABILITY')) {
                $this->capabilities = explode(' ', strtoupper(trim(substr($line, 13))));
            }
        }
    }

    /** @return list<string> */
    public function folders(): array
    {
        [, $lines] = $this->cmd('LIST "" "*"');
        $folders = [];
        foreach ($lines as $line) {
            if (preg_match('/^\* LIST \(([^)]*)\) (?:"[^"]*"|NIL) (?:"((?:[^"\\\\]|\\\\.)*)"|(\S+))\s*$/', rtrim($line), $m) && ! str_contains($m[1], '\\Noselect')) {
                $folders[] = ($m[2] ?? '') !== '' ? stripcslashes($m[2]) : $m[3];
            }
        }

        return $folders;
    }

    /** @return list<int> */
    public function search(string $folder, string $criteria, bool $readOnly = true): array
    {
        [$status] = $this->cmd(($readOnly ? 'EXAMINE ' : 'SELECT ').'"'.addcslashes($folder, '"\\').'"');
        if (preg_match('/^q\d+ OK/', $status) !== 1) {
            return [];
        }
        [, $lines] = $this->cmd("UID SEARCH {$criteria}");
        foreach ($lines as $line) {
            if (str_starts_with($line, '* SEARCH')) {
                return array_values(array_map('intval', array_filter(explode(' ', trim(substr($line, 8))), 'strlen')));
            }
        }

        return [];
    }

    /** The raw header block, or (with $bytes) the first bytes of the whole message. */
    public function fetch(int $uid, ?int $bytes = null): string
    {
        [, $lines] = $this->cmd("UID FETCH {$uid} (".($bytes === null ? 'BODY.PEEK[HEADER]' : "BODY.PEEK[]<0.{$bytes}>").')');
        $all = implode('', $lines);

        return preg_match('/\{\d+\}\r\n(.*)\)\r\n$/s', $all, $m) ? $m[1] : $all;
    }

    public function deleteExactly(array $uids): string
    {
        if ($uids === []) {
            return 'nothing to delete';
        }
        $set = implode(',', $uids);
        $this->cmd("UID STORE {$set} +FLAGS.SILENT (\\Deleted)");
        if (! in_array('UIDPLUS', $this->capabilities, true)) {
            return 'flagged \\Deleted only (no UIDPLUS, so nothing was expunged)';
        }
        [$status] = $this->cmd("UID EXPUNGE {$set}");

        return preg_match('/^q\d+ OK/', $status) === 1 ? 'expunged' : 'expunge refused';
    }

    public function close(): void
    {
        try {
            $this->cmd('LOGOUT');
        } catch (Throwable) {
        }
        fclose($this->s);
    }

    private function bytes(int $n): string
    {
        $buf = '';
        while (strlen($buf) < $n) {
            $chunk = fread($this->s, $n - strlen($buf));
            if ($chunk === false || $chunk === '') {
                throw new RuntimeException('IMAP literal cut short');
            }
            $buf .= $chunk;
        }

        return $buf;
    }
}

/** @return array<string, string> lower-cased header name => unfolded value (first occurrence) */
function headers(string $block): array
{
    $headers = [];
    foreach (preg_split('/\r\n(?![ \t])/', preg_replace('/\r?\n/', "\r\n", $block)) as $line) {
        if (preg_match('/^([A-Za-z0-9-]+):\s*(.*)$/s', $line, $m)) {
            $headers[strtolower($m[1])] ??= trim((string) preg_replace('/\r\n[ \t]+/', ' ', $m[2]));
        }
    }

    return $headers;
}

/** One message over SMTP with a delivery status notification requested. Returns status codes and the queue id; prints no credential. */
function smtpSendWithDsn(array $smtp, string $envelopeFrom, string $to, string $message, string $envid): array
{
    $s = tls($smtp['host'], $smtp['port']);
    $read = static function () use ($s): string {
        $all = '';
        while (($line = fgets($s, 2048)) !== false) {
            $all .= $line;
            if (strlen($line) >= 4 && $line[3] === ' ') {
                break;
            }
        }

        return rtrim($all);
    };
    $cmd = static function (string $line) use ($s, $read): string {
        fwrite($s, $line."\r\n");

        return $read();
    };
    $code = static fn (string $reply): int => (int) substr($reply, 0, 3);
    $codes = ['greeting' => $code($read())];
    $ehlo = $cmd('EHLO '.(parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'localhost'));
    $codes['ehlo'] = $code($ehlo);
    $codes['dsn_offered'] = str_contains($ehlo, 'DSN');
    $codes['auth'] = $code($cmd('AUTH PLAIN '.base64_encode("\0{$smtp['user']}\0{$smtp['pass']}")));
    if ($codes['auth'] !== 235) {
        $cmd('QUIT');
        fclose($s);

        return $codes + ['ok' => false];
    }
    $codes['mail_from'] = $code($cmd("MAIL FROM:<{$envelopeFrom}> RET=HDRS ENVID={$envid}"));
    $rcpt = $cmd("RCPT TO:<{$to}> NOTIFY=SUCCESS,FAILURE ORCPT=rfc822;{$to}");
    $codes['rcpt_to'] = $code($rcpt);
    if ($codes['rcpt_to'] !== 250) {
        $codes['rcpt_reply'] = mb_substr((string) preg_replace('/\s+/', ' ', $rcpt), 0, 200);
        $cmd('QUIT');
        fclose($s);

        return $codes + ['ok' => false];
    }
    $codes['data'] = $code($cmd('DATA'));
    $body = preg_replace('/^\./m', '..', str_replace("\n", "\r\n", str_replace("\r\n", "\n", $message)));
    $final = $cmd($body."\r\n.");
    $codes['queued'] = $code($final);
    $codes['queue_id'] = preg_match('/queued as (\S+)/i', $final, $m) ? $m[1] : null;
    $cmd('QUIT');
    fclose($s);

    return $codes + ['ok' => $codes['queued'] === 250];
}

/** @return array{users: Illuminate\Support\Collection, roles: Illuminate\Support\Collection, mailboxes: Illuminate\Support\Collection} the QA rows of $tag */
function qaRows(string $tag): array
{
    return [
        'users' => App\Models\User::withTrashed()->where('email', 'like', "qa-mail-{$tag}-%@example.com")->get(),
        'roles' => App\Models\Role::query()->where('slug', 'like', "qa-mail-{$tag}-%")->get(),
        'mailboxes' => App\Models\Mailbox::query()->where('address', 'like', "qa-{$tag}-%")->get(),
    ];
}

/** A stable fingerprint of every row that is NOT QA data. @return array<string, mixed> */
function realDataFingerprint(): array
{
    $db = Illuminate\Support\Facades\DB::class;
    $qaUser = fn ($q) => $q->where('email', 'not like', 'qa-mail-%@example.com');
    // remember_token is left out on purpose: it changes whenever a real person signs out, which is not the run's doing.
    $users = $db::table('users')->where($qaUser)->orderBy('id')->get(['id', 'email', 'status', 'password', 'mfa_confirmed_at', 'deleted_at']);
    $roles = $db::table('roles')->where('slug', 'not like', 'qa-mail-%')->orderBy('id')->get(['id', 'slug', 'name', 'mfa_required']);
    $realRoleIds = $roles->pluck('id')->all();
    $realUserIds = $users->pluck('id')->all();
    $boxes = Illuminate\Support\Facades\Schema::hasTable('mailboxes') ? $db::table('mailboxes')->where('address', 'not like', 'qa-%')->orderBy('id')->get(['id', 'address', 'display_name', 'enabled', 'sort_order']) : collect();
    $realBoxIds = $boxes->pluck('id')->all();

    return [
        'users' => ['count' => $users->count(), 'sha' => hash('sha256', json_encode($users))],
        'roles' => ['count' => $roles->count(), 'sha' => hash('sha256', json_encode($roles))],
        'permissions' => ['count' => $db::table('permissions')->count(), 'sha' => hash('sha256', json_encode($db::table('permissions')->orderBy('id')->get(['id', 'slug'])))],
        'role_permissions' => hash('sha256', json_encode($db::table('role_permissions')->whereIn('role_id', $realRoleIds)->orderBy('role_id')->orderBy('permission_id')->get(['role_id', 'permission_id']))),
        'user_roles' => hash('sha256', json_encode($db::table('user_roles')->whereIn('user_id', $realUserIds)->orderBy('user_id')->orderBy('role_id')->get(['user_id', 'role_id']))),
        'mailboxes' => ['count' => $boxes->count(), 'sha' => hash('sha256', json_encode($boxes))],
        'grants' => Illuminate\Support\Facades\Schema::hasTable('mailbox_grants') ? hash('sha256', json_encode($db::table('mailbox_grants')->whereIn('mailbox_id', $realBoxIds)->orderBy('id')->get())) : null,
        'mail_audit_real' => Illuminate\Support\Facades\Schema::hasTable('mail_audit_events') ? $db::table('mail_audit_events')->where(fn ($q) => $q->whereNull('actor_id')->orWhereIn('actor_id', $realUserIds))->where(fn ($q) => $q->whereNull('mailbox_id')->orWhereIn('mailbox_id', $realBoxIds))->count() : null,
        'security_events_real' => $db::table('security_events')->where(fn ($q) => $q->whereNull('user_id')->orWhereIn('user_id', $realUserIds))->where(fn ($q) => $q->whereNull('actor_id')->orWhereIn('actor_id', $realUserIds))->count(),
        'settings' => hash('sha256', json_encode($db::table('settings')->orderBy('key')->get(['key', 'value']))),
    ];
}

/** role => address: the five institutional roles from config, plus the departmental mailbox this task is about. */
function auditTargets(): array
{
    $targets = [];
    foreach (['public', 'support', 'security', 'noreply', 'internal'] as $role) {
        $targets[$role] = strtolower((string) config("institution.email.{$role}"));
    }
    $targets['partnerships'] = 'partnerships@'.institutionDomain();

    return $targets;
}

function bootLaravel(string $app): void
{
    define('LARAVEL_START', microtime(true));
    define('LARAVEL_PUBLIC_PATH_OVERRIDE', $app.'/public');
    require $app.'/vendor/autoload.php';
    $laravel = require $app.'/bootstrap/app.php';
    $laravel->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
}

/** "forhad.h@example.com" → "fo***@ex***.com": enough to recognise an account, not enough to quote it. */
function maskEmail(?string $email): ?string
{
    if ($email === null || ! str_contains($email, '@')) {
        return $email === null ? null : '***';
    }
    [$local, $domain] = explode('@', strtolower($email), 2);
    $dot = strrpos($domain, '.');
    $host = $dot === false ? $domain : substr($domain, 0, $dot);
    $tld = $dot === false ? '' : substr($domain, $dot);

    return mb_substr($local, 0, 2).'***@'.mb_substr($host, 0, 2).'***'.$tld;
}

/** A provider identifier in a form that can be compared between runs but is not the identifier itself. */
function fingerprint(?string $value): ?string
{
    return $value === null ? null : substr(hash('sha256', $value), 0, 10);
}

/** The institution's own domain, from the configured public contact (never a literal in this kit's logic). */
function institutionDomain(): string
{
    $public = (string) config('institution.email.public');

    return strtolower(substr($public, strrpos($public, '@') + 1));
}

switch ($mode) {
    case 'audit':
        bootLaravel($APP);
        $report = ['mode' => 'audit', 'app_url' => config('app.url'), 'config_cached' => app()->configurationIsCached(), 'env' => app()->environment()];

        // ---------------------------------------------------------------------------------------- provider configuration
        $token = config('services.hostinger_mail.token');
        $report['provider'] = [
            'hostinger_mail_api_token_set' => filled($token),
            'hostinger_mail_webhook_secret_set' => filled(config('services.hostinger_mail.webhook_secret')),
            'sdk_installed' => class_exists(Hostinger\Api\AccountApi::class),
            'service_class' => class_exists(App\Services\HostingerMailService::class),
        ];
        unset($token);
        if ($report['provider']['hostinger_mail_api_token_set']) {
            try {
                $mail = app(App\Services\HostingerMailService::class);
                $scope = [];
                foreach ($mail->accessibleAddresses() as $address) {
                    $row = ['address' => $address, 'resource_fingerprint' => fingerprint((string) ($mail->mailboxes()[$address] ?? ''))];
                    try {
                        $row['folders'] = array_map(fn ($f) => [
                            'path' => method_exists($f, 'getPath') ? $f->getPath() : null,
                            'special_use' => method_exists($f, 'getSpecialUse') ? $f->getSpecialUse() : null,
                            'messages' => method_exists($f, 'getMessageCount') ? $f->getMessageCount() : null,
                            'unread' => method_exists($f, 'getUnreadCount') ? $f->getUnreadCount() : null,
                        ], (array) $mail->listFolders($address));
                    } catch (Throwable $e) {
                        $row['folders_error'] = $e::class.': '.mb_substr($e->getMessage(), 0, 160);
                    }
                    try {
                        $row['webhooks'] = array_map(function ($hook) {
                            $url = method_exists($hook, 'getUrl') ? (string) $hook->getUrl() : '';

                            return [
                                'url' => $url === '' ? null : (parse_url($url, PHP_URL_HOST).parse_url($url, PHP_URL_PATH)),
                                'events' => method_exists($hook, 'getEvents') ? $hook->getEvents() : null,
                                'status' => method_exists($hook, 'getStatus') ? $hook->getStatus() : null,
                            ];
                        }, (array) $mail->listWebhooks($address));
                    } catch (Throwable $e) {
                        $row['webhooks_error'] = $e::class.': '.mb_substr($e->getMessage(), 0, 160);
                    }
                    $scope[] = $row;
                }
                $report['provider']['token_scope'] = $scope;
            } catch (Throwable $e) {
                $report['provider']['api_error'] = $e::class.': '.mb_substr($e->getMessage(), 0, 160);
            }
        }

        // ---------------------------------------------------------------------------------------- institutional roles
        $report['institution_roles'] = (array) config('institution.email');
        $report['mail'] = ['default_mailer' => config('mail.default'), 'from' => config('mail.from.address'), 'smtp_host' => config('mail.mailers.smtp.host'), 'smtp_password_set' => filled(config('mail.mailers.smtp.password'))];

        // ---------------------------------------------------------------------------------------- RBAC + MFA
        $schema = Illuminate\Support\Facades\Schema::class;
        $report['tables'] = [
            'users_columns' => $schema::getColumnListing('users'),
            'mailboxes_table' => $schema::hasTable('mailboxes'),
            'mailbox_grants_table' => $schema::hasTable('mailbox_grants'),
        ];
        $mailPermissions = App\Models\Permission::query()->where('module', 'mail')->orWhere('slug', 'like', 'mail%')->get();
        $report['mail_permissions'] = $mailPermissions->map(fn ($p) => [
            'id' => $p->id, 'slug' => $p->slug, 'module' => $p->module, 'action' => $p->action,
            'roles' => Illuminate\Support\Facades\DB::table('role_permissions')->join('roles', 'roles.id', '=', 'role_permissions.role_id')
                ->where('role_permissions.permission_id', $p->id)->pluck('roles.slug')->all(),
        ])->values()->all();
        $report['roles'] = App\Models\Role::query()->withCount('users')->with('permissions:id,slug')->orderBy('id')->get()->map(fn ($r) => [
            'id' => $r->id, 'slug' => $r->slug, 'name' => $r->name, 'system' => (bool) $r->is_system_role,
            'mfa_required' => (bool) $r->mfa_required, 'users' => $r->users_count, 'permissions' => $r->permissions->count(),
            'mail_permissions' => $r->permissions->pluck('slug')->filter(fn ($s) => str_starts_with($s, 'mail.'))->values()->all(),
            'privileged_per_mfa_policy' => App\Support\Mfa\MfaPolicy::roleIsPrivileged($r),
        ])->all();
        $users = App\Models\User::query()->with('roles:id,slug')->orderBy('id')->get();
        $report['users'] = [
            'total' => $users->count(),
            'by_status' => $users->countBy('status')->all(),
            'mfa_enabled' => $users->filter->hasMfaEnabled()->count(),
            'without_role' => $users->filter(fn ($u) => $u->roles->isEmpty())->count(),
            'super_admins' => $users->filter(fn ($u) => $u->hasRole('super_admin'))->map(fn ($u) => ['id' => $u->id, 'status' => $u->status, 'mfa' => $u->hasMfaEnabled()])->values()->all(),
            'privileged_rule_enforced' => App\Support\Mfa\MfaPolicy::privilegedEnforcementActive(),
            'accounts' => $users->map(fn ($u) => ['id' => $u->id, 'email' => maskEmail($u->email), 'status' => $u->status, 'roles' => $u->roles->pluck('slug')->all(), 'mfa' => $u->hasMfaEnabled(), 'last_login' => $u->last_login_at?->toDateString()])->values()->all(),
        ];

        // ---------------------------------------------------------------------------------------- the person (read-only)
        $patterns = ['forhad', 'farhad', 'ফরহাদ', 'ফারহাদ'];
        $matches = App\Models\User::withTrashed()->with('roles:id,slug,name')->where(function ($q) use ($patterns) {
            foreach ($patterns as $p) {
                $q->orWhereRaw('LOWER(name) LIKE ?', ['%'.$p.'%'])->orWhereRaw('LOWER(email) LIKE ?', ['%'.$p.'%']);
            }
        })->get();
        $report['person_lookup'] = [
            'patterns' => $patterns,
            'staff_accounts' => $matches->map(fn ($u) => [
                'id' => $u->id, 'name' => $u->name, 'email' => maskEmail($u->email), 'status' => $u->status, 'deleted' => $u->trashed(),
                'email_verified' => $u->email_verified_at !== null, 'roles' => $u->roles->pluck('slug')->all(), 'mfa' => $u->hasMfaEnabled(),
                'created' => $u->created_at?->toDateString(), 'last_login' => $u->last_login_at?->toDateString(),
            ])->values()->all(),
            'leadership_profiles' => class_exists(App\Models\LeadershipProfile::class) ? App\Models\LeadershipProfile::query()->where(function ($q) use ($patterns) {
                foreach ($patterns as $p) {
                    $q->orWhereRaw('LOWER(name_en) LIKE ?', ['%'.$p.'%'])->orWhere('name_bn', 'like', '%'.$p.'%');
                }
            })->get(['id', 'slug', 'name_en', 'name_bn', 'designation_en', 'designation_bn', 'status'])->toArray() : null,
        ];

        // ---------------------------------------------------------------------------------------- existing mail plumbing
        $report['mail_rows'] = [
            'admin_notifications_by_type' => Illuminate\Support\Facades\DB::table('admin_notifications')->select('type', Illuminate\Support\Facades\DB::raw('count(*) as n'))->groupBy('type')->pluck('n', 'type')->all(),
            'mail_webhook_events' => $schema::hasTable('mail_webhook_events') ? Illuminate\Support\Facades\DB::table('mail_webhook_events')->count() : null,
        ];
        $report['mail_routes'] = collect(app('router')->getRoutes()->getRoutes())
            ->filter(fn ($r) => str_contains(strtolower($r->uri()), 'mail') || str_contains(strtolower((string) $r->getName()), 'mail'))
            ->map(fn ($r) => implode('|', $r->methods()).' '.$r->uri().' '.$r->getName())->values()->all();
        out($report);

    case 'rcpt':
        bootLaravel($APP);
        $domain = institutionDomain();
        $targets = [];
        foreach (['public', 'support', 'security', 'noreply', 'internal'] as $role) {
            $targets[$role] = (string) config("institution.email.{$role}");
        }
        foreach (['partnerships', 'programs', 'fundraising', 'projects'] as $local) {
            $targets[$local] = $local.'@'.$domain;
        }
        $targets['random_nonexistent'] = 'zz-'.bin2hex(random_bytes(6)).'@'.$domain;
        $result = ['mode' => 'rcpt', 'domain' => $domain, 'mx' => [], 'answers' => []];
        $mx = [];
        $weights = [];
        if (getmxrr($domain, $mx, $weights)) {
            array_multisort($weights, SORT_ASC, $mx);
        }
        $result['mx'] = $mx;
        foreach ($mx as $host) {
            $errno = 0;
            $errstr = '';
            $s = @stream_socket_client("tcp://{$host}:25", $errno, $errstr, 15);
            if ($s === false) {
                $result['connect'][$host] = "failed: {$errno} {$errstr}";

                continue;
            }
            stream_set_timeout($s, 20);
            $read = static function () use ($s): string {
                $all = '';
                while (($line = fgets($s, 2048)) !== false) {
                    $all .= $line;
                    if (strlen($line) >= 4 && $line[3] === ' ') {
                        break;
                    }
                }

                return rtrim($all);
            };
            $cmd = static function (string $line) use ($s, $read): string {
                fwrite($s, $line."\r\n");

                return $read();
            };
            $short = static fn (string $reply): string => mb_substr((string) preg_replace('/\s+/', ' ', $reply), 0, 200);
            $result['connect'][$host] = 'ok';
            $result['greeting'] = $short($read());
            // The MX refuses a HELO that is not a fully-qualified name; QA_HELO overrides it when the kit runs off the server.
            $helo = getenv('QA_HELO') ?: (parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'localhost');
            $ehlo = $cmd("EHLO {$helo}");
            if (str_contains($ehlo, 'STARTTLS')) {
                $result['starttls'] = $short($cmd('STARTTLS'));
                $result['tls'] = (bool) @stream_socket_enable_crypto($s, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT);
                $cmd("EHLO {$helo}");
            }
            foreach ($targets as $label => $address) {
                $from = $cmd('MAIL FROM:<>');
                $rcpt = $cmd("RCPT TO:<{$address}>");
                $result['answers'][$label] = ['address' => $address, 'mail_from' => (int) substr($from, 0, 3), 'rcpt' => (int) substr($rcpt, 0, 3), 'reply' => $short($rcpt)];
                $cmd('RSET');
            }
            $cmd('QUIT');
            fclose($s);
            $result['asked'] = $host;
            break;
        }
        $result['note'] = 'RCPT acceptance shows the address exists for delivery (a mailbox, an alias or a forwarder); it cannot tell which. Nothing was sent: no DATA.';
        out($result);

    case 'dsn-send':
        $tag = (string) ($argv[2] ?? '');
        $roles = array_values(array_filter(explode(',', (string) ($argv[3] ?? ''))));
        if (! tagOk($tag) || $roles === []) {
            out(['error' => 'usage: dsn-send <tag> <role>[,<role>…]'], 2);
        }
        bootLaravel($APP);
        once($STATE, "dsn-send-{$tag}", function () use ($tag, $roles) {
            $smtp = smtpSettings();
            if ($smtp['host'] === '' || $smtp['user'] === '' || $smtp['pass'] === '') {
                return ['mode' => 'dsn-send', 'ok' => false, 'error' => 'SMTP host, login or password not configured'];
            }
            $targets = auditTargets();
            $visibleFrom = strtolower((string) config('mail.from.address'));
            $sent = [];
            foreach ($roles as $role) {
                if (! isset($targets[$role])) {
                    $sent[$role] = ['ok' => false, 'error' => 'unknown role'];

                    continue;
                }
                $to = $targets[$role];
                $envid = "{$tag}-{$role}";
                $message = implode("\r\n", [
                    'From: '.mb_encode_mimeheader('Provatferi ERP mail audit', 'UTF-8').' <'.$visibleFrom.'>',
                    'To: <'.$to.'>',
                    "Subject: [ERP MAIL AUDIT {$tag} {$role}] delivery check - safe to delete",
                    'Message-ID: <'.bin2hex(random_bytes(12)).'@'.institutionDomain().'>',
                    'Date: '.date(DATE_RFC2822),
                    'MIME-Version: 1.0',
                    'Content-Type: text/plain; charset=UTF-8',
                    'Content-Transfer-Encoding: 8bit',
                    "X-Provatferi-Mail-Audit: {$tag}",
                    '',
                    "Automated delivery check by the Provatferi ERP mail audit ({$tag}).",
                    'It only records whether this address is delivered into its own mailbox or passed on as an alias.',
                    'Nothing needs to be done. This message can be deleted.',
                ]);
                // The envelope sender is the SMTP login's own mailbox, so every delivery report comes back where this kit can read it.
                $sent[$role] = ['address' => $to, 'envid' => $envid] + smtpSendWithDsn($smtp, $smtp['user'], $to, $message, $envid);
            }

            return ['mode' => 'dsn-send', 'tag' => $tag, 'visible_from' => $visibleFrom, 'reports_return_to' => 'the SMTP login mailbox', 'sent' => $sent, 'ok' => ! in_array(false, array_column($sent, 'ok'), true)];
        });

    case 'dsn-read':
    case 'dsn-cleanup':
        $tag = (string) ($argv[2] ?? '');
        if (! tagOk($tag)) {
            out(['error' => "usage: {$mode} <tag>"], 2);
        }
        bootLaravel($APP);
        $smtp = smtpSettings();
        $imap = new Imap('imap.hostinger.com', 993);
        $imap->login($smtp['user'], $smtp['pass']);
        $report = ['mode' => $mode, 'tag' => $tag, 'mailbox' => 'the SMTP login mailbox', 'uidplus' => in_array('UIDPLUS', $imap->capabilities, true), 'folders' => []];
        foreach ($imap->folders() as $folder) {
            $uids = $imap->search($folder, 'TEXT "'.$tag.'"', $mode === 'dsn-read');
            if ($uids === []) {
                continue;
            }
            if ($mode === 'dsn-cleanup') {
                $report['folders'][$folder] = ['messages' => count($uids), 'result' => $imap->deleteExactly($uids)];

                continue;
            }
            $items = [];
            foreach ($uids as $uid) {
                $h = headers($imap->fetch($uid));
                $item = ['subject' => $h['subject'] ?? null, 'date' => $h['date'] ?? null];
                if (str_contains(strtolower($h['content-type'] ?? ''), 'report-type=delivery-status')) {
                    $raw = $imap->fetch($uid, 24000);
                    foreach (['original-envelope-id', 'original-recipient', 'final-recipient', 'action', 'status', 'remote-mta', 'diagnostic-code', 'reporting-mta'] as $field) {
                        if (preg_match('/^'.preg_quote($field, '/').':\s*([^\r\n]+)/mi', $raw, $m)) {
                            $item['dsn'][$field] = trim($m[1]);
                        }
                    }
                    // The human-readable part of a Postfix report names what happened in plain words.
                    if (preg_match('/[^\r\n]*\b(delivered to (?:mailbox|maildir)|alias expanded|delivery via [^\r\n]*|relayed|forwarded)\b[^\r\n]*/i', $raw, $m)) {
                        $item['dsn']['human_text'] = mb_substr(trim($m[0]), 0, 200);
                    }
                    $item['kind'] = 'delivery report';
                } else {
                    $item['kind'] = 'test copy delivered into the SMTP login mailbox (its address is an alias of it)';
                    foreach (['delivered-to', 'x-original-to', 'to'] as $field) {
                        if (isset($h[$field])) {
                            $item[$field] = $h[$field];
                        }
                    }
                }
                $items[] = $item;
            }
            $report['folders'][$folder] = $items;
        }
        $imap->close();
        if ($mode === 'dsn-cleanup') {
            foreach (glob($STATE.'/*'.$tag.'*.json') ?: [] as $file) {
                unlink($file);
            }
            if (is_dir($STATE) && (glob($STATE.'/*') ?: []) === []) {
                rmdir($STATE);
            }
            $report['state_files_left'] = is_dir($STATE) ? count(glob($STATE.'/*') ?: []) : 0;
        }
        out($report);

    case 'snapshot':
    case 'diff':
        $tag = (string) ($argv[2] ?? '');
        if (! tagOk($tag)) {
            out(['error' => "usage: {$mode} <tag>"], 2);
        }
        bootLaravel($APP);
        $file = $STATE.'/snapshot-'.$tag.'.json';
        if ($mode === 'snapshot') {
            once($STATE, "snapshot-taken-{$tag}", function () use ($file) {
                $print = realDataFingerprint();
                file_put_contents($file, json_encode($print));

                return ['mode' => 'snapshot', 'stored' => true, 'fingerprint' => $print];
            });
        }
        if (! is_file($file)) {
            out(['mode' => 'diff', 'error' => 'no snapshot for this tag'], 1);
        }
        $before = json_decode((string) file_get_contents($file), true);
        $now = realDataFingerprint();
        $changed = [];
        foreach ($now as $key => $value) {
            if (json_encode($value) !== json_encode($before[$key] ?? null)) {
                $changed[$key] = ['before' => $before[$key] ?? null, 'now' => $value];
            }
        }
        out(['mode' => 'diff', 'tag' => $tag, 'identical' => $changed === [], 'changed' => $changed]);

    case 'qa-setup':
        $tag = (string) ($argv[2] ?? '');
        $hashFile = basename((string) ($argv[3] ?? ''));
        if (! tagOk($tag) || preg_match('/^_qa_[a-z0-9]+_h\.php$/', $hashFile) !== 1) {
            out(['error' => 'usage: qa-setup <tag> <_qa_<id>_h.php>'], 2);
        }
        bootLaravel($APP);
        once($STATE, "qa-setup-{$tag}", function () use ($tag, $hashFile) {
            $hashes = require __DIR__.'/'.$hashFile;
            foreach (['limited', 'admin', 'all'] as $kind) {
                if (! is_string($hashes[$kind] ?? null) || ! str_starts_with($hashes[$kind], '$2y$')) {
                    return ['ok' => false, 'error' => "no bcrypt hash for {$kind}"];
                }
            }
            $domain = institutionDomain();
            $made = Illuminate\Support\Facades\DB::transaction(function () use ($tag, $domain, $hashes) {
                $box = function (string $kind, int $order) use ($tag, $domain) {
                    $mailbox = new App\Models\Mailbox(['display_name' => "QA {$tag} ".ucfirst($kind), 'description' => "QA {$tag} — removed after the run", 'enabled' => true, 'sort_order' => $order]);
                    $mailbox->address = "qa-{$tag}-{$kind}@{$domain}";
                    $mailbox->provider = 'hostinger_mail_api';
                    $mailbox->save();

                    return $mailbox;
                };
                $partnerships = $box('partnerships', 9001);
                $internal = $box('internal', 9002);
                $perm = fn (array $slugs) => App\Models\Permission::query()->whereIn('slug', $slugs)->pluck('id')->all();
                $role = function (string $kind, string $name, array $slugs) use ($tag, $perm) {
                    $r = App\Models\Role::query()->create(['name' => "QA-MAIL-{$tag} {$name}", 'slug' => "qa-mail-{$tag}-{$kind}", 'description' => "QA {$tag} — removed after the run", 'is_system_role' => false]);
                    $r->permissions()->sync($perm($slugs));

                    return $r;
                };
                $roles = [
                    'limited' => $role('limited', 'Partnerships', ['mail.access']),
                    'admin' => $role('admin', 'Mail admins', ['mail.manage_access', 'users.view', 'users.update']),
                    'all' => $role('all', 'All mailboxes', ['mail.manage_all']),
                ];
                App\Models\MailboxGrant::query()->create(['mailbox_id' => $partnerships->id, 'role_id' => $roles['limited']->id, 'can_read' => true, 'can_send' => true, 'can_reply' => true, 'can_forward' => true, 'can_manage_state' => true]);
                $users = [];
                foreach ($roles as $kind => $r) {
                    $user = App\Models\User::query()->create(['name' => "QA Mail {$tag} ".ucfirst($kind), 'email' => "qa-mail-{$tag}-{$kind}@example.com", 'password' => Illuminate\Support\Str::random(40), 'status' => 'active', 'ui_locale' => 'bn']);
                    // The bcrypt hash is written as it is (the model's "hashed" cast would hash a hash).
                    Illuminate\Support\Facades\DB::table('users')->where('id', $user->id)->update(['password' => $hashes[$kind], 'email_verified_at' => now()]);
                    $user->roles()->sync([$r->id]);
                    $users[$kind] = ['id' => $user->id, 'email' => $user->email, 'role' => $r->slug];
                }

                return ['mailboxes' => ['partnerships' => $partnerships->id, 'internal' => $internal->id], 'roles' => array_map(fn ($r) => $r->id, $roles), 'users' => $users];
            });

            return ['mode' => 'qa-setup', 'ok' => true, 'tag' => $tag] + $made;
        });

    case 'qa-inventory':
    case 'qa-cleanup':
        $tag = (string) ($argv[2] ?? '');
        $go = ($argv[3] ?? '') === 'go';
        if (! tagOk($tag)) {
            out(['error' => "usage: {$mode} <tag> [go]"], 2);
        }
        bootLaravel($APP);
        $rows = qaRows($tag);
        $userIds = $rows['users']->pluck('id')->all();
        $boxIds = $rows['mailboxes']->pluck('id')->all();
        $roleIds = $rows['roles']->pluck('id')->all();
        $db = Illuminate\Support\Facades\DB::class;
        $audit = $db::table('mail_audit_events')->where(fn ($q) => $q->whereIn('actor_id', $userIds ?: [0])->orWhereIn('mailbox_id', $boxIds ?: [0]));
        $report = [
            'mode' => $mode, 'tag' => $tag,
            'users' => $rows['users']->map(fn ($u) => ['id' => $u->id, 'email' => $u->email, 'mfa' => $u->hasMfaEnabled(), 'roles' => $u->roles()->pluck('slug')->all(), 'deleted' => $u->trashed()])->all(),
            'roles' => $rows['roles']->map(fn ($r) => ['id' => $r->id, 'slug' => $r->slug, 'mfa_required' => (bool) $r->mfa_required, 'permissions' => $r->permissions()->pluck('slug')->all()])->all(),
            'mailboxes' => $rows['mailboxes']->map(fn ($b) => ['id' => $b->id, 'address' => $b->address, 'status' => $b->status, 'enabled' => $b->enabled])->all(),
            'grants' => $db::table('mailbox_grants')->where(fn ($q) => $q->whereIn('mailbox_id', $boxIds ?: [0])->orWhereIn('role_id', $roleIds ?: [0])->orWhereIn('user_id', $userIds ?: [0]))->get(['mailbox_id', 'role_id', 'user_id', 'can_read', 'can_send', 'can_reply', 'can_forward', 'can_manage_state'])->all(),
            'audit_by_action' => (clone $audit)->select('action', $db::raw('count(*) as n'))->groupBy('action')->pluck('n', 'action')->all(),
            'security_events' => $db::table('security_events')->where(fn ($q) => $q->whereIn('user_id', $userIds ?: [0])->orWhereIn('actor_id', $userIds ?: [0]))->count(),
            'sessions' => $db::table('sessions')->whereIn('user_id', $userIds ?: [0])->orWhere('user_agent', 'like', "%provatferi-mail-qa/{$tag}%")->count(),
            'grants_on_real_mailboxes_by_qa_roles' => $db::table('mailbox_grants')->whereNotIn('mailbox_id', $boxIds ?: [0])->where(fn ($q) => $q->whereIn('role_id', $roleIds ?: [0])->orWhereIn('user_id', $userIds ?: [0]))->count(),
        ];
        if ($mode === 'qa-cleanup') {
            $report['dry_run'] = ! $go;
            if ($go) {
                $db::transaction(function () use ($db, $userIds, $boxIds, $roleIds, $audit, $tag) {
                    (clone $audit)->delete();
                    $db::table('security_events')->where(fn ($q) => $q->whereIn('user_id', $userIds ?: [0])->orWhereIn('actor_id', $userIds ?: [0]))->delete();
                    $db::table('sessions')->whereIn('user_id', $userIds ?: [0])->orWhere('user_agent', 'like', "%provatferi-mail-qa/{$tag}%")->delete();
                    $db::table('mailbox_grants')->where(fn ($q) => $q->whereIn('mailbox_id', $boxIds ?: [0])->orWhereIn('role_id', $roleIds ?: [0])->orWhereIn('user_id', $userIds ?: [0]))->delete();
                    $db::table('personal_access_tokens')->where('tokenable_type', App\Models\User::class)->whereIn('tokenable_id', $userIds ?: [0])->delete();
                    $db::table('user_roles')->whereIn('user_id', $userIds ?: [0])->delete();
                    $db::table('role_permissions')->whereIn('role_id', $roleIds ?: [0])->delete();
                    $db::table('password_reset_tokens')->where('email', 'like', "qa-mail-{$tag}-%@example.com")->delete();
                    $db::table('users')->whereIn('id', $userIds ?: [0])->delete();
                    $db::table('roles')->whereIn('id', $roleIds ?: [0])->delete();
                    $db::table('mailboxes')->whereIn('id', $boxIds ?: [0])->delete();
                });
                foreach ($boxIds as $id) {
                    Illuminate\Support\Facades\Cache::forget('mail-center:folders:'.$id);
                }
                $after = qaRows($tag);
                $report['left'] = ['users' => $after['users']->count(), 'roles' => $after['roles']->count(), 'mailboxes' => $after['mailboxes']->count()];
            }
        }
        out($report);

    default:
        out(['error' => 'modes: audit | rcpt | dsn-send <tag> <roles> | dsn-read <tag> | dsn-cleanup <tag> | snapshot <tag> | diff <tag> | qa-setup <tag> <hash-file> | qa-inventory <tag> | qa-cleanup <tag> [go]'], 2);
}
