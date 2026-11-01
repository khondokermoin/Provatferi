<?php

namespace App\Notifications;

use App\Support\InstitutionMail;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells the staff mailbox that a backup run failed (docs/BACKUP_AND_DISASTER_RECOVERY.md, "Failure handling").
 *
 * A security-class message (docs/EMAIL_ARCHITECTURE.md): From no-reply@, Reply-To security@. The one thing it must
 * never do is carry anything FROM the backup — no filenames, no dump, no configuration, no credential. It says what
 * failed, when, and how old the last good backup now is, and nothing else. `defer()`-style sending is unnecessary
 * here: the run is a cron, not a request anyone is waiting on.
 */
class BackupFailedNotification extends Notification
{
    /**
     * @param  string  $reason  already scrubbed by the backup services
     */
    public function __construct(
        private readonly string $reason,
        private readonly ?string $lastSuccessUtc,
        private readonly ?int $hoursSinceLastSuccess,
    ) {
    }

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $age = $this->hoursSinceLastSuccess === null
            ? 'There is no record of a successful backup at all.'
            : "The last successful backup finished {$this->hoursSinceLastSuccess} hour(s) ago ({$this->lastSuccessUtc} UTC).";

        $message = (new MailMessage)
            ->subject('Provatferi backup FAILED')
            ->greeting('Backup failure')
            ->line('The nightly Provatferi backup did not complete.')
            ->line('Reason: '.$this->reason)
            ->line($age)
            ->line('Run `php artisan backup:status` on the server for the full picture, and `php artisan backup:run` to retry.')
            ->line('This message carries no backup content and no credential.');

        return InstitutionMail::security($message);
    }
}
