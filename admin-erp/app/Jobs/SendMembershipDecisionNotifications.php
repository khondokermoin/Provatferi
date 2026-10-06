<?php

namespace App\Jobs;

use App\Models\ApprovalHistory;
use App\Models\Member;
use App\Models\MembershipApplication;
use App\Models\User;
use App\Notifications\MemberInvitationNotification;
use App\Notifications\MembershipApplicationStatusChangedNotification;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Throwable;

/**
 * The applicant's e-mails about a decision on their membership application, sent AFTER the admin's response has gone
 * out (dispatched with ->afterResponse(), the same pattern as SendVolunteerApplicationNotifications: no queue worker,
 * which this shared host cannot keep alive, and no admin waiting on an SMTP session).
 *
 *  - approved / rejected / need_information: the status e-mail. A rejection carries the reason, a request for
 *    information carries what is needed — both written FOR the applicant. An admin's internal note is never sent.
 *  - $inviteMemberId (approval only): a password-setup link for the member portal, built by the existing 'members'
 *    password broker — the same token table, expiry and reset page as "forgot password", so there is still exactly one
 *    way a member ever gets a password, and no password is ever sent or stored in plain text. Recorded in the account's
 *    history as "invitation_sent" once it has really gone out.
 *
 * A mail failure is logged at error level and never raised: the decision is already committed and stays.
 */
class SendMembershipDecisionNotifications
{
    use Dispatchable;

    public function __construct(
        public readonly int $applicationId,
        public readonly string $status,
        public readonly ?string $applicantMessage = null,
        public readonly ?int $inviteMemberId = null,
        public readonly ?int $adminId = null,
    ) {
    }

    public function handle(): void
    {
        $application = MembershipApplication::query()->with('membership')->find($this->applicationId);
        if ($application === null) {
            return;
        }

        $member = $this->inviteMemberId !== null ? Member::query()->find($this->inviteMemberId) : null;
        $email = $application->applicantDisplayEmail();

        if ($email !== '') {
            try {
                Notification::route('mail', $email)->notify(new MembershipApplicationStatusChangedNotification(
                    $application->application_no,
                    $this->status,
                    $this->applicantMessage,
                    $application->membership?->member_code,
                    $this->status === 'approved' ? ($member !== null ? 'invited' : 'existing') : null,
                ));
            } catch (Throwable $e) {
                Log::error('Membership application decision e-mail failed to send.', [
                    'application_no' => $application->application_no, 'status' => $this->status, 'exception' => $e::class, 'error' => $e->getMessage(),
                ]);
            }
        }

        if ($member === null) {
            return;
        }

        // Once per membership: a second run (a retried job) must not create a new token — that would silently invalidate
        // the link already e-mailed. The membership number is what the history entry records.
        $code = $application->membership?->member_code;
        if ($code !== null && $member->history()->where('action', 'invitation_sent')->where('note', $code)->exists()) {
            return;
        }

        try {
            $token = Password::broker('members')->createToken($member);
            $url = rtrim((string) config('services.public_site.url'), '/').'/member/reset-password?token='.$token.'&email='.urlencode($member->email);
            $member->notify(new MemberInvitationNotification($url, (int) config('auth.passwords.members.expire', 60), $code));
            ApprovalHistory::record($member, 'invitation_sent', $this->adminId !== null ? User::query()->find($this->adminId) : null, $code);
        } catch (Throwable $e) {
            Log::error('Member portal invitation failed to send.', [
                'member_id' => $member->id, 'application_no' => $application->application_no, 'exception' => $e::class, 'error' => $e->getMessage(),
            ]);
        }
    }
}
