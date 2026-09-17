<?php

namespace Tests\Feature\Admin;

use App\Models\JobApplication;
use App\Models\JobPosting;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * §7: the admin side of the volunteer workflow — seeing an application in
 * full, moving it through the review statuses, and keeping the internal
 * note and the applicant's files where only a permitted admin can reach
 * them.
 */
class VolunteerApplicationReviewTest extends AdminTestCase
{
    private function posting(array $overrides = []): JobPosting
    {
        return JobPosting::query()->create(array_merge([
            'title' => 'স্বেচ্ছাসেবী আহ্বান',
            'slug' => 'volunteer-'.uniqid(),
            'description' => 'বিবরণ',
            'employment_type' => 'volunteer',
            'application_mode' => 'rolling',
            'accepts_applications' => true,
            'status' => 'open',
        ], $overrides));
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
            'skills' => ['fundraising', 'report_writing'],
            'contribution' => 'তহবিল সংগ্রহে সময় দিতে চাই।',
            'accuracy_declaration' => true,
            'privacy_consent' => true,
            'contact_consent' => true,
            'status' => 'submitted',
            'submitted_at' => now(),
        ], $overrides));
    }

    public function test_the_review_screen_shows_everything_the_applicant_submitted(): void
    {
        $application = $this->application($this->posting());

        $this->actingAs($this->superAdmin())->get(route('admin.recruitment.applications.show', $application))->assertOk()
            ->assertSee('নাদিয়া ইসলাম')
            ->assertSee('nadia@example.test')
            ->assertSee('+8801711223344')
            ->assertSee('কুমিল্লা')
            ->assertSee('শিক্ষার্থী')
            ->assertSee('Fundraising / Donation / Sponsorship')
            ->assertSee('তহবিল সংগ্রহে সময় দিতে চাই।')
            // Labels, never the stored keys.
            ->assertDontSee('report_writing');
    }

    public function test_applications_can_be_filtered_by_status_skill_and_district(): void
    {
        $posting = $this->posting();
        $this->application($posting, ['applicant_name' => 'ফারহান', 'applicant_email' => 'f@example.test', 'district' => 'ঢাকা', 'skills' => ['graphic_design']]);
        $this->application($posting, ['applicant_name' => 'নাদিয়া', 'applicant_email' => 'n@example.test', 'district' => 'কুমিল্লা', 'skills' => ['fundraising'], 'status' => 'shortlisted']);

        $admin = $this->superAdmin();

        $this->actingAs($admin)->get(route('admin.recruitment.applications.index', ['district' => 'ঢাকা']))
            ->assertOk()->assertSee('ফারহান')->assertDontSee('নাদিয়া');

        $this->actingAs($admin)->get(route('admin.recruitment.applications.index', ['skill' => 'fundraising']))
            ->assertOk()->assertSee('নাদিয়া')->assertDontSee('ফারহান');

        $this->actingAs($admin)->get(route('admin.recruitment.applications.index', ['status' => 'shortlisted']))
            ->assertOk()->assertSee('নাদিয়া')->assertDontSee('ফারহান');
    }

    public function test_an_application_moves_through_the_review_statuses(): void
    {
        $application = $this->application($this->posting());
        $admin = $this->superAdmin();

        foreach (['under_review', 'contacted', 'shortlisted', 'accepted'] as $status) {
            $this->actingAs($admin)->patch(route('admin.recruitment.applications.status', $application), ['status' => $status])
                ->assertRedirect()->assertSessionHasNoErrors();
            $this->assertSame($status, $application->fresh()->status);
        }

        $this->assertSame($admin->id, $application->fresh()->reviewed_by);

        $this->actingAs($admin)->patch(route('admin.recruitment.applications.status', $application), ['status' => 'hired'])
            ->assertSessionHasErrors('status');
    }

    public function test_an_internal_note_is_kept_private_and_is_not_wiped_by_a_later_status_change(): void
    {
        $application = $this->application($this->posting());
        $admin = $this->superAdmin();

        $this->actingAs($admin)->patch(route('admin.recruitment.applications.status', $application), [
            'status' => 'contacted',
            'internal_note' => 'ফোনে কথা হয়েছে — আগ্রহী।',
        ])->assertRedirect();

        $this->assertSame('ফোনে কথা হয়েছে — আগ্রহী।', $application->fresh()->internal_note);

        $this->actingAs($admin)->patch(route('admin.recruitment.applications.status', $application), ['status' => 'shortlisted'])
            ->assertRedirect();
        $this->assertSame('ফোনে কথা হয়েছে — আগ্রহী।', $application->fresh()->internal_note);

        // Nothing public ever carries it.
        $posting = $application->jobPosting;
        $this->getJson("/api/v1/job-postings/{$posting->slug}")->assertOk()
            ->assertDontSee('ফোনে কথা হয়েছে', false);
    }

    public function test_review_is_guarded_by_recruitment_permissions(): void
    {
        $application = $this->application($this->posting());

        $outsider = $this->userWith(['activities.view']);
        $this->actingAs($outsider)->get(route('admin.recruitment.applications.index'))->assertForbidden();
        $this->actingAs($outsider)->get(route('admin.recruitment.applications.show', $application))->assertForbidden();

        $viewer = $this->userWith(['recruitment.view']);
        $this->actingAs($viewer)->get(route('admin.recruitment.applications.index'))->assertOk();
        $this->actingAs($viewer)->patch(route('admin.recruitment.applications.status', $application), ['status' => 'contacted'])
            ->assertForbidden();
    }

    public function test_the_cv_streams_only_to_a_permitted_admin_and_never_publicly(): void
    {
        Storage::fake('uploads_private');
        $application = $this->application($this->posting());
        $application->update(['cv_path' => 'applications/cv/secret.pdf']);
        Storage::disk('uploads_private')->put('applications/cv/secret.pdf', '%PDF-1.4');

        $response = $this->actingAs($this->userWith(['recruitment.view']))
            ->get(route('admin.recruitment.applications.file', [$application, 'cv']))->assertOk();
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString('attachment', (string) $response->headers->get('Content-Disposition'));

        $this->actingAs($this->userWith(['activities.view']))
            ->get(route('admin.recruitment.applications.file', [$application, 'cv']))->assertForbidden();

        // Signed out entirely, it is a login redirect — never the file.
        $this->post(route('logout'));
        $this->get(route('admin.recruitment.applications.file', [$application, 'cv']))->assertRedirect();
    }

    public function test_a_missing_file_is_a_404_not_a_500(): void
    {
        $application = $this->application($this->posting());

        $this->actingAs($this->superAdmin())
            ->get(route('admin.recruitment.applications.file', [$application, 'photo']))->assertNotFound();
    }

    public function test_the_posting_form_carries_the_accepts_applications_switch(): void
    {
        $admin = $this->superAdmin();

        $this->actingAs($admin)->post(route('admin.recruitment.store'), [
            'title' => 'নতুন স্বেচ্ছাসেবী আহ্বান',
            'description' => 'বিবরণ',
            'employment_type' => 'volunteer',
            'application_mode' => 'rolling',
            'status' => 'open',
            'accepts_applications' => '1',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $posting = JobPosting::query()->firstOrFail();
        $this->assertTrue($posting->accepts_applications);
        $this->assertSame("/recruitment/{$posting->slug}/apply", $posting->applyPath());

        // Unticking it on a later edit actually turns it off.
        $this->actingAs($admin)->put(route('admin.recruitment.update', $posting), [
            'title' => $posting->title,
            'description' => 'বিবরণ',
            'employment_type' => 'volunteer',
            'application_mode' => 'rolling',
            'status' => 'open',
        ])->assertRedirect();

        $this->assertFalse($posting->fresh()->accepts_applications);
        $this->assertNull($posting->fresh()->applyPath());
    }
}
