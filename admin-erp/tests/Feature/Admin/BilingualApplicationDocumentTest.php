<?php

namespace Tests\Feature\Admin;

use App\Models\JobApplication;
use App\Models\JobPosting;

/**
 * Phase 3 Step 4/6: the printable/PDF application document now has an
 * explicit document language (?doclang=bn|en), independent of the admin's
 * own panel locale, defaulting to that panel locale when omitted. Covers:
 * both document languages render with the right chrome text, the default
 * follows the admin's current ui_locale, explicit selection overrides that
 * default in both directions, and — the one rule that must never break —
 * the applicant's own submitted text is shown verbatim in either document
 * language, never machine-translated.
 */
class BilingualApplicationDocumentTest extends AdminTestCase
{
    private function posting(): JobPosting
    {
        return JobPosting::query()->create([
            'title' => 'স্বেচ্ছাসেবী আহ্বান',
            'slug' => 'volunteer-'.uniqid(),
            'description' => 'বিবরণ',
            'employment_type' => 'volunteer',
            'application_mode' => 'rolling',
            'accepts_applications' => true,
            'status' => 'open',
        ]);
    }

    private function application(JobPosting $posting): JobApplication
    {
        return JobApplication::query()->create([
            'application_no' => JobApplication::generateApplicationNo(),
            'job_posting_id' => $posting->id,
            'applicant_name' => 'নাদিয়া ইসলাম চৌধুরী',
            'applicant_email' => 'nadia@example.test',
            'applicant_phone' => '+8801711223344',
            'district' => 'কুমিল্লা',
            'current_location' => 'চান্দিনা',
            'profession' => 'শিক্ষার্থী — সমাজবিজ্ঞান',
            'experience' => 'কঠিন যুক্তাক্ষর পরীক্ষা: ক্ষমতা, জ্ঞান, বন্ধু, তত্ত্ব।',
            'other_skills' => 'কাস্টম দক্ষতা XYZ৭৮৯',
            'contribution' => 'তহবিল সংগ্রহে সময় দিতে চাই।',
            'accuracy_declaration' => true,
            'privacy_consent' => true,
            'contact_consent' => true,
            'status' => 'submitted',
            'submitted_at' => now(),
        ]);
    }

    public function test_print_defaults_to_the_admins_current_panel_locale_when_no_doclang_is_given(): void
    {
        $admin = $this->superAdmin();
        $admin->update(['ui_locale' => 'en']);
        $application = $this->application($this->posting());

        $html = $this->actingAs($admin)->get(route('admin.recruitment.applications.print', $application))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Volunteer Application', $html);
        $this->assertSame('lang="en"', $this->extractHtmlLangAttribute($html));
    }

    public function test_explicit_doclang_overrides_the_admins_panel_locale_in_both_directions(): void
    {
        $admin = $this->superAdmin();
        $admin->update(['ui_locale' => 'bn']);
        $application = $this->application($this->posting());

        // Panel is Bangla, but an English document is explicitly requested.
        $englishHtml = $this->actingAs($admin)
            ->get(route('admin.recruitment.applications.print', [$application, 'doclang' => 'en']))
            ->assertOk()->getContent();
        $this->assertStringContainsString('Volunteer Application', $englishHtml);
        $this->assertStringContainsString('Declarations &amp; Consent', $englishHtml);

        $admin->update(['ui_locale' => 'en']);

        // Panel is now English, but a Bangla document is explicitly requested.
        $banglaHtml = $this->actingAs($admin)
            ->get(route('admin.recruitment.applications.print', [$application, 'doclang' => 'bn']))
            ->assertOk()->getContent();
        $this->assertStringContainsString('স্বেচ্ছাসেবী আবেদনপত্র', $banglaHtml);
        $this->assertStringContainsString('ঘোষণা ও সম্মতি', $banglaHtml);
    }

