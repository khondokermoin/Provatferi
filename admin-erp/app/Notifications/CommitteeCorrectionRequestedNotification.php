<?php

namespace App\Notifications;

use App\Support\InstitutionMail;
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
 * A support-class message (docs/EMAIL_ARCHITECTURE.md): an applicant asked to
 * correct their details is exactly who may need to write back.
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
        $message = (new MailMessage)->subject("আপনার তথ্য সংশোধন প্রয়োজন — {$this->committeeName}")
            ->greeting('প্রিয়,')
            ->line("{$this->committeeName}-এর জন্য আপনার জমাকৃত তথ্যে একটি সংশোধন প্রয়োজন।")
            ->line("কারণ: {$this->reason}")
            ->action('তথ্য সংশোধন করুন', $this->correctionUrl)
            ->line("এই লিংকটি {$this->expiryDays} দিন পর মেয়াদোত্তীর্ণ হবে এবং শুধুমাত্র একবার ব্যবহারযোগ্য।");

        return InstitutionMail::support($message);
    }
}
