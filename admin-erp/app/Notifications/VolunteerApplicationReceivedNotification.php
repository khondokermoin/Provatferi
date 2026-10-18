<?php

namespace App\Notifications;

use App\Support\InstitutionMail;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * §22: the applicant's own receipt. Routed via Notification::route('mail', …)
 * — a volunteer applicant has no User/Member row to hang this off. Carries
 * the application number and nothing else the applicant just typed.
 *
 * It carries no group link of any kind. The central Messenger group invite is shown only on the confirmation page
 * that follows a verified submission (docs/VOLUNTEER_MESSENGER_GROUP.md); this message goes to whatever address was
 * typed into the form, which is not proof of who reads it.
 */
class VolunteerApplicationReceivedNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly string $applicationNo,
        private readonly string $postingTitle,
    ) {
    }

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject('আপনার স্বেচ্ছাসেবী আবেদন গ্রহণ করা হয়েছে')
            ->greeting('প্রিয়,')
            ->line("“{$this->postingTitle}” — এ আপনার আবেদন সফলভাবে গ্রহণ করা হয়েছে।")
            ->line("আবেদন নম্বর: {$this->applicationNo}")
            ->line('প্রভাতফেরীর পক্ষ থেকে আবেদন যাচাই করে প্রয়োজন অনুযায়ী আপনার সঙ্গে যোগাযোগ করা হবে।');

        return InstitutionMail::support($message->line('ধন্যবাদ।'));
    }
}