    public function test_an_unsupported_doclang_value_falls_back_to_the_panel_locale_instead_of_erroring(): void
    {
        $admin = $this->superAdmin();
        $admin->update(['ui_locale' => 'bn']);
        $application = $this->application($this->posting());

        $this->actingAs($admin)
            ->get(route('admin.recruitment.applications.print', [$application, 'doclang' => 'fr']))
            ->assertOk()
            ->assertSee('স্বেচ্ছাসেবী আবেদনপত্র');
    }

    public function test_the_english_pdf_renders_a_real_pdf_with_english_chrome(): void
    {
        $application = $this->application($this->posting());

        $response = $this->actingAs($this->superAdmin())
            ->get(route('admin.recruitment.applications.pdf', [$application, 'doclang' => 'en']))
            ->assertOk();

        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF-', $response->getContent());
    }

    public function test_applicant_submitted_text_is_shown_verbatim_regardless_of_document_language(): void
    {
        $application = $this->application($this->posting());

        foreach (['bn', 'en'] as $doclang) {
            $html = $this->actingAs($this->superAdmin())
                ->get(route('admin.recruitment.applications.print', [$application, 'doclang' => $doclang]))
                ->assertOk()->getContent();

            // Every one of these is the applicant's own submitted text — it
            // must appear byte-for-byte the same no matter which document
            // language was requested; nothing here is ever machine-translated.
            $this->assertStringContainsString('নাদিয়া ইসলাম চৌধুরী', $html);
            $this->assertStringContainsString('কুমিল্লা', $html);
            $this->assertStringContainsString('চান্দিনা', $html);
            $this->assertStringContainsString('শিক্ষার্থী — সমাজবিজ্ঞান', $html);
            $this->assertStringContainsString('কঠিন যুক্তাক্ষর পরীক্ষা: ক্ষমতা, জ্ঞান, বন্ধু, তত্ত্ব।', $html);
            $this->assertStringContainsString('কাস্টম দক্ষতা XYZ৭৮৯', $html);
            $this->assertStringContainsString('তহবিল সংগ্রহে সময় দিতে চাই।', $html);
        }
    }

    public function test_the_documents_own_chrome_switches_while_applicant_text_does_not(): void
    {
        $application = $this->application($this->posting());

        $bnHtml = $this->actingAs($this->superAdmin())
            ->get(route('admin.recruitment.applications.print', [$application, 'doclang' => 'bn']))
            ->assertOk()->getContent();
        $enHtml = $this->actingAs($this->superAdmin())
            ->get(route('admin.recruitment.applications.print', [$application, 'doclang' => 'en']))
            ->assertOk()->getContent();

        // Document chrome differs between the two languages...
        $this->assertStringContainsString('আবেদন নম্বর', $bnHtml);
        $this->assertStringContainsString('Application No.', $enHtml);
        $this->assertStringNotContainsString('Application No.', $bnHtml);
        $this->assertStringNotContainsString('আবেদন নম্বর', $enHtml);

        // ...but the applicant's own name is identical in both.
        $this->assertStringContainsString('নাদিয়া ইসলাম চৌধুরী', $bnHtml);
        $this->assertStringContainsString('নাদিয়া ইসলাম চৌধুরী', $enHtml);
    }

    public function test_the_language_switch_links_point_at_both_document_languages(): void
    {
        $application = $this->application($this->posting());

        $html = $this->actingAs($this->superAdmin())
            ->get(route('admin.recruitment.applications.print', $application))
            ->assertOk()->getContent();

        $this->assertStringContainsString('doclang=bn', $html);
        $this->assertStringContainsString('doclang=en', $html);
    }

    private function extractHtmlLangAttribute(string $html): ?string
    {
        return preg_match('/<html\s+([^>]*lang="[a-z]+"[^>]*)>/', $html, $m)
            ? trim(preg_replace('/^.*?(lang="[a-z]+").*$/', '$1', $m[1]))
            : null;
    }
}
