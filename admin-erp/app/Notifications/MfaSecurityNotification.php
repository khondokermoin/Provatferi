<?php

namespace App\Notifications;

use App\Support\InstitutionMail;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "Something changed in your account's two-step verification" (docs/MFA_SECURITY.md, "Security e-mails"). A SECURITY-class
 * message: From no-reply@, Reply-To security@ (InstitutionMail::security). In the person's admin language.
 *
 * It says what happened, when, and from which address and browser, and what to do if it was not them. It never contains a
 * secret, a QR code, a code, a recovery code, a password or a link that signs anyone in.
 *
 * Events: enabled · replaced (a new authenticator) · disabled · codes_regenerated · code_used · reset (by an administrator
 * on the server).
 */
class MfaSecurityNotification extends Notification
{
    use Queueable;

    public const EVENTS = ['enabled', 'replaced', 'disabled', 'codes_regenerated', 'code_used', 'reset'];

    /** @param array<string, scalar|null> $facts */
    public function __construct(public readonly string $event, private readonly array $facts = [])
    {
    }

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $event = in_array($this->event, self::EVENTS, true) ? $this->event : 'enabled';

        $message = (new MailMessage)
            ->subject(__('admin.mfa.mail.subject_'.$event))
            ->greeting(__('admin.mfa.mail.greeting'))
            ->line(__('admin.mfa.mail.body_'.$event, ['remaining' => bn_number((string) ($this->facts['remaining'] ?? ''))]));

        $when = $this->facts['at'] ?? null;
        if (is_string($when) && $when !== '') {
            $message->line(__('admin.mfa.mail.when', ['when' => $when]));
        }
        $ip = $this->facts['ip'] ?? null;
        $device = $this->facts['device'] ?? null;
        if (is_string($ip) && $ip !== '') {
            $message->line(__('admin.mfa.mail.where', ['ip' => $ip, 'device' => is_string($device) && $device !== '' ? $device : __('admin.mfa.mail.unknown_device')]));
        }

        $message->line(__('admin.mfa.mail.not_you'))
            ->line(__('admin.mfa.mail.never_ask'));

        return InstitutionMail::security($message);
    }
}
