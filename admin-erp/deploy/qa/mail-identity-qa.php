<?php
/**
 * PROVIDER CAPABILITY PROBE for the Mail Center's institutional IDENTITIES (docs/MAIL_CENTER.md). CLI only: 404 otherwise.
 * Upload it under a one-off name (`php deploy/qa/cron-guard.php new-session` → `_qa_<id>.php`) and run each mode from a
 * one-shot cron. It never prints a password, a token, an SMTP transcript or an authorization header.
 *
 * WHY THIS EXISTS. The Hostinger Mail API sends only as the mailbox the call is made for: its send request carries
 * `displayName`, never a `from`. So the one question that decides whether the Mail Center can offer institutional sender
 * identities at all is whether the provider's SMTP path accepts a From of an ALIAS of the authenticated mailbox, and what
 * the recipient then really sees. That cannot be assumed — it has to be sent and read.
 *
 *   capability
 *       Read-only. What this server can do: whether a Mail API token and a webhook secret are configured (booleans only),
 *       the SMTP host/port/encryption the application is configured with, whether an SMTP login is configured (boolean),
 *       whether that login is the internal mailbox (boolean — the login itself is never printed), and the institutional
 *       identities as config/institution.php defines them.
 *   send-matrix <tag> <outside-address>
 *       ONCE per tag. One small, clearly-labelled test message per institutional identity, each sent through the LIVE
 *       mail transport with that identity as the visible From and Reply-To, to one controlled external inbox. Prints, per
 *       identity, exactly what the transport was handed (From, Reply-To, To, Subject, Message-ID) and the provider's queue
 *       id, or the failure class and message if the provider refused that sender. Nothing else is created or changed; the
 *       application's own automated mail architecture is not touched.
 *   forget <tag>
 *       Removes this tag's stored report from storage/app/mail-identity-qa (and the folder once it is empty), so no kit
 *       state is left under the live application before the next release stages it.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
ini_set('display_errors', 'stderr');

const QA_DOMAIN_ROOT = '/home/u951246149/domains/provatferi.org';
$APP = getenv('QA_APP') ?: (is_dir(QA_DOMAIN_ROOT.'/laravel-admin') ? QA_DOMAIN_ROOT.'/laravel-admin' : dirname(__DIR__, 2));
$STATE = $APP.'/storage/app/mail-identity-qa';
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

function addressOk(string $address): bool
{
    return filter_var($address, FILTER_VALIDATE_EMAIL) !== false && preg_match('/[\x00-\x20]/', $address) !== 1;
}

function bootLaravel(string $app): void
{
    define('LARAVEL_START', microtime(true));
    define('LARAVEL_PUBLIC_PATH_OVERRIDE', $app.'/public');
    require $app.'/vendor/autoload.php';
    $laravel = require $app.'/bootstrap/app.php';
    $laravel->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
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
    $report = $work();
    file_put_contents($file, json_encode($report));
    out($report);
}

/**
 * The institutional identities, built from config/institution.php so no address is hard-coded here. `partnerships` has no
 * config role of its own (it is an alias the Mail Center offers, not an application mail role), so it is derived from the
 * institution's own mail domain.
 *
 * @return array<string, array{address: string, name: string, manual: bool}>
 */
function identities(): array
{
    $role = fn (string $key) => strtolower(trim((string) config('institution.email.'.$key)));
    $internal = $role('internal');
    $domain = str_contains($internal, '@') ? substr(strrchr($internal, '@'), 1) : '';

    $list = [
        'admin' => ['address' => $internal, 'name' => 'Provatferi Administration', 'manual' => true],
        'info' => ['address' => $role('public'), 'name' => 'Provatferi', 'manual' => true],
        'support' => ['address' => $role('support'), 'name' => 'Provatferi Support', 'manual' => true],
        'security' => ['address' => $role('security'), 'name' => 'Provatferi Security', 'manual' => true],
        'partnerships' => ['address' => $domain !== '' ? 'partnerships@'.$domain : '', 'name' => 'Provatferi Partnerships', 'manual' => true],
        // The automated sender. Included so the matrix shows what the recipient sees for it too; it is NOT a manual
        // compose identity and the application's automated architecture is not changed by this probe.
        'no-reply' => ['address' => $role('noreply'), 'name' => (string) config('mail.from.name'), 'manual' => false],
    ];

    return array_filter($list, fn (array $i) => $i['address'] !== '' && addressOk($i['address']));
}

