<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * §14/§37: tells a member their public-profile edit was reviewed. Sent via
 * $member->notify() directly (unlike the membership-application/committee-
 * submission notifications) since Member is a real Notifiable model here,
 * not an external applicant with no account to route by.
 */
class PublicProfileReviewedNotification extends Notification
{
    use Queueable;

    public function __construct(private readonly string $status, private readonly ?string $note = null)
    {
    }

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $isApproved = $this->status === 'approved';
        $message = (new MailMessage)->subject($isApproved ? 'আপনার পাবলিক প্রোফাইল প্রকাশিত হয়েছে' : 'আপনার পাবলিক প্রোফাইল সম্পর্কে');

        $supportAddress = config('mail.reply_to.support');
        if (is_string($supportAddress) && $supportAddress !== '') {
            $message->replyTo($supportAddress, 'Provatferi Support');
        }

        $message->greeting('প্রিয়,');

        if ($isApproved) {
            $message->line('আপনার সাম্প্রতিক প্রোফাইল সম্পাদনা অনুমোদিত হয়েছে এবং এখন সদস্য পরিচিতি পাতায় প্রকাশিত।');
        } else {
            $message->line('আপনার সাম্প্রতিক প্রোফাইল সম্পাদনাটি এই মুহূর্তে প্রকাশ করা যায়নি।');
            if ($this->note) {
                $message->line("কারণ: {$this->note}");
            }
            $message->line('সদস্য পোর্টালে গিয়ে আবার সম্পাদনা করে জমা দিতে পারেন।');
        }

        return $message;
    }
}
