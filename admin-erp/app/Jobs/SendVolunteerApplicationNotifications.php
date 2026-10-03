<?php

namespace App\Jobs;

use App\Models\JobApplication;
use App\Notifications\VolunteerApplicationReceivedNotification;
use App\Notifications\VolunteerApplicationSubmittedNotification;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * The applicant's receipt and the operations mailbox's heads-up, sent AFTER the
 * response has gone back to the applicant.
 *
 * They used to be sent inside the request — two SMTP sessions, one after the
 * other — so every applicant waited on the mail server for something they do
 * not need in order to know their application was accepted. Dispatched with
 * ->afterResponse() (see VolunteerApplicationController), this runs in the same
 * PHP process once the response is flushed: no queue worker, which this shared
 * host cannot be relied on to keep alive.
 *
 * It is not a ShouldQueue job on purpose. A mail failure is logged — at error
 * level, since the production log level would swallow a warning, which is how
 * these failures used to disappear — and never raised: the application is
 * already committed and must stay accepted.
 */
class SendVolunteerApplicationNotifications
{
    use Dispatchable;

    public function __construct(public readonly int $applicationId)
    {
    }

    public function handle(): void
    {
        $application = JobApplication::query()->with('jobPosting.notice')->find($this->applicationId);
        $posting = $application?->jobPosting;
        if ($application === null || $posting === null) {
            return;
        }

        $notice = $posting->notice;
        $communityUrl = $notice !== null && $notice->isPubliclyVisible() ? $notice->action_url : null;

        try {
            Notification::route('mail', $application->applicant_email)->notify(
                new VolunteerApplicationReceivedNotification($application->application_no, $posting->title, $communityUrl)
            );
            // The only record, short of the mailbox itself, that the receipt really went out.
            $application->forceFill(['receipt_sent_at' => now()])->saveQuietly();
        } catch (Throwable $e) {
            Log::error('Volunteer application receipt failed to send.', [
                'application_no' => $application->application_no,
                'exception' => $e::class,
                'error' => $e->getMessage(),
            ]);
        }

        $operations = config('mail.reply_to.operations');
        if (! is_string($operations) || $operations === '') {
            return;
        }

        try {
            Notification::route('mail', $operations)->notify(
                new VolunteerApplicationSubmittedNotification(
                    $application->application_no,
                    $posting->title,
                    $application->applicant_name,
                    route('admin.recruitment.applications.show', $application),
                )
            );
        } catch (Throwable $e) {
            Log::error('Volunteer application admin notification failed to send.', [
                'application_no' => $application->application_no,
                'exception' => $e::class,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