switch ($mode) {
    case 'capability':
        bootLaravel($APP);
        $smtp = (array) config('mail.mailers.smtp');
        $login = (string) config('institution.email.smtp_auth');
        $internal = strtolower(trim((string) config('institution.email.internal')));

        out([
            'mode' => 'capability',
            'app_env' => (string) config('app.env'),
            'mail_api' => [
                // Booleans only: the token itself is never read into any output.
                'token_configured' => filled(config('services.hostinger_mail.token')),
                'webhook_secret_configured' => filled(config('services.hostinger_mail.webhook_secret')),
                'send_supports_alias_from' => false,
                'note' => 'The Mail API send request carries displayName only; it has no from field, so it always sends as the mailbox itself.',
            ],
            'smtp' => [
                'mailer' => (string) config('mail.default'),
                'host' => (string) ($smtp['host'] ?? ''),
                'port' => (int) ($smtp['port'] ?? 0),
                'scheme' => (string) ($smtp['scheme'] ?? ''),
                // The login is never printed, only whether it exists and whether it is the internal mailbox.
                'login_configured' => $login !== '',
                'login_is_internal_mailbox' => $login !== '' && strtolower(trim($login)) === $internal,
            ],
            'identities' => array_map(fn (array $i, string $key) => [
                'key' => $key, 'address' => $i['address'], 'display_name' => $i['name'], 'manual_compose_identity' => $i['manual'],
            ], identities(), array_keys(identities())),
        ]);

        // no break — out() exits.
    case 'send-matrix':
        $tag = (string) ($argv[2] ?? '');
        $outside = (string) ($argv[3] ?? '');
        if (! tagOk($tag) || ! addressOk($outside)) {
            out(['error' => 'usage: send-matrix <tag> <outside-address>'], 2);
        }
        bootLaravel($APP);
        once($STATE, "send-matrix-{$tag}", function () use ($tag, $outside) {
            $sent = [];
            Illuminate\Support\Facades\Event::listen(Illuminate\Mail\Events\MessageSent::class, function ($event) use (&$sent) {
                $m = $event->sent->getOriginalMessage();
                $list = fn (array $addresses) => array_map(fn ($a) => $a->toString(), $addresses);
                $sent[] = [
                    'from' => $list($m->getFrom()),
                    'reply_to' => $list($m->getReplyTo()),
                    'to' => $list($m->getTo()),
                    'subject' => $m->getSubject(),
                    'message_id' => $event->sent->getMessageId(),
                    // Only the queue id is lifted from the SMTP transcript; the transcript itself holds the AUTH line
                    // and is never printed.
                    'queue_id' => preg_match('/250 [^\r\n]*queued as (\S+)/i', (string) $event->sent->getDebug(), $q) ? $q[1] : null,
                ];
            });

            $results = [];
            foreach (identities() as $key => $identity) {
                $before = count($sent);
                $subject = "[MCID {$tag} {$key}] institutional identity send test";
                $body = "Controlled test of the Provatferi Mail Center's institutional sender identities.\n"
                    ."Identity: {$key} <{$identity['address']}>\n"
                    ."Tag: {$tag}\n\n"
                    ."Nothing is required of the recipient. This message carries no link and no attachment.\n";
                try {
                    Illuminate\Support\Facades\Mail::raw($body, function ($message) use ($identity, $outside, $subject) {
                        $message->from($identity['address'], $identity['name'])
                            ->replyTo($identity['address'], $identity['name'])
                            ->to($outside)
                            ->subject($subject);
                    });
                    $results[] = [
                        'key' => $key, 'address' => $identity['address'], 'manual_compose_identity' => $identity['manual'],
                        'accepted_by_provider' => isset($sent[$before]), 'transport_got' => $sent[$before] ?? null,
                    ];
                } catch (Throwable $e) {
                    // The class and a short message only: a transport exception can quote the conversation.
                    $results[] = [
                        'key' => $key, 'address' => $identity['address'], 'manual_compose_identity' => $identity['manual'],
                        'accepted_by_provider' => false, 'error' => $e::class.': '.mb_substr($e->getMessage(), 0, 200),
                    ];
                }
            }

            return ['mode' => 'send-matrix', 'tag' => $tag, 'sent_at_utc' => gmdate('c'), 'identities' => count($results), 'results' => $results];
        });

        // no break — once() exits.
    case 'forget':
        $tag = (string) ($argv[2] ?? '');
        if (! tagOk($tag)) {
            out(['error' => 'usage: forget <tag>'], 2);
        }
        $removed = [];
        foreach (glob($STATE.'/*'.$tag.'*.json') ?: [] as $file) {
            unlink($file);
            $removed[] = basename($file);
        }
        if (is_dir($STATE) && (glob($STATE.'/*') ?: []) === []) {
            rmdir($STATE);
        }
        out(['mode' => 'forget', 'tag' => $tag, 'removed' => $removed, 'state_dir_left' => is_dir($STATE)]);

    default:
        out(['error' => 'modes: capability | send-matrix <tag> <outside-address> | forget <tag>'], 2);
}
