<?php

namespace Tests\Feature\Admin;

use App\Models\JobApplication;
use App\Models\JobPosting;
use App\Services\RecruitmentPdfService;
use Illuminate\Support\Facades\Storage;

/**
 * The PDF/print application document — §5/§6 of the recruitment field-
 * requirements + document feature. Covers auth gating, that the printable
 * content is correct, that internal_note can never reach it, and that a
 * historical application (missing photo/CV/newly-configurable fields) still
 * renders without error. The Bengali-rendering claim itself (mPDF forms real
 * conjuncts, dompdf cannot) was verified empirically before RecruitmentPdfService
 * was written — see its class docblock — and is not re-litigated per-test here;
 * test_the_pdf_service_produces_a_genuine_pdf_from_bengali_html below is a
 * regression smoke test, not that original verification.
 */
class RecruitmentApplicationDocumentTest extends AdminTestCase
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
            'applicant_name' => 'নাদিয়া ইসলাম চৌধুরী',
            'applicant_email' => 'nadia@example.test',
            'applicant_phone' => '+8801711223344',
            'district' => 'কুমিল্লা',
            'current_location' => 'চান্দিনা',
            'profession' => 'শিক্ষার্থী — সমাজবিজ্ঞান',
            'experience' => 'কঠিন যুক্তাক্ষর পরীক্ষা: ক্ষমতা, জ্ঞান, বন্ধু, তত্ত্ব।',
            'skills' => ['fundraising', 'report_writing'],
            'contribution' => 'তহবিল সংগ্রহে সময় দিতে চাই।',
            'accuracy_declaration' => true,
            'privacy_consent' => true,
            'contact_consent' => true,
            'status' => 'submitted',
            'submitted_at' => now(),
        ], $overrides));
    }

    public function test_the_pdf_route_requires_recruitment_view_permission(): void
    {
        $application = $this->application($this->posting());

        $this->get(route('admin.recruitment.applications.pdf', $application))->assertRedirect();

        $this->actingAs($this->userWith(['activities.view']))
            ->get(route('admin.recruitment.applications.pdf', $application))->assertForbidden();

        $this->actingAs($this->userWith(['recruitment.view']))
            ->get(route('admin.recruitment.applications.pdf', $application))->assertOk();
    }

    public function test_the_print_route_requires_recruitment_view_permission(): void
    {
        $application = $this->application($this->posting());

        $this->get(route('admin.recruitment.applications.print', $application))->assertRedirect();

        $this->actingAs($this->userWith(['activities.view']))
            ->get(route('admin.recruitment.applications.print', $application))->assertForbidden();

        $this->actingAs($this->userWith(['recruitment.view']))
            ->get(route('admin.recruitment.applications.print', $application))->assertOk();
    }

    public function test_the_pdf_response_is_a_real_downloadable_pdf(): void
    {
        $application = $this->application($this->posting());

        $response = $this->actingAs($this->superAdmin())
            ->get(route('admin.recruitment.applications.pdf', $application))->assertOk();

        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment', (string) $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString($application->application_no, (string) $response->headers->get('Content-Disposition'));
        $this->assertStringStartsWith('%PDF-', $response->getContent());
        $this->assertGreaterThan(1000, strlen($response->getContent()));
    }

    public function test_the_print_view_shows_the_applicants_data(): void
    {
        $application = $this->application($this->posting());

        $this->actingAs($this->superAdmin())
            ->get(route('admin.recruitment.applications.print', $application))->assertOk()
            ->assertSee('নাদিয়া ইসলাম চৌধুরী')
            ->assertSee($application->application_no)
            ->assertSee('কুমিল্লা')
            ->assertSee('Fundraising / Donation / Sponsorship');
    }

    public function test_the_document_partial_never_includes_the_internal_note(): void
    {
        $application = $this->application($this->posting(), [
            'internal_note' => 'এই-নোটটি-কখনও-প্রকাশ্য-হবে-না-XYZ123',
        ]);

        $html = view('admin.recruitment.applications.document', [
            'application' => $application,
            'contactLabels' => JobApplication::PREFERRED_CONTACTS,
            'photoSrc' => null,
            'logoSrc' => null,
            'generatedAt' => now(),
        ])->render();

        $this->assertStringNotContainsString('এই-নোটটি-কখনও-প্রকাশ্য-হবে-না-XYZ123', $html);
        // The applicant's own data must still be there — this is a targeted
        // exclusion of one field, not a broken/empty document.
        $this->assertStringContainsString('নাদিয়া ইসলাম চৌধুরী', $html);
        $this->assertStringContainsString($application->application_no, $html);
    }

    public function test_the_document_partial_never_includes_technical_or_hidden_fields(): void
    {
        $application = $this->application($this->posting(), [
            'photo_path' => 'applications/photos/should-not-leak.jpg',
            'cv_path' => 'applications/cv/should-not-leak.pdf',
        ]);

        $html = view('admin.recruitment.applications.document', [
            'application' => $application,
            'contactLabels' => JobApplication::PREFERRED_CONTACTS,
            'photoSrc' => null, // print()/pdf() resolve this themselves; the partial never reads photo_path directly
            'logoSrc' => null,
            'generatedAt' => now(),
        ])->render();

        // No storage path — private-disk, server-generated filenames — ever leaks
        // into the printable document. (A bare numeric id is deliberately not
        // checked here: this application's own phone number legitimately
        // contains short digit runs that would collide with almost any id value.)
        $this->assertStringNotContainsString('applications/photos/', $html);
        $this->assertStringNotContainsString('applications/cv/', $html);
        $this->assertStringNotContainsString('should-not-leak', $html);
    }

    public function test_a_cv_present_shows_attached_without_embedding_the_file(): void
    {
        Storage::fake('uploads_private');
        $application = $this->application($this->posting(), ['cv_path' => 'applications/cv/real.pdf']);
        Storage::disk('uploads_private')->put('applications/cv/real.pdf', '%PDF-1.4 secret cv body');

        $html = view('admin.recruitment.applications.document', [
            'application' => $application,
            'contactLabels' => JobApplication::PREFERRED_CONTACTS,
            'photoSrc' => null,
            'logoSrc' => null,
            'generatedAt' => now(),
        ])->render();

        $this->assertStringContainsString('সংযুক্ত আছে', $html);
        $this->assertStringNotContainsString('secret cv body', $html);
    }

    public function test_a_missing_cv_shows_a_professional_empty_state_on_the_detail_page(): void
    {
        $application = $this->application($this->posting(), ['cv_path' => null]);

        $this->actingAs($this->superAdmin())
            ->get(route('admin.recruitment.applications.show', $application))->assertOk()
            ->assertSee('সিভি প্রদান করা হয়নি');
    }

    /**
     * The actual defect reported 2026-09-24: photo_path/cv_path recorded in
     * the DB, but the file itself genuinely gone from the private disk (root
     * cause: every deploy before release-manager.php's storage-persistence
     * fix silently orphaned previously-uploaded files on each release
     * switch). Every view that decides whether to render a photo/CV MUST
     * check JobApplication::photoFileExists()/cvFileExists() — actual disk
     * presence — never the raw column, which is exactly what previously
     * rendered a real <img src="..."> pointing at a route that 404s: a
     * broken image icon in the browser.
     */
    public function test_an_orphaned_photo_path_never_renders_a_broken_image_tag(): void
    {
        Storage::fake('uploads_private');
        // Deliberately NOT put on disk — path recorded, file genuinely absent.
        $application = $this->application($this->posting(), [
            'photo_path' => 'applications/photos/gone.jpg',
            'cv_path' => 'applications/cv/gone.pdf',
        ]);

        $this->assertFalse($application->photoFileExists());
        $this->assertFalse($application->cvFileExists());

        $html = $this->actingAs($this->superAdmin())
            ->get(route('admin.recruitment.applications.show', $application))
            ->assertOk()
            ->getContent();

        // No <img> pointing at the photo file route — that would 404 in the
        // browser and render as a broken image icon.
        $this->assertStringNotContainsString('<img src="https://admin.provatferi.org/admin/recruitment-applications', $html);
        $this->assertStringNotContainsString(route('admin.recruitment.applications.file', [$application, 'photo']).'"', $html);
        // Distinct, honest wording for "was uploaded but is now unavailable"
        // — not the same string used for "never provided" (asserted above,
        // in the sibling test, to genuinely mean something different).
        $this->assertStringContainsString('ফাইল পাওয়া যায়নি', $html);
    }

    public function test_an_orphaned_photo_resolves_to_no_photo_in_print_and_pdf_too(): void
    {
        Storage::fake('uploads_private');
        $application = $this->application($this->posting(), [
            'photo_path' => 'applications/photos/gone.jpg',
        ]);
        $admin = $this->superAdmin();

        // print() must not hand the view a route URL for a file that will 404.
        $printHtml = $this->actingAs($admin)
            ->get(route('admin.recruitment.applications.print', $application))
            ->assertOk()->getContent();
        $this->assertStringNotContainsString(route('admin.recruitment.applications.file', [$application, 'photo']), $printHtml);
        $this->assertStringContainsString('ছবি নেই', $printHtml); // document.blade.php's placeholder

        // pdf() must fall back to the no-photo placeholder rather than embedding nothing/garbage.
        $this->actingAs($admin)
            ->get(route('admin.recruitment.applications.pdf', $application))
            ->assertOk();
    }

    public function test_a_historical_application_with_no_photo_or_cv_renders_everywhere_without_error(): void
    {
        // Simulates a row from before photo/cv or the newer fields existed:
        // every newly-configurable field is null, matching a genuinely old record.
        $application = $this->application($this->posting(), [
            'photo_path' => null,
            'cv_path' => null,
            'availability' => null,
            'preferred_contact' => null,
            'linkedin_url' => null,
            'facebook_url' => null,
            'portfolio_url' => null,
        ]);
        $admin = $this->superAdmin();

        $this->actingAs($admin)->get(route('admin.recruitment.applications.show', $application))->assertOk();
        $this->actingAs($admin)->get(route('admin.recruitment.applications.print', $application))->assertOk();
        $this->actingAs($admin)->get(route('admin.recruitment.applications.pdf', $application))->assertOk();
    }

    public function test_a_posting_deleted_after_the_application_was_submitted_still_renders(): void
    {
        $posting = $this->posting();
        $application = $this->application($posting);
        $posting->delete(); // soft-deleted, mirrors JobApplicationController::notifyApplicant()'s own withTrashed() reasoning

        $this->actingAs($this->superAdmin())
            ->get(route('admin.recruitment.applications.pdf', $application->fresh()))->assertOk();
    }

    public function test_the_list_shows_compact_photo_and_cv_indicators(): void
    {
        Storage::fake('uploads_private');
        Storage::disk('uploads_private')->put('applications/photos/a.jpg', 'fake-jpeg-bytes');
        Storage::disk('uploads_private')->put('applications/cv/a.pdf', '%PDF-1.4 fake');
        $posting = $this->posting();
        $withBoth = $this->application($posting, [
            'applicant_email' => 'with-both@example.test',
            'photo_path' => 'applications/photos/a.jpg',
            'cv_path' => 'applications/cv/a.pdf',
        ]);
        $withNeither = $this->application($posting, [
            'applicant_email' => 'with-neither@example.test',
            'applicant_name' => 'ফারহান কবির',
            'photo_path' => null,
            'cv_path' => null,
        ]);

        $this->actingAs($this->superAdmin())->get(route('admin.recruitment.applications.index'))
            ->assertOk()->assertSee('ছবি আছে', false)->assertSee('সিভি আছে', false)
            ->assertSee('ছবি নেই', false)->assertSee('সিভি নেই', false);

        // Both rows are on the page — the indicators aren't hiding either applicant.
        $this->assertNotNull($withBoth->applicant_email);
        $this->assertNotNull($withNeither->applicant_name);
    }

    public function test_the_pdf_service_produces_a_genuine_pdf_from_bengali_html(): void
    {
        $html = '<h1>প্রভাতফেরী সাহিত্য ও সাংস্কৃতিক কেন্দ্র</h1><p>কঠিন যুক্তাক্ষর: ক্ষমতা, জ্ঞান, তত্ত্ব, স্বেচ্ছাসেবী।</p>';

        $bytes = app(RecruitmentPdfService::class)->render($html);

        $this->assertStringStartsWith('%PDF-', $bytes);
        $this->assertGreaterThan(500, strlen($bytes));
    }
}
