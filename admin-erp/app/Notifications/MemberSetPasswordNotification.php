<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Deliberately its own class rather than reusing the framework's
 * Illuminate\Auth\Notifications\ResetPassword — AppServiceProvider already
 * overrides that class's toMailUsing() globally for ERP staff (App\Models\
 * User), building a URL via the web route('password.reset', ...). Since
 * that override is static/class-level, a Member's password-reset would
 * inherit the SAME callback and get a URL pointing at admin.provatferi.org's
 * own staff reset page — wrong domain, wrong flow, and looked up against
 * the wrong password-reset-tokens table. This class points at the Next.js
 * member portal instead and never touches ResetPassword at all.
 */
class MemberSetPasswordNotification extends Notification
{
    use Queueable;

    public function __construct(private readonly string $url, private readonly int $expiryMinutes)
    {
    }

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)->subject('আপনার সদস্য অ্যাকাউন্টের পাসওয়ার্ড সেট করুন — প্রভাতফেরী');

        $supportAddress = config('mail.reply_to.support');
        if (is_string($supportAddress) && $supportAddress !== '') {
            $message->replyTo($supportAddress, 'Provatferi Support');
        }

        return $message
            ->greeting('স্বাগতম!')
            ->line('আপনার প্রভাতফেরী সদস্য পোর্টাল অ্যাকাউন্টের জন্য একটি পাসওয়ার্ড সেট করতে নিচের বাটনে ক্লিক করুন।')
            ->action('পাসওয়ার্ড সেট করুন', $this->url)
            ->line("এই লিংকটি {$this->expiryMinutes} মিনিট পর মেয়াদোত্তীর্ণ হবে।")
            ->line('আপনি যদি এই অনুরোধ না করে থাকেন, তাহলে এই ই-মেইলটি উপেক্ষা করুন।');
    }
}
