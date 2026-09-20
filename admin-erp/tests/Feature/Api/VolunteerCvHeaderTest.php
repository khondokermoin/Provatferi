<?php

namespace Tests\Feature\Api;

use App\Models\JobApplication;
use App\Models\JobPosting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Regression cover for the live intake failure.
 *
 * The existing suite passed 325/325 while this defect was in production,
 * because every CV fixture writes "%PDF-1.4..." at byte 0 — the one layout
 * that never broke. Production evidence (an empty applications/photos
 * directory created at 18:02, no cv/ directory ever created, and zero rows in
 * job_applications) showed a real applicant losing their submission when
 * storeCv() refused their file and storeFiles() then deleted the photo that
 * had ridden along with it.
 *
 * These cases pin the header-position contract in both directions: genuine
 * PDFs whose header is offset by a BOM or whitespace must be ACCEPTED, and a
 * file whose header only appears beyond the 1024-byte window must still be
 * REFUSED. The photo cases cover WEBP, which the public form offers but the
 * suite never exercised.
 */
class VolunteerCvHeaderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Storage::fake('uploads_private');
    }

    private function posting(): JobPosting
    {
        return JobPosting::query()->create([
            'title' => 'স্বেচ্ছাসেবী আহ্বান',
            'slug' => 'volunteer-'.uniqid(),
            'description' => 'বিবরণ',
            'status' => 'open',
            'accepts_applications' => true,
            'application_mode' => 'rolling',
            'published_at' => now(),
        ]);
    }

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'applicant_name' => 'নাদিয়া রহমান',
            'applicant_email' => 'nadia'.uniqid().'@example.test',
            'applicant_phone' => '+8801700000000',
            'district' => 'কুমিল্লা',
            'current_location' => 'চান্দিনা',
            'profession' => 'শিক্ষার্থী',
            'experience' => 'পূর্ব অভিজ্ঞতা নেই।',
            'skills' => ['media_content'],
            'contribution' => 'সপ্তাহে কিছু সময় দিতে চাই।',
            'accuracy_declaration' => '1',
            'privacy_consent' => '1',
            'contact_consent' => '1',
        ], $overrides);
    }

    private function submit(JobPosting $posting, array $overrides = [])
    {
        return $this->postJson("/api/v1/public/recruitment/{$posting->slug}/applications", $this->payload($overrides));
    }

    /** The layout the old check required — must keep working. */
    public function test_a_pdf_whose_header_starts_at_byte_zero_is_accepted(): void
    {
        $posting = $this->posting();

        $this->submit($posting, [
            'cv' => UploadedFile::fake()->createWithContent('cv.pdf', "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n"),
        ])->assertCreated();

        $this->assertStringStartsWith('applications/cv/', JobApplication::query()->firstOrFail()->cv_path);
    }

    /** THE REGRESSION: Word and several online converters emit this. */
    public function test_a_pdf_behind_a_utf8_bom_is_accepted(): void
    {
        $posting = $this->posting();

        $this->submit($posting, [
            'cv' => UploadedFile::fake()->createWithContent('cv.pdf', "\xEF\xBB\xBF%PDF-1.4\n1 0 obj<<>>endobj\n%%EOF\n"),
        ])->assertCreated();

        Storage::disk('uploads_private')->assertExists(JobApplication::query()->firstOrFail()->cv_path);
    }

    /** THE REGRESSION: a stray newline before the header is still a valid PDF. */
    public function test_a_pdf_behind_leading_whitespace_is_accepted(): void
    {
        $posting = $this->posting();

        $this->submit($posting, [
            'cv' => UploadedFile::fake()->createWithContent('cv.pdf', "\n  %PDF-1.7\n1 0 obj<<>>endobj\n%%EOF\n"),
        ])->assertCreated();
    }

    /** The widened window must not become "anywhere in the file". */
    public function test_a_header_beyond_the_scan_window_is_still_refused(): void
    {
        $posting = $this->posting();

        $this->submit($posting, [
            'cv' => UploadedFile::fake()->createWithContent('cv.pdf', str_repeat('A', 2000)."%PDF-1.4\n%%EOF\n"),
        ])->assertStatus(422)->assertJsonValidationErrors(['cv']);

        $this->assertDatabaseCount('job_applications', 0);
    }

    /** The disguised-upload defence must survive the change. */
    public function test_a_disguised_cv_is_still_refused_and_leaves_no_orphan_photo(): void
    {
        $posting = $this->posting();

        $this->submit($posting, [
            'photo' => UploadedFile::fake()->image('me.jpg', 300, 300),
            'cv' => UploadedFile::fake()->createWithContent('cv.pdf', "<?php system('id'); ?>"),
        ])->assertStatus(422)->assertJsonValidationErrors(['cv']);

        $this->assertDatabaseCount('job_applications', 0);
        // This is the branch that cost the live applicant their photo.
        $this->assertSame([], Storage::disk('uploads_private')->allFiles());
    }

    /** The form offers WEBP; the suite only ever exercised JPEG and PNG. */
    public function test_a_webp_photo_is_accepted(): void
    {
        if (!function_exists('imagewebp')) {
            $this->markTestSkipped('GD was built without WEBP support on this machine.');
        }

        $posting = $this->posting();

        $this->submit($posting, [
            'photo' => UploadedFile::fake()->image('me.webp', 320, 320),
        ])->assertCreated();

        Storage::disk('uploads_private')->assertExists(JobApplication::query()->firstOrFail()->photo_path);
    }

    /** A CV-less application is the common case and must never regress. */
    public function test_an_application_with_no_attachments_is_accepted(): void
    {
        $posting = $this->posting();

        $this->submit($posting)->assertCreated();

        $application = JobApplication::query()->firstOrFail();
        $this->assertNull($application->cv_path);
        $this->assertNull($application->photo_path);
    }
}
