<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * §27/§37/§40: without this, CommitteeSubmissionController::requestCorrection()
 * only ever flashes the correction URL into the admin's own session for a
 * one-time display — the entire correction workflow depends on the admin
 * remembering to copy and manually relay it. Routed via
 * Notification::route('mail', ...) since a committee nominee is not
 * necessarily a Member/User with a Notifiable trait to hang this off of.
 * Reply-To is support@, matching MAIL_ROLES.md's "membership application
 * status" class — a correction request is exactly that class of message
 * for the committee-submission workflow.
 */
class CommitteeCorrectionRequestedNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly string $committeeName,
        private readonly string $reason,
        private readonly string $correctionUrl,
        private readonly int $expiryDays,
    ) {
    }

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)->subject("আপনার তথ্য সংশোধন প্রয়োজন — {$this->committeeName}");

        $supportAddress = config('mail.reply_to.support');
        if (is_string($supportAddress) && $supportAddress !== '') {
            $message->replyTo($supportAddress, 'Provatferi Support');
        }

        return $message
            ->greeting('প্রিয়,')
            ->line("{$this->committeeName}-এর জন্য আপনার জমাকৃত তথ্যে একটি সংশোধন প্রয়োজন।")
            ->line("কারণ: {$this->reason}")
            ->action('তথ্য সংশোধন করুন', $this->correctionUrl)
            ->line("এই লিংকটি {$this->expiryDays} দিন পর মেয়াদোত্তীর্ণ হবে এবং শুধুমাত্র একবার ব্যবহারযোগ্য।");
    }
}
