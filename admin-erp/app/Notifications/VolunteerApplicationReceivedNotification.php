<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * §22: the applicant's own receipt. Routed via Notification::route('mail', …)
 * — a volunteer applicant has no User/Member row to hang this off. Carries
 * the application number and nothing else the applicant just typed; the
 * WhatsApp community link is offered as optional, never as the place to
 * send personal details.
 */
class VolunteerApplicationReceivedNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly string $applicationNo,
        private readonly string $postingTitle,
        private readonly ?string $communityUrl = null,
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

        $supportAddress = config('mail.reply_to.support');
        if (is_string($supportAddress) && $supportAddress !== '') {
            $message->replyTo($supportAddress, 'Provatferi Support');
        }

        if ($this->communityUrl !== null && $this->communityUrl !== '') {
            $message->line('যোগাযোগ ও পরবর্তী আপডেটের জন্য চাইলে আমাদের কমিউনিটি গ্রুপে যুক্ত হতে পারেন — এটি ঐচ্ছিক।')
                ->action('WhatsApp Group-এ যুক্ত হোন', $this->communityUrl);
        }

        return $message->line('ধন্যবাদ।');
    }
}
