<?php

namespace Tests\Feature\Admin;

use App\Models\JobApplication;
use App\Models\JobPosting;
use App\Notifications\VolunteerApplicationStatusChangedNotification;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * The applicant is emailed the outcome (accepted / not_selected) of a
 * volunteer application. Mirrors MembershipApplicationStatusChangedNotification's
 * architecture, with three deliberate differences pinned here: no internal
 * note can reach the message, a re-save of an unchanged status does not email
 * again, and a mail failure never costs the admin their status change.
 */
class VolunteerApplicationStatusEmailTest extends AdminTestCase
{
    private function posting(string $title = 'প্রভাতফেরীর স্বেচ্ছাসেবী টিম'): JobPosting
    {
        return JobPosting::query()->create([
            'title' => $title,
            'slug' => 'volunteer-'.uniqid(),
            'description' => 'বিবরণ',
            'employment_type' => 'volunteer',
            'application_mode' => 'rolling',
            'accepts_applications' => true,
            'status' => 'open',
        ]);
    }

    private function application(JobPosting $posting, array $overrides = []): JobApplication
    {
        return JobApplication::query()->create(array_merge([
            'application_no' => JobApplication::generateApplicationNo(),
            'job_posting_id' => $posting->id,
            'applicant_name' => 'নাদিয়া ইসলাম',
            'applicant_email' => 'nadia@example.test',
            'applicant_phone' => '+8801711223344',
            'district' => 'কুমিল্লা',
            'current_location' => 'চান্দিনা',
            'profession' => 'শিক্ষার্থী',
            'experience' => 'পাঠাগারে স্বেচ্ছাসেবী কাজ।',
            'skills' => ['fundraising'],
            'contribution' => 'তহবিল সংগ্রহে সময় দিতে চাই।',
            'accuracy_declaration' => true,
            'privacy_consent' => true,
            'contact_consent' => true,
            'status' => 'submitted',
            'submitted_at' => now(),
        ], $overrides));
    }

    private function setStatus(JobApplication $application, string $status, array $extra = [])
    {
        return $this->actingAs($this->superAdmin())
            ->patch(route('admin.recruitment.applications.status', $application), array_merge(['status' => $status], $extra));
    }

    private function sentTo(string $email): callable
    {
        return fn ($notification, $channels, $notifiable) => ($notifiable->routes['mail'] ?? null) === $email;
    }

    public function test_accepting_an_application_emails_the_applicant(): void
    {
        Notification::fake();
        $application = $this->application($this->posting());

        $this->setStatus($application, 'accepted')->assertRedirect();

        Notification::assertSentOnDemand(VolunteerApplicationStatusChangedNotification::class, $this->sentTo('nadia@example.test'));
        $this->assertSame('accepted', $application->fresh()->status);
    }

    public function test_marking_not_selected_emails_the_applicant(): void
    {
        Notification::fake();
        $application = $this->application($this->posting());

        $this->setStatus($application, 'not_selected')->assertRedirect();

        Notification::assertSentOnDemand(VolunteerApplicationStatusChangedNotification::class, $this->sentTo('nadia@example.test'));
        $this->assertSame('not_selected', $application->fresh()->status);
    }

    public function test_intermediate_review_statuses_send_no_email(): void
    {
        Notification::fake();
        $application = $this->application($this->posting());

        foreach (['under_review', 'contacted', 'shortlisted', 'withdrawn', 'archived'] as $status) {
            $this->setStatus($application, $status)->assertRedirect();
        }

        Notification::assertNothingSent();
    }

    public function test_exactly_one_notification_is_dispatched_per_transition(): void
    {
        Notification::fake();
        $application = $this->application($this->posting());

        $this->setStatus($application, 'accepted');

        Notification::assertSentOnDemandTimes(VolunteerApplicationStatusChangedNotification::class, 1);
    }

    public function test_re_saving_an_unchanged_status_does_not_email_again(): void
    {
        Notification::fake();
        $application = $this->application($this->posting());

        $this->setStatus($application, 'accepted');
        // Same status again — e.g. an admin returning to edit the internal note.
        $this->setStatus($application, 'accepted', ['internal_note' => 'পরে ফোন করতে হবে।']);

        Notification::assertSentOnDemandTimes(VolunteerApplicationStatusChangedNotification::class, 1);
        $this->assertSame('পরে ফোন করতে হবে।', $application->fresh()->internal_note);
    }

    public function test_a_genuine_second_transition_does_email_again(): void
    {
        Notification::fake();
        $application = $this->application($this->posting());

        $this->setStatus($application, 'accepted');
        $this->setStatus($application, 'not_selected');

        Notification::assertSentOnDemandTimes(VolunteerApplicationStatusChangedNotification::class, 2);
    }

    public function test_a_mail_failure_does_not_block_or_undo_the_status_update(): void
    {
        Log::spy();
        // Notification::route() is a static helper on the facade, so it cannot be
        // intercepted; the failure has to come from the dispatcher it hands off to.
        Notification::shouldReceive('send')->andThrow(new \RuntimeException('SMTP connection refused'));
        $application = $this->application($this->posting());

        $this->setStatus($application, 'accepted')
            ->assertRedirect(route('admin.recruitment.applications.show', $application))
            ->assertSessionHas('success');

        $fresh = $application->fresh();
        $this->assertSame('accepted', $fresh->status);
        $this->assertNotNull($fresh->reviewed_by);

        Log::shouldHaveReceived('warning')->once()->withArgs(
            fn (string $message, array $context) => $message === 'Volunteer application status notification failed to send.'
                && $context['application_no'] === $application->application_no
                && $context['status'] === 'accepted'
                && str_contains($context['error'], 'SMTP connection refused'),
        );
    }

