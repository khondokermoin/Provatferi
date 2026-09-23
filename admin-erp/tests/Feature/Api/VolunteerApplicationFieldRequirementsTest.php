<?php

namespace Tests\Feature\Api;

use App\Models\JobApplication;
use App\Models\JobPosting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The public store endpoint's validation must always match a posting's own
 * Application Form Settings — this is the "Laravel validation rules must be
 * generated from the stored field requirements" half of the feature. The
 * admin-side configuration itself is covered by
 * Tests\Feature\Admin\RecruitmentFieldRequirementsTest.
 */
class VolunteerApplicationFieldRequirementsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Notification::fake();
        Storage::fake('uploads_private');
    }

    private function posting(array $fieldRequirements = []): JobPosting
    {
        return JobPosting::query()->create([
            'title' => 'প্রভাতফেরীর স্বেচ্ছাসেবী টিমে যুক্ত হওয়ার আহ্বান',
            'slug' => 'volunteer-call-'.uniqid(),
            'description' => 'বিবরণ',
            'employment_type' => 'volunteer',
            'application_mode' => 'rolling',
            'accepts_applications' => true,
            'status' => 'open',
            'field_requirements' => $fieldRequirements,
        ]);
    }

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'applicant_name' => 'নাদিয়া ইসলাম',
            'applicant_email' => 'nadia+'.uniqid().'@example.test',
            'applicant_phone' => '+8801711223344',
            'district' => 'কুমিল্লা',
            'current_location' => 'চান্দিনা, কুমিল্লা',
            'profession' => 'শিক্ষার্থী',
            'experience' => 'দুই বছর ধরে স্বেচ্ছাসেবী কাজ করছি।',
            'skills' => ['fundraising'],
            'contribution' => 'তহবিল সংগ্রহে সময় দিতে চাই।',
            'accuracy_declaration' => '1',
            'privacy_consent' => '1',
            'contact_consent' => '1',
        ], $overrides);
    }

    public function test_a_required_photo_rejects_a_submission_without_one(): void
    {
        $posting = $this->posting(['photo' => 'required']);

        $this->postJson("/api/v1/public/recruitment/{$posting->slug}/applications", $this->payload())
            ->assertStatus(422)->assertJsonValidationErrors(['photo']);
        $this->assertDatabaseCount('job_applications', 0);
    }

    public function test_a_required_photo_accepts_a_submission_with_one(): void
    {
        $posting = $this->posting(['photo' => 'required']);

        $this->postJson("/api/v1/public/recruitment/{$posting->slug}/applications", $this->payload([
            'photo' => UploadedFile::fake()->image('me.jpg', 300, 300),
        ]))->assertCreated();
    }

    public function test_an_optional_photo_accepts_a_submission_without_one(): void
    {
        $posting = $this->posting(['photo' => 'optional']);

        $this->postJson("/api/v1/public/recruitment/{$posting->slug}/applications", $this->payload())->assertCreated();
        $this->assertNull(JobApplication::query()->firstOrFail()->photo_path);
    }

    public function test_an_optional_cv_accepts_a_submission_without_one(): void
    {
        $posting = $this->posting(['cv' => 'optional']);

        $this->postJson("/api/v1/public/recruitment/{$posting->slug}/applications", $this->payload())->assertCreated();
        $this->assertNull(JobApplication::query()->firstOrFail()->cv_path);
    }

    public function test_a_required_cv_rejects_a_submission_without_one(): void
    {
        $posting = $this->posting(['cv' => 'required']);

        $this->postJson("/api/v1/public/recruitment/{$posting->slug}/applications", $this->payload())
            ->assertStatus(422)->assertJsonValidationErrors(['cv']);
        $this->assertDatabaseCount('job_applications', 0);
    }

    public function test_a_required_cv_accepts_a_genuine_pdf(): void
    {
        $posting = $this->posting(['cv' => 'required']);

        $this->postJson("/api/v1/public/recruitment/{$posting->slug}/applications", $this->payload([
            'cv' => UploadedFile::fake()->createWithContent('cv.pdf', "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n"),
        ]))->assertCreated();
    }

    public function test_weekly_availability_can_be_made_required(): void
    {
        $posting = $this->posting(['availability' => 'required']);

        $this->postJson("/api/v1/public/recruitment/{$posting->slug}/applications", $this->payload())
            ->assertStatus(422)->assertJsonValidationErrors(['availability']);

        $this->postJson("/api/v1/public/recruitment/{$posting->slug}/applications", $this->payload([
            'availability' => 'সপ্তাহে ৮ ঘণ্টা',
        ]))->assertCreated();
    }

    public function test_weekly_availability_stays_optional_when_configured_so(): void
    {
        $posting = $this->posting(['availability' => 'optional']);

        $this->postJson("/api/v1/public/recruitment/{$posting->slug}/applications", $this->payload())->assertCreated();
    }

    public function test_skills_can_be_made_optional(): void
    {
        $posting = $this->posting(['skills' => 'optional']);

        // No skills key at all, and an empty selection — both must pass once optional.
        $this->postJson("/api/v1/public/recruitment/{$posting->slug}/applications", $this->payload([
            'skills' => [],
            'applicant_email' => 'a1@example.test',
        ]))->assertCreated();

        $payload = $this->payload(['applicant_email' => 'a2@example.test']);
        unset($payload['skills']);
        $this->postJson("/api/v1/public/recruitment/{$posting->slug}/applications", $payload)->assertCreated();
    }

    public function test_district_and_profession_can_each_be_made_optional_independently(): void
    {
        $posting = $this->posting(['district' => 'optional', 'profession' => 'required']);

        $payload = $this->payload();
        unset($payload['district']);
        $this->postJson("/api/v1/public/recruitment/{$posting->slug}/applications", $payload)->assertCreated();

        $payload2 = $this->payload(['applicant_email' => 'other@example.test']);
        unset($payload2['profession']);
        $this->postJson("/api/v1/public/recruitment/{$posting->slug}/applications", $payload2)
            ->assertStatus(422)->assertJsonValidationErrors(['profession']);
    }

    public function test_identity_and_consent_fields_stay_required_no_matter_what_the_configuration_says(): void
    {
        // These keys are not in JobPosting::CONFIGURABLE_APPLICATION_FIELDS at all —
        // they cannot be switched off even by a hand-crafted stored config.
        $posting = $this->posting();
        $posting->forceFill(['field_requirements' => [
            'photo' => 'optional', 'cv' => 'optional', 'availability' => 'optional',
            'experience' => 'optional', 'contribution' => 'optional', 'skills' => 'optional',
            'district' => 'optional', 'current_location' => 'optional', 'profession' => 'optional',
            'preferred_contact' => 'optional',
        ]])->save();

        $this->postJson("/api/v1/public/recruitment/{$posting->slug}/applications", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors([
                'applicant_name', 'applicant_email', 'applicant_phone',
                'accuracy_declaration', 'privacy_consent', 'contact_consent',
            ]);
    }

    public function test_a_posting_with_no_stored_configuration_validates_exactly_like_before_this_feature(): void
    {
        // field_requirements NULL — every posting that existed before this shipped.
        $posting = JobPosting::query()->create([
            'title' => 'পুরনো বিজ্ঞপ্তি',
            'slug' => 'legacy-'.uniqid(),
            'description' => 'বিবরণ',
            'employment_type' => 'volunteer',
            'application_mode' => 'rolling',
            'accepts_applications' => true,
            'status' => 'open',
        ]);
        $this->assertNull($posting->field_requirements);

        // Photo/CV/availability/preferred_contact optional, everything else required —
        // the exact hardcoded shape VolunteerApplicationApiTest already pins.
        $this->postJson("/api/v1/public/recruitment/{$posting->slug}/applications", $this->payload())->assertCreated();

        $payload = $this->payload(['applicant_email' => 'b@example.test']);
        unset($payload['experience']);
        $this->postJson("/api/v1/public/recruitment/{$posting->slug}/applications", $payload)
            ->assertStatus(422)->assertJsonValidationErrors(['experience']);
    }

    public function test_the_public_contract_exposes_this_postings_field_requirements(): void
    {
        $posting = $this->posting(['photo' => 'required', 'cv' => 'optional']);

        $this->getJson("/api/v1/job-postings/{$posting->slug}")->assertOk()
            ->assertJsonPath('data.field_requirements.photo', 'required')
            ->assertJsonPath('data.field_requirements.cv', 'optional')
            // Untouched keys still resolve through the default, in the same payload.
            ->assertJsonPath('data.field_requirements.experience', 'required');

        $posting->update(['accepts_applications' => false]);
        $this->getJson("/api/v1/job-postings/{$posting->slug}")->assertOk()
            ->assertJsonPath('data.field_requirements', null);
    }
}
