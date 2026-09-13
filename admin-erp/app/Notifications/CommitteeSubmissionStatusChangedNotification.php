<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * §26-28/§37: tells a committee nominee the outcome of their submission —
 * approved or rejected. correction_requested has its own dedicated
 * CommitteeCorrectionRequestedNotification instead, since that one carries
 * a secure action link rather than just a status update.
 */
class CommitteeSubmissionStatusChangedNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly string $committeeName,
        private readonly string $status,
        private readonly ?string $note = null,
    ) {
    }

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $isApproved = $this->status === 'approved';
        $message = (new MailMessage)->subject(
            $isApproved ? "আপনার আবেদন অনুমোদিত হয়েছে — {$this->committeeName}" : "আপনার আবেদন সম্পর্কে — {$this->committeeName}",
        );

        $supportAddress = config('mail.reply_to.support');
        if (is_string($supportAddress) && $supportAddress !== '') {
            $message->replyTo($supportAddress, 'Provatferi Support');
        }

        $message->greeting('প্রিয়,');

        if ($isApproved) {
            $message->line("{$this->committeeName}-এর জন্য আপনার আবেদনটি অনুমোদিত হয়েছে এবং আপনি এখন কমিটির সদস্য তালিকায় যুক্ত হয়েছেন।");
        } else {
            $message->line("দুঃখিত, {$this->committeeName}-এর জন্য আপনার আবেদনটি গ্রহণ করা যায়নি।");
            if ($this->note) {
                $message->line("কারণ: {$this->note}");
            }
        }

        return $message;
    }
}