    public function test_the_internal_note_never_appears_in_the_email(): void
    {
        Notification::fake();
        $application = $this->application($this->posting());

        $this->setStatus($application, 'not_selected', ['internal_note' => 'গোপন: এই ব্যক্তির সঙ্গে আগে সমস্যা হয়েছিল।']);

        Notification::assertSentOnDemand(
            VolunteerApplicationStatusChangedNotification::class,
            function (VolunteerApplicationStatusChangedNotification $notification, $channels, AnonymousNotifiable $notifiable) {
                $body = $this->render($notification, $notifiable);

                return ! str_contains($body, 'গোপন') && ! str_contains($body, 'সমস্যা হয়েছিল');
            },
        );
        // …while it is still saved for the admins.
        $this->assertStringContainsString('গোপন', $application->fresh()->internal_note);
    }

    public function test_the_accepted_email_names_the_applicant_posting_number_and_outcome(): void
    {
        Notification::fake();
        $application = $this->application($this->posting('মিডিয়া টিমে স্বেচ্ছাসেবী'));

        $this->setStatus($application, 'accepted');

        Notification::assertSentOnDemand(
            VolunteerApplicationStatusChangedNotification::class,
            function (VolunteerApplicationStatusChangedNotification $notification, $channels, AnonymousNotifiable $notifiable) use ($application) {
                $mail = $notification->toMail($notifiable);
                $body = $this->render($notification, $notifiable);

                return str_contains($mail->subject, $application->application_no)
                    && str_contains($body, 'নাদিয়া ইসলাম')
                    && str_contains($body, 'মিডিয়া টিমে স্বেচ্ছাসেবী')
                    && str_contains($body, $application->application_no)
                    && str_contains($body, 'গৃহীত হয়েছে');
            },
        );
    }

    public function test_the_not_selected_email_is_gentle_and_invites_a_future_application(): void
    {
        Notification::fake();
        $application = $this->application($this->posting());

        $this->setStatus($application, 'not_selected');

        Notification::assertSentOnDemand(
            VolunteerApplicationStatusChangedNotification::class,
            function (VolunteerApplicationStatusChangedNotification $notification, $channels, AnonymousNotifiable $notifiable) {
                $body = $this->render($notification, $notifiable);

                return str_contains($body, 'সম্ভব হয়নি')
                    && str_contains($body, 'আবার আবেদন করতে পারেন')
                    && ! str_contains($body, 'অভিনন্দন');
            },
        );
    }

    public function test_the_email_still_names_a_posting_that_was_removed_after_the_application(): void
    {
        Notification::fake();
        $posting = $this->posting('বন্ধ হয়ে যাওয়া সুযোগ');
        $application = $this->application($posting);
        $posting->delete(); // JobPosting soft-deletes

        $this->setStatus($application, 'accepted')->assertRedirect();

        Notification::assertSentOnDemand(
            VolunteerApplicationStatusChangedNotification::class,
            fn ($notification, $channels, $notifiable) => str_contains($this->render($notification, $notifiable), 'বন্ধ হয়ে যাওয়া সুযোগ'),
        );
    }

    public function test_the_reply_to_is_the_support_mailbox_when_configured(): void
    {
        config(['mail.reply_to.support' => 'support@provatferi.test']);
        $notification = new VolunteerApplicationStatusChangedNotification('VOL-2026-0001', 'accepted', 'নাদিয়া', 'পোস্ট');

        $mail = $notification->toMail(new AnonymousNotifiable);

        $this->assertSame([['support@provatferi.test', 'Provatferi Support']], $mail->replyTo);
    }

    public function test_the_notification_has_no_way_to_carry_an_internal_note(): void
    {
        $constructor = (new \ReflectionClass(VolunteerApplicationStatusChangedNotification::class))->getConstructor();
        $params = array_map(fn ($p) => $p->getName(), $constructor->getParameters());

        $this->assertSame(['applicationNo', 'status', 'applicantName', 'postingTitle'], $params);
    }

    public function test_a_viewer_without_approve_permission_cannot_trigger_an_email(): void
    {
        Notification::fake();
        $application = $this->application($this->posting());

        $this->actingAs($this->userWith(['recruitment.view']))
            ->patch(route('admin.recruitment.applications.status', $application), ['status' => 'accepted'])
            ->assertForbidden();

        Notification::assertNothingSent();
        $this->assertSame('submitted', $application->fresh()->status);
    }

    /** The flattened text a recipient would read: intro, body lines and outro. */
    private function render(VolunteerApplicationStatusChangedNotification $notification, AnonymousNotifiable $notifiable): string
    {
        $mail = $notification->toMail($notifiable);

        return implode("\n", array_merge([$mail->greeting], $mail->introLines, $mail->outroLines));
    }
}
