<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * §37/§41: notifies the applicant of a decision on their membership
 * application. Only ever sent for approved/rejected/need_information — an
 * applicant already knows about their own under_review/cancelled
 * transitions, so those don't warrant a message. Reply-To is support@,
 * MAIL_ROLES.md's explicit "membership application status" class.
 */
class MembershipApplicationStatusChangedNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly string $applicationNo,
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
        $message = (new MailMessage)->subject("আপনার সদস্যপদ আবেদন ({$this->applicationNo}) সম্পর্কে হালনাগাদ");

        $supportAddress = config('mail.reply_to.support');
        if (is_string($supportAddress) && $supportAddress !== '') {
            $message->replyTo($supportAddress, 'Provatferi Support');
        }

        $message->greeting('প্রিয়,')->line("আপনার সদস্যপদ আবেদন ({$this->applicationNo}) সম্পর্কে একটি হালনাগাদ রয়েছে।");

        match ($this->status) {
            'approved' => $message->line('অভিনন্দন! আপনার আবেদনটি অনুমোদিত হয়েছে এবং আপনি এখন প্রভাতফেরীর একজন সদস্য।'),
            'rejected' => $message->line('দুঃখিত, আপনার আবেদনটি এই মুহূর্তে গ্রহণ করা যায়নি।'.($this->note ? " কারণ: {$this->note}" : '')),
            'need_information' => $message->line('আপনার আবেদনটি পর্যালোচনাধীন — অনুগ্রহ করে অতিরিক্ত তথ্যের জন্য আমাদের সাথে যোগাযোগ করুন।'.($this->note ? " বিস্তারিত: {$this->note}" : '')),
            default => $message->line("বর্তমান অবস্থা: {$this->status}"),
        };

        return $message;
    }
}
