<?php

namespace App\Notifications;

use App\Support\InstitutionMail;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * §22: tells the internal mailbox a new application is waiting. Carries
 * the application number, the posting and the applicant's NAME only — no
 * phone, e-mail, address, CV or free-text answers. Whoever reviews it opens
 * the ERP, where access is already gated by recruitment.view; a mailbox is
 * not the place to copy an applicant's personal details to.
 *
 * An internal-class message (docs/EMAIL_ARCHITECTURE.md): addressed to the
 * internal mailbox, From the automated sender, and with no Reply-To. Until
 * 2026-10-14 it named the internal mailbox as its own Reply-To, which made a
 * private mailbox an address to write to.
 */
class VolunteerApplicationSubmittedNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly string $applicationNo,
        private readonly string $postingTitle,
        private readonly string $applicantName,
        private readonly string $reviewUrl,
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
            ->subject("নতুন স্বেচ্ছাসেবী আবেদন — {$this->applicationNo}")
            ->greeting('নতুন আবেদন')
            ->line("বিজ্ঞপ্তি: {$this->postingTitle}")
            ->line("আবেদনকারী: {$this->applicantName}")
            ->line("আবেদন নম্বর: {$this->applicationNo}")
            ->action('ERP-তে পর্যালোচনা করুন', $this->reviewUrl)
            ->line('আবেদনকারীর যোগাযোগের তথ্য ও সংযুক্তি শুধু ERP-তে দেখা যাবে।');

        return InstitutionMail::internal($message);
    }
}
