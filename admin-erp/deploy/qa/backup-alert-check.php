<?php
/**
 * Proves what a backup-failure e-mail would say, and who it would appear to come from, WITHOUT sending one and
 * WITHOUT breaking a backup to trigger it. (docs/BACKUP_AND_DISASTER_RECOVERY.md, "Failure handling".)
 * CLI only: 404 otherwise.
 *
 * The alternative ways to check this are both bad: corrupting a real backup to force a failure, or sending mail to a
 * real mailbox from production. So the notification is simply BUILT here, on the live application with production's
 * own configuration, and its envelope is read off the message. That is the thing under test — which identity the
 * deployed code resolves — and it needs no delivery to answer.
 *
 * It also checks the body for the one property that matters more than the headers: that a failure notice carries
 * nothing from the backup itself.
 *
 *   php backup-alert-check.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
ini_set('display_errors', 'stderr');

const LIVE_APP = '/home/u951246149/domains/provatferi.org/laravel-admin';

define('LARAVEL_START', microtime(true));
define('LARAVEL_PUBLIC_PATH_OVERRIDE', LIVE_APP.'/public');
require LIVE_APP.'/vendor/autoload.php';
$laravel = require LIVE_APP.'/bootstrap/app.php';
$laravel->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Notifications\BackupFailedNotification;

// A plausible failure, invented here. Nothing on disk is touched and no run is altered.
$notification = new BackupFailedNotification(
    'a deliberately invented reason, used only to build this message',
    gmdate('c', time() - 7200),
    2
);

$mail = $notification->toMail(new class
{
    // Stands in for the staff mailbox. Notifiable only needs to exist for toMail(); nothing is routed anywhere.
    public string $email = 'nobody@invalid.test';
});

$from = $mail->from ?? [];
$replyTo = $mail->replyTo ?? [];
$body = implode("\n", array_map(static fn ($line): string => is_scalar($line) ? (string) $line : '', array_merge(
    (array) ($mail->introLines ?? []),
    (array) ($mail->outroLines ?? [])
)));

// The body must not carry anything from the backup. These are the shapes that would mean it did.
$leaks = [];
foreach ([
    'a path inside the backup root' => '/backups/',
    'an upload path' => 'storage/app/private',
    'a dump file name' => '.sql.gz',
    'an archive file name' => '.tar.gz',
    'the vault' => 'config.vault',
    'an environment key name' => 'DB_PASSWORD',
    'the encryption key name' => 'BACKUP_ENCRYPTION_KEY',
] as $what => $needle) {
    if (stripos($body, $needle) !== false) {
        $leaks[] = $what;
    }
}

$expectedFrom = (string) config('institution.email.noreply');
$expectedReplyTo = (string) config('institution.email.security');
$actualFrom = (string) ($from[0] ?? '');
$actualReplyTo = (string) ($replyTo[0][0] ?? ($replyTo[0] ?? ''));

$ok = $actualFrom === $expectedFrom && $actualFrom === 'no-reply@provatferi.org'
    && $actualReplyTo === $expectedReplyTo && $actualReplyTo === 'security@provatferi.org'
    && $leaks === [];

echo json_encode([
    'ok' => $ok,
    'checked_at_utc' => gmdate('c'),
    'sent' => false,
    'subject' => $mail->subject,
    'from' => $actualFrom,
    'from_matches_the_noreply_role' => $actualFrom === $expectedFrom,
    'reply_to' => $actualReplyTo,
    'reply_to_matches_the_security_role' => $actualReplyTo === $expectedReplyTo,
    'body_lines' => count((array) ($mail->introLines ?? [])) + count((array) ($mail->outroLines ?? [])),
    'backup_content_found_in_body' => $leaks,
    'carries_no_backup_content' => $leaks === [],
], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), "\n";

exit($ok ? 0 : 1);
