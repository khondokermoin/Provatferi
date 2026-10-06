<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * §37/§41: notifies the applicant of a decision on their membership application. Only ever sent for
 * approved/rejected/need_information — an applicant already knows about their own under_review/cancelled
 * transitions, so those don't warrant a message. Reply-To is support@, MAIL_ROLES.md's explicit "membership
 * application status" class.
 *
 * $message is text written FOR the applicant (a rejection reason, or what information is needed). An admin's internal
 * note is never passed here (Membership Registry task 2 fixed a leak: the internal note used to be sent as the
 * "details" of a request for information, and in place of the rejection reason when both were given).
 *
 * On approval: $memberCode is the new member number; $portal says what happens with the member portal — 'invited' (a
 * separate e-mail carries the password-setup link) or 'existing' (they already have an account and sign in with it).
 */
class MembershipApplicationStatusChangedNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly string $applicationNo,
        private readonly string $status,
        private readonly ?string $message = null,
        private readonly ?string $memberCode = null,
        private readonly ?string $portal = null,
    ) {
    }

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)->subject("আপনার সদস্যপদ আবেদন ({$this->applicationNo}) সম্পর্কে হালনাগাদ");

        $supportAddress = config('mail.reply_to.support');
        if (is_string($supportAddress) && $supportAddress !== '') {
            $mail->replyTo($supportAddress, 'Provatferi Support');
        }

        $mail->greeting('প্রিয়,')->line("আপনার সদস্যপদ আবেদন ({$this->applicationNo}) সম্পর্কে একটি হালনাগাদ রয়েছে।");

        match ($this->status) {
            'approved' => $this->approved($mail),
            'rejected' => $mail->line('দুঃখিত, আপনার আবেদনটি এই মুহূর্তে গ্রহণ করা যায়নি।'.($this->message ? " কারণ: {$this->message}" : '')),
            'need_information' => $mail->line('আপনার আবেদনটি পর্যালোচনার জন্য আরও কিছু তথ্য প্রয়োজন।')
                ->line($this->message ? "যা প্রয়োজন: {$this->message}" : 'অনুগ্রহ করে আমাদের সাথে যোগাযোগ করুন।')
                ->line('এই ই-মেইলের উত্তরে তথ্যগুলো পাঠাতে পারেন।'),
            default => $mail->line("বর্তমান অবস্থা: {$this->status}"),
        };

        return $mail;
    }

    private function approved(MailMessage $mail): void
    {
        $mail->line('অভিনন্দন! আপনার আবেদনটি অনুমোদিত হয়েছে এবং আপনি এখন প্রভাতফেরীর একজন সদস্য।');

        if ($this->memberCode !== null) {
            $mail->line("আপনার সদস্য নম্বর: {$this->memberCode}");
        }

        if ($this->portal === 'invited') {
            $mail->line('সদস্য পোর্টালে প্রবেশের জন্য আলাদা একটি ই-মেইলে পাসওয়ার্ড সেট করার লিংক পাঠানো হয়েছে।');
        } elseif ($this->portal === 'existing') {
            $mail->line('আপনার আগের সদস্য পোর্টাল অ্যাকাউন্টের সঙ্গে এই সদস্যপদ যুক্ত করা হয়েছে — সেই অ্যাকাউন্ট দিয়েই লগইন করুন।');
        }
    }
}
