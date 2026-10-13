<?php
/**
 * READ-ONLY audit of the mail identities a deployed Provatferi install actually uses (docs/EMAIL_ARCHITECTURE.md).
 * CLI only (404 otherwise); on production upload it under a one-off name `_qa_<hex>.php` and run it from a cron.
 * It prints roles, addresses and booleans — NEVER a password, token or secret, not even a length.
 *
 *   config                 the RESOLVED Laravel mail configuration (cached config included): transport, SMTP host/port/scheme,
 *                          the SMTP authentication mailbox, whether a password is set, From, every Reply-To/role address, the
 *                          public contact setting, whether the Mail API token / webhook secret exist.
 *   smtp-identities        logs in to the configured SMTP server with the configured account and asks, for each institutional
 *                          address, whether it may be used as the envelope sender (MAIL FROM) — then RSET. Nothing is sent:
 *                          the conversation never reaches DATA.
 *   wordpress <dir>        boots the WordPress install in <dir> (e.g. /home/…/public_html/cms) and prints its site name, URL,
 *                          admin e-mail option, the sender WordPress mail would use, mail-related filters and active plugins.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
ini_set('display_errors', 'stderr');

$mode = $argv[1] ?? 'config';
$out = static function (array $data): void {
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)."\n";
};

if ($mode === 'wordpress') {
    $dir = rtrim((string) ($argv[2] ?? ''), '/');
    if ($dir === '' || ! is_file($dir.'/wp-load.php')) {
        fwrite(STDERR, "usage: wordpress <directory holding wp-load.php>\n");
        exit(2);
    }
    $_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'localhost';
    define('WP_USE_THEMES', false);
    require $dir.'/wp-load.php';
    $sitename = wp_parse_url(network_home_url(), PHP_URL_HOST);
    $sitename = is_string($sitename) && str_starts_with($sitename, 'www.') ? substr($sitename, 4) : $sitename;
    $out([
        'mode' => 'wordpress',
        'dir' => $dir,
        'blogname' => get_option('blogname'),
        'home' => home_url(),
        'admin_email' => get_option('admin_email'),
        'default_sender_if_unfiltered' => 'wordpress@'.$sitename,
        'filters' => ['wp_mail_from' => has_filter('wp_mail_from'), 'wp_mail_from_name' => has_filter('wp_mail_from_name'), 'wp_mail' => has_filter('wp_mail'), 'phpmailer_init' => has_action('phpmailer_init')],
        'active_plugins' => array_values((array) get_option('active_plugins')),
        'mu_plugins' => array_keys(get_mu_plugins()),
        'theme' => get_stylesheet(),
        'users' => count_users()['total_users'] ?? null,
    ]);
    exit;
}

$APP = getenv('QA_APP') ?: (is_dir('/home/u951246149/domains/provatferi.org/laravel-admin') ? '/home/u951246149/domains/provatferi.org/laravel-admin' : dirname(__DIR__, 2));
define('LARAVEL_START', microtime(true));
define('LARAVEL_PUBLIC_PATH_OVERRIDE', $APP.'/public');
require $APP.'/vendor/autoload.php';
$app = require $APP.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$smtp = (array) config('mail.mailers.smtp');
$roleAddresses = array_filter([
    'from' => config('mail.from.address'),
    'institution' => config('institution.email'),
    'reply_to' => config('mail.reply_to'),
], fn ($v) => $v !== null);

switch ($mode) {
    case 'config':
        $out([
            'mode' => 'config',
            'app_url' => config('app.url'),
            'config_cached' => app()->configurationIsCached(),
            'default_mailer' => config('mail.default'),
            'queue' => config('queue.default'),
            'smtp' => [
                'host' => $smtp['host'] ?? null,
                'port' => $smtp['port'] ?? null,
                'scheme' => $smtp['scheme'] ?? null,
                'auth_mailbox' => $smtp['username'] ?? null,
                'password_set' => filled($smtp['password'] ?? null),
                'local_domain' => $smtp['local_domain'] ?? null,
            ],
            'from' => ['address' => config('mail.from.address'), 'name' => config('mail.from.name')],
            'role_addresses' => $roleAddresses,
            'public_contact_setting' => class_exists(App\Models\Setting::class) ? App\Models\Setting::get('site.email') : null,
            'hostinger_mail_api_token_set' => filled(config('services.hostinger_mail.token')),
            'hostinger_mail_webhook_secret_set' => filled(config('services.hostinger_mail.webhook_secret')),
            'admin_users_with_admin_at' => App\Models\User::query()->where('email', 'admin@provatferi.org')->count(),
        ]);
        break;

    case 'smtp-identities':
        $host = (string) ($smtp['host'] ?? '');
        $port = (int) ($smtp['port'] ?? 465);
        $user = (string) ($smtp['username'] ?? '');
        $pass = (string) ($smtp['password'] ?? '');
        if ($host === '' || $user === '' || $pass === '') {
            $out(['mode' => 'smtp-identities', 'ok' => false, 'error' => 'SMTP host, username or password not configured']);
            break;
        }
        $implicit = ($smtp['scheme'] ?? null) === 'smtps' || $port === 465;
        $errno = 0;
        $errstr = '';
        $s = @stream_socket_client(($implicit ? 'ssl://' : 'tcp://').$host.':'.$port, $errno, $errstr, 20, STREAM_CLIENT_CONNECT, stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => $host]]));
        if ($s === false) {
            $out(['mode' => 'smtp-identities', 'ok' => false, 'error' => "connect failed: {$errno} {$errstr}"]);
            break;
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
        $code = static fn (string $reply): int => (int) substr($reply, 0, 3);
        $transcript = ['greeting' => $code($read())];
        $ehlo = $cmd('EHLO '.(parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'localhost'));
        $transcript['ehlo'] = $code($ehlo);
        if (! $implicit) {
            $transcript['starttls'] = $code($cmd('STARTTLS'));
            stream_socket_enable_crypto($s, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT);
            $cmd('EHLO '.(parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'localhost'));
        }
        $auth = $cmd('AUTH PLAIN '.base64_encode("\0".$user."\0".$pass));
        $transcript['auth'] = $code($auth);
        unset($pass);
        $senders = [];
        if ($code($auth) === 235) {
            foreach (['no-reply@provatferi.org', 'support@provatferi.org', 'security@provatferi.org', 'info@provatferi.org', 'admin@provatferi.org'] as $address) {
                $reply = $cmd("MAIL FROM:<{$address}>");
                $senders[$address] = ['code' => $code($reply), 'reply' => substr(preg_replace('/\s+/', ' ', $reply), 0, 160)];
                $cmd('RSET');
            }
        }
        $cmd('QUIT');
        fclose($s);
        $out(['mode' => 'smtp-identities', 'ok' => $code($auth) === 235, 'host' => $host, 'port' => $port, 'auth_mailbox' => $user, 'transcript_codes' => $transcript, 'mail_from_acceptance' => $senders,
            'note' => 'MAIL FROM acceptance only — a server may still refuse a header From at DATA time; the delivery tests decide.']);
        break;

    default:
        fwrite(STDERR, "modes: config | smtp-identities | wordpress <dir>\n");
        exit(2);
}
