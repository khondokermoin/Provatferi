<?php
/**
 * PRODUCTION ACCEPTANCE KIT for the e-mail role architecture (docs/EMAIL_ARCHITECTURE.md). CLI only: 404 otherwise.
 * Upload it under a one-off name (`php deploy/qa/cron-guard.php new-session`) and run each mode from a cron. It never
 * prints a password, a token, a reset link or a message body. Every mode that sends or writes runs ONCE per tag: a
 * later run of the same mode and tag (a cron firing again) only prints the first run's stored report.
 *
 *   send <tag> <outside-address>
 *       One message of every application mail class to <outside-address> (a disposable inbox), built by the real
 *       notification classes and sent through the live mailer. The support, security and internal ones also go to the
 *       internal mailbox: the only mailbox this kit can open, used for the reply test. Prints what the transport was
 *       handed (From, Reply-To, To, Subject, Message-ID) and the provider's queue id.
 *   reply <tag>
 *       Opens the internal mailbox over IMAP with the SMTP login's own credentials, finds this tag's copies there, and
 *       answers each one the way a mail client does (to its Reply-To, else to its From) over SMTP, asking the server
 *       for delivery status notifications (DSN). It only ever writes to the institution's own role addresses.
 *   verify <tag>
 *       Read-only. Lists, per folder of the internal mailbox, this tag's messages: the test copies, the delivery
 *       reports for the replies (what happened to each reply, and where), and any reply that came back to the internal
 *       mailbox itself (there must be none).
 *   subjects-create <tag> <staff-address> <member-address>
 *       A role-less, unverified, inactive ERP account and a bare inactive member record on two disposable addresses, so
 *       that the REAL password-reset flows can be triggered over HTTPS. Nothing else is created.
 *   subjects-cleanup <tag> <staff-address> <member-address>
 *       Deletes exactly those two records (only if they carry this kit's name) and their reset tokens.
 *   wp-mail <wordpress-dir> <outside-address> <tag>
 *       One wp_mail() from that WordPress install, reporting the sender and transport WordPress used.
 *   cleanup <tag>
 *       Deletes this tag's messages and delivery reports from the internal mailbox (UID EXPUNGE of exactly those
 *       messages; nothing is expunged if the server cannot do that) and the kit's stored reports.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
ini_set('display_errors', 'stderr');

const QA_DOMAIN_ROOT = '/home/u951246149/domains/provatferi.org';
const QA_NAME = 'Mail role test';
$APP = getenv('QA_APP') ?: (is_dir(QA_DOMAIN_ROOT.'/laravel-admin') ? QA_DOMAIN_ROOT.'/laravel-admin' : dirname(__DIR__, 2));
$STATE = $APP.'/storage/app/mail-roles-qa';
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

function bootLaravel(string $app): void
{
    define('LARAVEL_START', microtime(true));
    define('LARAVEL_PUBLIC_PATH_OVERRIDE', $app.'/public');
    require $app.'/vendor/autoload.php';
    $laravel = require $app.'/bootstrap/app.php';
    $laravel->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
}

/** @return array{host: string, port: int, user: string, pass: string, implicit: bool} */
function smtpSettings(): array
{
    $smtp = (array) config('mail.mailers.smtp');
    $port = (int) ($smtp['port'] ?? 465);

    return [
        'host' => (string) ($smtp['host'] ?? ''),
        'port' => $port,
        'user' => (string) ($smtp['username'] ?? ''),
        'pass' => (string) ($smtp['password'] ?? ''),
        'implicit' => ($smtp['scheme'] ?? null) === 'smtps' || $port === 465,
    ];
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

/** A minimal IMAP client: one TLS connection, tagged commands, literals read in full. Never echoes what it sends. */
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

    /** @return array{0: string, 1: list<string>} the tagged status line and the untagged responses (literals inlined) */
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
        [$status] = $this->cmd(($readOnly ? 'EXAMINE ' : 'SELECT ').$this->quote($folder));
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

    private function quote(string $value): string
    {
        return '"'.addcslashes($value, '"\\').'"';
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

function bareAddress(?string $value): ?string
{
    if ($value === null || trim($value) === '') {
        return null;
    }

    return strtolower(preg_match('/<([^>]+)>/', $value, $m) ? trim($m[1]) : trim($value));
}

function decoded(?string $value): ?string
{
    return $value === null ? null : (iconv_mime_decode($value, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8') ?: $value);
}

/** One message over SMTP with DSN requested. Returns status codes and the provider's queue id; prints no credential. */
function smtpSendWithDsn(array $smtp, string $from, string $to, string $message, string $envid): array
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
    $ehlo = $cmd('EHLO admin.provatferi.org');
    $codes['ehlo'] = $code($ehlo);
    $codes['dsn_offered'] = str_contains($ehlo, 'DSN');
    $codes['auth'] = $code($cmd('AUTH PLAIN '.base64_encode("\0{$smtp['user']}\0{$smtp['pass']}")));
    if ($codes['auth'] !== 235) {
        $cmd('QUIT');
        fclose($s);

        return $codes + ['ok' => false];
    }
    $codes['mail_from'] = $code($cmd("MAIL FROM:<{$from}> RET=HDRS ENVID={$envid}"));
    $codes['rcpt_to'] = $code($cmd("RCPT TO:<{$to}> NOTIFY=SUCCESS,FAILURE ORCPT=rfc822;{$to}"));
    $codes['data'] = $code($cmd('DATA'));
    $body = preg_replace('/^\./m', '..', str_replace("\n", "\r\n", str_replace("\r\n", "\n", $message)));
    $final = $cmd($body."\r\n.");
    $codes['queued'] = $code($final);
    $codes['queue_id'] = preg_match('/queued as (\S+)/i', $final, $m) ? $m[1] : null;
    $cmd('QUIT');
    fclose($s);

    return $codes + ['ok' => $codes['queued'] === 250];
}

switch ($mode) {
    case 'wp-mail':
        [$dir, $to, $tag] = [rtrim((string) ($argv[2] ?? ''), '/'), (string) ($argv[3] ?? ''), (string) ($argv[4] ?? '')];
        if (! is_file($dir.'/wp-load.php') || ! addressOk($to) || ! tagOk($tag)) {
            out(['error' => 'usage: wp-mail <wordpress-dir> <outside-address> <tag>'], 2);
        }
        once($STATE, "wp-{$tag}-".preg_replace('/[^a-z0-9]/', '', strtolower(basename($dir))), function () use ($dir, $to, $tag) {
            $_SERVER['HTTP_HOST'] ??= 'localhost';
            define('WP_USE_THEMES', false);
            require $dir.'/wp-load.php';
            $error = null;
            $transport = null;
            add_action('wp_mail_failed', function ($e) use (&$error) {
                $error = mb_substr($e->get_error_message(), 0, 200);
            });
            add_action('phpmailer_init', function ($m) use (&$transport) {
                $transport = ['mailer' => $m->Mailer, 'from' => $m->From, 'from_name' => $m->FromName, 'envelope_sender' => $m->Sender ?: null, 'reply_to' => array_keys($m->getReplyToAddresses()), 'smtp_host' => $m->Mailer === 'smtp' ? $m->Host : null];
            }, PHP_INT_MAX);
            $site = basename($dir);
            $sent = wp_mail($to, "[MAIL TEST {$tag} wp-{$site}] WordPress sender check", "Controlled test of the sender identity this WordPress install uses (e-mail role audit {$tag}). It can be deleted.");

            return ['mode' => 'wp-mail', 'site' => home_url(), 'wp_mail_returned' => $sent, 'transport' => $transport, 'error' => $error];
        });

    case 'send':
        [$tag, $outside] = [(string) ($argv[2] ?? ''), (string) ($argv[3] ?? '')];
        if (! tagOk($tag) || ! addressOk($outside)) {
            out(['error' => 'usage: send <tag: 6-24 of a-z0-9> <outside-address>'], 2);
        }
        bootLaravel($APP);
        once($STATE, "send-{$tag}", function () use ($tag, $outside) {
            $internal = App\Support\InstitutionMail::internalRecipient();
            $sent = [];
            Illuminate\Support\Facades\Event::listen(Illuminate\Mail\Events\MessageSent::class, function ($event) use (&$sent) {
                $m = $event->sent->getOriginalMessage();
                $list = fn (array $addresses) => array_map(fn ($a) => $a->toString(), $addresses);
                $sent[] = [
                    'to' => $list($m->getTo()),
                    'from' => $list($m->getFrom()),
                    'reply_to' => $list($m->getReplyTo()),
                    'subject' => $m->getSubject(),
                    'message_id' => $event->sent->getMessageId(),
                    // Only the queue id is lifted from the SMTP transcript, which is never printed (it holds the AUTH line).
                    'queue_id' => preg_match('/250 [^\r\n]*queued as (\S+)/i', (string) $event->sent->getDebug(), $q) ? $q[1] : null,
                ];
            });

            $fakeLink = 'https://provatferi.org/member/reset-password?token=MAIL-TEST-NOT-A-TOKEN&email=test';
            $cases = [
                'b1-membership-approved' => ['support', fn () => new App\Notifications\MembershipApplicationStatusChangedNotification('APP-TEST', 'approved', null, 'PLCC-TEST', 'invited'), true],
                'b2-member-invitation' => ['support', fn () => new App\Notifications\MemberInvitationNotification($fakeLink, 60, 'PLCC-TEST'), false],
                'c1-volunteer-received' => ['support', fn () => new App\Notifications\VolunteerApplicationReceivedNotification('VOL-TEST', 'Mail role test posting', null), false],
                'd1-profile-reviewed' => ['support', fn () => new App\Notifications\PublicProfileReviewedNotification('approved'), false],
                'e1-security-notice' => ['security', fn () => null, true],
                'i1-internal-notice' => ['internal', fn () => new App\Notifications\VolunteerApplicationSubmittedNotification('VOL-TEST', 'Mail role test posting', 'QA', 'https://admin.provatferi.org/admin/recruitment-applications'), true],
            ];

            $results = [];
            foreach ($cases as $key => [$class, $make, $alsoInternal]) {
                $recipients = $alsoInternal && $internal !== null ? ['outside' => $outside, 'internal' => $internal] : ['outside' => $outside];
                foreach ($recipients as $who => $to) {
                    $before = count($sent);
                    try {
                        $notification = $make();
                        $mail = $notification !== null
                            ? $notification->toMail(new Illuminate\Notifications\AnonymousNotifiable)
                            : App\Support\InstitutionMail::security((new Illuminate\Notifications\Messages\MailMessage)
                                ->subject('নিরাপত্তা বার্তার পরীক্ষা — security notice test')
                                ->greeting('Security route test')
                                ->line('This is a controlled test of the security e-mail route: From no-reply@, Reply-To security@. No action is needed.'));
                        $mail->subject = "[MAIL TEST {$tag} {$key}".($who === 'internal' ? ' internal' : '').'] '.$mail->subject;
                        Illuminate\Support\Facades\Notification::route('mail', $to)->notify(new class($mail) extends Illuminate\Notifications\Notification
                        {
                            public function __construct(private readonly Illuminate\Notifications\Messages\MailMessage $mail) {}

                            public function via(object $notifiable): array
                            {
                                return ['mail'];
                            }

                            public function toMail(object $notifiable): Illuminate\Notifications\Messages\MailMessage
                            {
                                return $this->mail;
                            }
                        });
                        $results[] = ['key' => $key, 'class' => $class, 'recipient' => $who, 'built_by' => $notification !== null ? class_basename($notification) : 'InstitutionMail::security()', 'ok' => isset($sent[$before]), 'transport_got' => $sent[$before] ?? null];
                    } catch (Throwable $e) {
                        $results[] = ['key' => $key, 'class' => $class, 'recipient' => $who, 'ok' => false, 'error' => $e::class.': '.mb_substr($e->getMessage(), 0, 200)];
                    }
                }
            }

            $smtp = smtpSettings();

            return [
                'mode' => 'send', 'tag' => $tag, 'mailer' => config('mail.default'),
                'smtp' => ['host' => $smtp['host'], 'port' => $smtp['port'], 'implicit_tls' => $smtp['implicit'], 'auth_mailbox' => $smtp['user']],
                'identity_problems' => App\Support\InstitutionMail::problems(),
                'results' => $results,
            ];
        });

    case 'reply':
        $tag = (string) ($argv[2] ?? '');
        if (! tagOk($tag)) {
            out(['error' => 'usage: reply <tag>'], 2);
        }
        bootLaravel($APP);
        once($STATE, "reply-{$tag}", function () use ($tag) {
            $smtp = smtpSettings();
            $own = array_filter([
                'support' => App\Support\InstitutionMail::address('support'),
                'security' => App\Support\InstitutionMail::address('security'),
                'noreply' => App\Support\InstitutionMail::address('noreply'),
            ]);
            $imap = new Imap('imap.hostinger.com', 993);
            $imap->login($smtp['user'], $smtp['pass']);
            $replies = [];
            foreach ($imap->search('INBOX', 'SUBJECT "MAIL TEST '.$tag.'"') as $uid) {
                $h = headers($imap->fetch($uid));
                $subject = decoded($h['subject'] ?? null);
                if (! str_contains((string) $subject, ' internal]') || str_starts_with((string) $subject, 'Re:')) {
                    continue;
                }
                // What a mail client does on "Reply": Reply-To when the message has one, otherwise From (RFC 5322 3.6.2).
                $target = bareAddress($h['reply-to'] ?? null) ?? bareAddress($h['from'] ?? null);
                $row = ['subject' => $subject, 'from' => $h['from'] ?? null, 'reply_to' => $h['reply-to'] ?? null, 'reply_goes_to' => $target, 'role' => array_search($target, $own, true) ?: null];
                if ($row['role'] === null) {
                    $row['sent'] = false;
                    $row['why'] = 'not an institutional role address: this kit only replies to the organisation\'s own mailboxes';
                    $replies[] = $row;

                    continue;
                }
                $key = preg_match('/MAIL TEST \S+ (\S+)/', (string) $subject, $k) ? $k[1] : 'x';
                $envid = "{$tag}-{$key}";
                $message = implode("\r\n", [
                    'From: '.mb_encode_mimeheader(QA_NAME.' (reply)', 'UTF-8').' <'.$smtp['user'].'>',
                    'To: <'.$target.'>',
                    'Subject: Re: '.($h['subject'] ?? ''),
                    'In-Reply-To: '.($h['message-id'] ?? ''),
                    'References: '.($h['message-id'] ?? ''),
                    'Message-ID: <'.bin2hex(random_bytes(12)).'@provatferi.org>',
                    'Date: '.date(DATE_RFC2822),
                    'MIME-Version: 1.0',
                    'Content-Type: text/plain; charset=UTF-8',
                    'Content-Transfer-Encoding: 8bit',
                    "X-Provatferi-Mail-Test: {$tag}",
                    '',
                    "Automated reply test from the e-mail role audit ({$tag}): this was sent the way a mail client answers a message, to its Reply-To. It can be deleted.",
                ]);
                $row['smtp'] = smtpSendWithDsn($smtp, $smtp['user'], $target, $message, $envid);
                $row['envid'] = $envid;
                $replies[] = $row;
            }
            $imap->close();

            return ['mode' => 'reply', 'tag' => $tag, 'replier_mailbox' => $smtp['user'], 'replies' => $replies, 'ok' => $replies !== [] && ! in_array(false, array_map(fn ($r) => $r['smtp']['ok'] ?? false, $replies), true)];
        });

    case 'verify':
    case 'cleanup':
        $tag = (string) ($argv[2] ?? '');
        if (! tagOk($tag)) {
            out(['error' => "usage: {$mode} <tag>"], 2);
        }
        bootLaravel($APP);
        $smtp = smtpSettings();
        $imap = new Imap('imap.hostinger.com', 993);
        $imap->login($smtp['user'], $smtp['pass']);
        $report = ['mode' => $mode, 'tag' => $tag, 'mailbox' => $smtp['user'], 'uidplus' => in_array('UIDPLUS', $imap->capabilities, true), 'folders' => []];
        foreach ($imap->folders() as $folder) {
            $uids = $imap->search($folder, 'TEXT "'.$tag.'"', $mode === 'verify');
            if ($uids === []) {
                continue;
            }
            if ($mode === 'cleanup') {
                $report['folders'][$folder] = ['messages' => count($uids), 'result' => $imap->deleteExactly($uids)];

                continue;
            }
            $items = [];
            foreach ($uids as $uid) {
                $h = headers($imap->fetch($uid));
                $item = ['subject' => decoded($h['subject'] ?? null), 'from' => $h['from'] ?? null, 'date' => $h['date'] ?? null];
                if (str_contains(strtolower($h['content-type'] ?? ''), 'report-type=delivery-status')) {
                    $raw = $imap->fetch($uid, 24000);
                    foreach (['original-envelope-id', 'final-recipient', 'action', 'status', 'remote-mta', 'diagnostic-code', 'reporting-mta', 'arrival-date'] as $field) {
                        if (preg_match('/^'.preg_quote($field, '/').':\s*([^\r\n]+)/mi', $raw, $m)) {
                            $item['dsn'][$field] = trim($m[1]);
                        }
                    }
                    $item['kind'] = 'delivery report';
                } elseif (str_starts_with((string) $item['subject'], 'Re:')) {
                    $item['kind'] = 'REPLY RETURNED TO THIS MAILBOX';
                } else {
                    $item['kind'] = 'test copy';
                    $item['reply_to'] = $h['reply-to'] ?? null;
                    $item['authentication_results'] = $h['authentication-results'] ?? null;
                }
                $items[] = $item;
            }
            $report['folders'][$folder] = $items;
        }
        $imap->close();
        if ($mode === 'cleanup') {
            foreach (glob($STATE.'/*'.$tag.'*.json') ?: [] as $file) {
                unlink($file);
            }
            if (is_dir($STATE) && (glob($STATE.'/*') ?: []) === []) {
                rmdir($STATE);
            }
            $report['state_files_left'] = is_dir($STATE) ? count(glob($STATE.'/*') ?: []) : 0;
        }
        out($report);

    case 'subjects-create':
    case 'subjects-cleanup':
        [$tag, $staff, $member] = [(string) ($argv[2] ?? ''), strtolower((string) ($argv[3] ?? '')), strtolower((string) ($argv[4] ?? ''))];
        if (! tagOk($tag) || ! addressOk($staff) || ! addressOk($member) || str_ends_with($staff, '@provatferi.org') || str_ends_with($member, '@provatferi.org')) {
            out(['error' => "usage: {$mode} <tag> <disposable staff address> <disposable member address>"], 2);
        }
        bootLaravel($APP);
        $name = QA_NAME." {$tag} (delete)";
        if ($mode === 'subjects-create') {
            once($STATE, "subjects-{$tag}", function () use ($staff, $member, $name) {
                $user = Illuminate\Database\Eloquent\Model::withoutEvents(fn () => App\Models\User::query()->firstOrCreate(
                    ['email' => $staff],
                    ['name' => $name, 'password' => Illuminate\Support\Facades\Hash::make(Illuminate\Support\Str::random(64)), 'status' => 'inactive'],
                ));
                $record = Illuminate\Database\Eloquent\Model::withoutEvents(fn () => App\Models\Member::query()->firstOrCreate(
                    ['email' => $member],
                    ['name' => $name, 'password' => Illuminate\Support\Str::random(64), 'status' => 'inactive'],
                ));

                return ['mode' => 'subjects-create', 'staff_user_id' => $user->id, 'staff_roles' => $user->roles()->count(), 'staff_verified' => $user->email_verified_at !== null, 'member_id' => $record->id, 'member_code' => $record->member_code, 'ok' => $user->name === $name && $record->name === $name];
            });
        }
        $removed = ['staff' => 0, 'member' => 0, 'staff_tokens' => 0, 'member_tokens' => 0];
        Illuminate\Support\Facades\DB::transaction(function () use ($staff, $member, $name, &$removed) {
            $user = App\Models\User::withTrashed()->where('email', $staff)->first();
            if ($user !== null && $user->name === $name) {
                $user->roles()->detach();
                $user->tokens()->delete();
                $removed['staff'] = (int) Illuminate\Database\Eloquent\Model::withoutEvents(fn () => $user->forceDelete());
            }
            $removed['staff_tokens'] = Illuminate\Support\Facades\DB::table((string) config('auth.passwords.users.table'))->where('email', $staff)->delete();
            $record = App\Models\Member::withTrashed()->where('email', $member)->first();
            if ($record !== null && $record->name === $name) {
                $record->tokens()->delete();
                $removed['member'] = (int) Illuminate\Database\Eloquent\Model::withoutEvents(fn () => $record->forceDelete());
            }
            $removed['member_tokens'] = Illuminate\Support\Facades\DB::table((string) config('auth.passwords.members.table'))->where('email', $member)->delete();
        });
        @unlink($STATE."/subjects-{$tag}.json");
        out(['mode' => 'subjects-cleanup', 'removed' => $removed,
            'left' => ['staff' => App\Models\User::withTrashed()->where('email', $staff)->count(), 'member' => App\Models\Member::withTrashed()->where('email', $member)->count()]]);

    default:
        fwrite(STDERR, "modes: send | reply | verify | cleanup | subjects-create | subjects-cleanup | wp-mail\n");
        exit(2);
}
