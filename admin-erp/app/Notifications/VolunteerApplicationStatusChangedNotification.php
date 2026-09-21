<?php

namespace App\Notifications;

use App\Models\JobApplication;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells a volunteer applicant the outcome of their application. Routed via
 * Notification::route('mail', …) — an applicant has no User/Member row to
 * hang this off (same as the receipt in VolunteerApplicationReceivedNotification).
 *
 * Only ever sent for accepted / not_selected: the intermediate review states
 * (under_review, contacted, shortlisted) are the team's working vocabulary,
 * and an applicant hearing about each internal step would be noise.
 *
 * Deliberately has NO note parameter. The admin screen's internal_note is
 * labelled "never published", and the Membership notification this mirrors
 * does pass its note through to the applicant — here the value cannot reach
 * this class at all, so it cannot leak by a future edit to the message text.
 */
class VolunteerApplicationStatusChangedNotification extends Notification
{
    use Queueable;

    /** @var array<int, string> */
    public const NOTIFIABLE_STATUSES = ['accepted', 'not_selected'];

    public function __construct(
        private readonly string $applicationNo,
        private readonly string $status,
        private readonly string $applicantName,
        private readonly ?string $postingTitle = null,
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
            ->subject("আপনার স্বেচ্ছাসেবী আবেদন ({$this->applicationNo}) সম্পর্কে হালনাগাদ")
            ->greeting("প্রিয় {$this->applicantName},");

        $supportAddress = config('mail.reply_to.support');
        if (is_string($supportAddress) && $supportAddress !== '') {
            $message->replyTo($supportAddress, 'Provatferi Support');
        }

        $subject = $this->postingTitle !== null && $this->postingTitle !== ''
            ? "“{$this->postingTitle}” — এ আপনার আবেদনের"
            : 'আপনার আবেদনের';

        $message->line("{$subject} অবস্থা হালনাগাদ হয়েছে।")
            ->line("আবেদন নম্বর: {$this->applicationNo}");

        match ($this->status) {
            'accepted' => $message->line('অভিনন্দন! আপনার আবেদনটি গৃহীত হয়েছে। আমাদের টিম শীঘ্রই পরবর্তী ধাপ নিয়ে আপনার সঙ্গে যোগাযোগ করবে।'),
            'not_selected' => $message->line('এবার আপনাকে অন্তর্ভুক্ত করা সম্ভব হয়নি। প্রভাতফেরীর প্রতি আপনার আগ্রহের জন্য ধন্যবাদ — ভবিষ্যতে নতুন সুযোগ এলে আপনি আবার আবেদন করতে পারেন।'),
            default => $message->line('বর্তমান অবস্থা: '.(JobApplication::STATUSES[$this->status] ?? $this->status)),
        };

        return $message->line('ধন্যবাদ।');
    }
}
