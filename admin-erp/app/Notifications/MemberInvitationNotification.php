<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent once, when an approval creates a new member portal account (App\Jobs\SendMembershipDecisionNotifications). The
 * link is an ordinary token of the 'members' password broker — the same reset page and the same expiry as "forgot
 * password" — so it says plainly what to do if it has expired: ask for a new link from the login page. Its own class
 * (not MemberSetPasswordNotification) only because the words differ: this member did not ask for anything; their
 * membership was approved. Bangla, like every member-facing e-mail.
 */
class MemberInvitationNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly string $url,
        private readonly int $expiryMinutes,
        private readonly ?string $memberCode = null,
    ) {
    }

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)->subject('প্রভাতফেরী সদস্য পোর্টাল — আপনার পাসওয়ার্ড সেট করুন');

        $supportAddress = config('mail.reply_to.support');
        if (is_string($supportAddress) && $supportAddress !== '') {
            $message->replyTo($supportAddress, 'Provatferi Support');
        }

        $message->greeting('স্বাগতম!')
            ->line('আপনার সদস্যপদ আবেদন অনুমোদিত হয়েছে এবং আপনার জন্য প্রভাতফেরী সদস্য পোর্টালের একটি অ্যাকাউন্ট তৈরি করা হয়েছে।');

        if ($this->memberCode !== null) {
            $message->line("আপনার সদস্য নম্বর: {$this->memberCode}");
        }

        return $message
            ->line('পোর্টালে প্রবেশের জন্য নিচের বাটনে ক্লিক করে নিজের পাসওয়ার্ড সেট করুন।')
            ->action('পাসওয়ার্ড সেট করুন', $this->url)
            ->line("এই লিংকটি {$this->expiryMinutes} মিনিট পর মেয়াদোত্তীর্ণ হবে। মেয়াদ পেরিয়ে গেলে সদস্য লগইন পাতার \"পাসওয়ার্ড ভুলে গেছেন?\" অংশ থেকে একই ই-মেইলে নতুন লিংক নিন।")
            ->line('আমরা কখনো ই-মেইলে পাসওয়ার্ড পাঠাই না।');
    }
}
