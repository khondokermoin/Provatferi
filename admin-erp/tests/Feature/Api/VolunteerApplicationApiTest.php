<?php

namespace Tests\Feature\Api;

use App\Models\JobApplication;
use App\Models\JobPosting;
use App\Notifications\VolunteerApplicationReceivedNotification;
use App\Notifications\VolunteerApplicationSubmittedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * §2/§21/§24: the website form is the system of record for volunteer
 * interest. These cover the public write endpoint only — what it accepts,
 * what it refuses, and what it never gives back.
 */
class VolunteerApplicationApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // The route is rate-limited per IP and every test here shares
        // 127.0.0.1; without this, one test's submissions would exhaust the
        // window for the next.
        Cache::flush();
        Notification::fake();
    }

    private function posting(array $overrides = []): JobPosting
    {
        return JobPosting::query()->create(array_merge([
            'title' => 'প্রভাতফেরীর স্বেচ্ছাসেবী টিমে যুক্ত হওয়ার আহ্বান',
            'slug' => 'volunteer-call-'.uniqid(),
            'description' => 'বিবরণ',
            'employment_type' => 'volunteer',
            'application_mode' => 'rolling',
            'accepts_applications' => true,
            'status' => 'open',
        ], $overrides));
    }

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'applicant_name' => 'নাদিয়া ইসলাম',
            'applicant_email' => 'nadia@example.test',
            'applicant_phone' => '+8801711223344',
            'district' => 'কুমিল্লা',
            'current_location' => 'চান্দিনা, কুমিল্লা',
            'profession' => 'শিক্ষার্থী — সমাজবিজ্ঞান',
            'experience' => 'দুই বছর ধরে স্থানীয় পাঠাগারে স্বেচ্ছাসেবী কাজ করছি।',
            'skills' => ['fundraising', 'report_writing'],
            'contribution' => 'তহবিল সংগ্রহ ও প্রতিবেদন তৈরিতে নিয়মিত সময় দিতে চাই।',
            'accuracy_declaration' => '1',
            'privacy_consent' => '1',
            'contact_consent' => '1',
        ], $overrides);
    }

    public function test_a_complete_bengali_application_is_stored_and_acknowledged(): void
    {
        $posting = $this->posting();

        $response = $this->postJson("/api/v1/public/recruitment/{$posting->slug}/applications", $this->payload([
            'other_skills' => 'ভিডিও সম্পাদনা',
            'availability' => 'সপ্তাহে ৮ ঘণ্টা',
            'preferred_contact' => 'whatsapp',
            'linkedin_url' => 'https://www.linkedin.com/in/nadia',
        ]))->assertCreated();

        $application = JobApplication::query()->firstOrFail();
        $this->assertSame($posting->id, $application->job_posting_id);
        $this->assertSame('submitted', $application->status);
        $this->assertSame(['fundraising', 'report_writing'], $application->skills);
        $this->assertSame('কুমিল্লা', $application->district);
        $this->assertTrue($application->privacy_consent);
        $this->assertNotNull($application->submitted_at);
        $this->assertTrue(mb_check_encoding($application->contribution, 'UTF-8'));

        // The receipt carries the application number and nothing else.
        $response->assertJsonPath('data.application_no', $application->application_no);
        $this->assertSame(['application_no'], array_keys($response->json('data')));
    }

    public function test_the_applicant_and_the_operations_mailbox_are_notified(): void
    {
        $posting = $this->posting();

        $this->postJson("/api/v1/public/recruitment/{$posting->slug}/applications", $this->payload())->assertCreated();

        Notification::assertSentOnDemand(
            VolunteerApplicationReceivedNotification::class,
            fn ($notification, $channels, $notifiable) => $notifiable->routes['mail'] === 'nadia@example.test',
        );
        Notification::assertSentOnDemand(VolunteerApplicationSubmittedNotification::class);
    }

    public function test_every_required_field_is_enforced(): void
    {
        $posting = $this->posting();

        $this->postJson("/api/v1/public/recruitment/{$posting->slug}/applications", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors([
                'applicant_name', 'applicant_email', 'applicant_phone', 'district', 'current_location',
                'profession', 'experience', 'skills', 'contribution',
                'accuracy_declaration', 'privacy_consent', 'contact_consent',
            ]);

        $this->assertDatabaseCount('job_applications', 0);
    }

    public function test_a_malformed_email_or_phone_is_refused(): void
    {
        $posting = $this->posting();

        $this->postJson("/api/v1/public/recruitment/{$posting->slug}/applications", $this->payload([
            'applicant_email' => 'not-an-email',
            'applicant_phone' => 'আমাকে ফোন করুন',
        ]))->assertStatus(422)->assertJsonValidationErrors(['applicant_email', 'applicant_phone']);
    }

    public function test_only_catalogued_skills_are_accepted(): void
    {
        $posting = $this->posting();

        $this->postJson("/api/v1/public/recruitment/{$posting->slug}/applications", $this->payload(['skills' => ['hacking']]))
            ->assertStatus(422)->assertJsonValidationErrors(['skills.0']);

        $this->postJson("/api/v1/public/recruitment/{$posting->slug}/applications", $this->payload(['skills' => []]))
            ->assertStatus(422)->assertJsonValidationErrors(['skills']);
    }

    public function test_a_posting_that_does_not_invite_applications_has_no_endpoint(): void
    {
        $closed = $this->posting(['status' => 'closed']);
        $notAccepting = $this->posting(['accepts_applications' => false]);

        $this->postJson("/api/v1/public/recruitment/{$closed->slug}/applications", $this->payload())->assertNotFound();
        $this->postJson("/api/v1/public/recruitment/{$notAccepting->slug}/applications", $this->payload())->assertNotFound();
        $this->postJson('/api/v1/public/recruitment/no-such-posting/applications', $this->payload())->assertNotFound();

        $this->assertDatabaseCount('job_applications', 0);
    }

    public function test_a_passed_deadline_closes_the_form_without_an_admin_touching_it(): void
    {
        $posting = $this->posting([
            'application_mode' => 'fixed',
            'opening_date' => now()->subMonth()->toDateString(),
            'application_deadline' => now()->subDay()->toDateString(),
        ]);

        $this->postJson("/api/v1/public/recruitment/{$posting->slug}/applications", $this->payload())
            ->assertStatus(422)->assertJsonValidationErrors(['status']);
    }

    public function test_a_second_live_application_from_the_same_person_is_refused(): void
    {
        $posting = $this->posting();

        $this->postJson("/api/v1/public/recruitment/{$posting->slug}/applications", $this->payload())->assertCreated();
        $this->postJson("/api/v1/public/recruitment/{$posting->slug}/applications", $this->payload(['applicant_email' => 'NADIA@example.test']))
            ->assertStatus(422)->assertJsonValidationErrors(['applicant_email']);

        $this->assertDatabaseCount('job_applications', 1);

        // Re-applying after being closed out is a genuine new application.
        JobApplication::query()->firstOrFail()->update(['status' => 'not_selected']);
        $this->postJson("/api/v1/public/recruitment/{$posting->slug}/applications", $this->payload())->assertCreated();
        $this->assertDatabaseCount('job_applications', 2);
    }

    public function test_a_genuine_cv_and_photo_are_stored_privately(): void
    {
        Storage::fake('uploads_private');
        $posting = $this->posting();

        $this->postJson("/api/v1/public/recruitment/{$posting->slug}/applications", $this->payload([
            'cv' => UploadedFile::fake()->createWithContent('আমার-সিভি.pdf', "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n"),
            'photo' => UploadedFile::fake()->image('me.jpg', 400, 400),
        ]))->assertCreated();

        $application = JobApplication::query()->firstOrFail();
        $this->assertStringStartsWith('applications/cv/', $application->cv_path);
        $this->assertStringStartsWith('applications/photos/', $application->photo_path);
        // The applicant's own filename never reaches the disk.
        $this->assertStringNotContainsString('সিভি', $application->cv_path);
        Storage::disk('uploads_private')->assertExists($application->cv_path);
        Storage::disk('uploads_private')->assertExists($application->photo_path);
    }

    public function test_a_disguised_cv_is_rejected_and_leaves_nothing_behind(): void
    {
        Storage::fake('uploads_private');
        $posting = $this->posting();

        $this->postJson("/api/v1/public/recruitment/{$posting->slug}/applications", $this->payload([
            'photo' => UploadedFile::fake()->image('me.jpg', 300, 300),
            'cv' => UploadedFile::fake()->createWithContent('cv.pdf', "<?php system('id'); ?>"),
        ]))->assertStatus(422)->assertJsonValidationErrors(['cv']);

        $this->assertDatabaseCount('job_applications', 0);
        // The photo that rode along with the rejected CV is not orphaned.
        $this->assertSame([], Storage::disk('uploads_private')->allFiles());
    }

    public function test_a_disguised_photo_is_rejected(): void
    {
        Storage::fake('uploads_private');
        $posting = $this->posting();

        $this->postJson("/api/v1/public/recruitment/{$posting->slug}/applications", $this->payload([
            'photo' => UploadedFile::fake()->createWithContent('me.jpg', '<?php echo 1;'),
        ]))->assertStatus(422)->assertJsonValidationErrors(['photo']);

        $this->assertSame([], Storage::disk('uploads_private')->allFiles());
    }

    public function test_the_honeypot_blocks_a_bot_submission(): void
    {
        $posting = $this->posting();

        $this->postJson("/api/v1/public/recruitment/{$posting->slug}/applications", $this->payload(['website' => 'http://spam.example']))
            ->assertStatus(422)->assertJsonValidationErrors(['website']);

        $this->assertDatabaseCount('job_applications', 0);
    }

    public function test_the_endpoint_is_rate_limited(): void
    {
        $posting = $this->posting();

        for ($i = 1; $i <= 5; $i++) {
            $this->postJson("/api/v1/public/recruitment/{$posting->slug}/applications", $this->payload([
                'applicant_email' => "person{$i}@example.test",
            ]))->assertCreated();
        }

        $this->postJson("/api/v1/public/recruitment/{$posting->slug}/applications", $this->payload([
            'applicant_email' => 'person6@example.test',
        ]))->assertStatus(429);
    }

    public function test_no_public_contract_ever_reads_an_application_back(): void
    {
        $posting = $this->posting();
        $this->postJson("/api/v1/public/recruitment/{$posting->slug}/applications", $this->payload())->assertCreated();

        $application = JobApplication::query()->firstOrFail();
        $application->update(['internal_note' => 'অভ্যন্তরীণ মন্তব্য']);

        $body = $this->getJson("/api/v1/job-postings/{$posting->slug}")->assertOk()->getContent();
        foreach (['nadia@example.test', '+8801711223344', 'অভ্যন্তরীণ মন্তব্য', $application->application_no, 'কুমিল্লা'] as $private) {
            $this->assertStringNotContainsString($private, $body);
        }
    }

    public function test_the_posting_contract_exposes_the_form_route_and_skill_catalogue(): void
    {
        $posting = $this->posting();

        $this->getJson("/api/v1/job-postings/{$posting->slug}")->assertOk()
            ->assertJsonPath('data.accepts_applications', true)
            ->assertJsonPath('data.apply_path', "/recruitment/{$posting->slug}/apply")
            ->assertJsonPath('data.skill_options.0.key', 'ngo_liaison')
            ->assertJsonPath('data.skill_options.0.label', 'NGO / Foundation যোগাযোগ');

        $posting->update(['accepts_applications' => false]);

        $this->getJson("/api/v1/job-postings/{$posting->slug}")->assertOk()
            ->assertJsonPath('data.accepts_applications', false)
            ->assertJsonPath('data.apply_path', null)
            ->assertJsonPath('data.skill_options', null);
    }
}
