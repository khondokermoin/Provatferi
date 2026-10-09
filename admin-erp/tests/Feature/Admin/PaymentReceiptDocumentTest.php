<?php

namespace Tests\Feature\Admin;

use App\Models\ApprovalHistory;
use App\Models\Member;
use App\Models\Membership;
use App\Models\MembershipApplication;
use App\Models\MembershipType;
use App\Models\Payment;
use App\Models\PaymentReceipt;
use App\Models\User;
use App\Services\MembershipApprovalService;
use App\Services\MembershipDueLedger;
use App\Services\PaymentReceiptPdfService;
use App\Services\Pdf\PdfInspector;
use App\Services\Pdf\PdfRenderer;
use Illuminate\Support\Carbon;
use Tests\Concerns\MakesMembershipTypes;

/**
 * Membership task 5 (2026-10-08): the receipt as a document and who may open it — the admin's print view and PDF in
 * Bangla and English on the Dhaka clock, the links on the payment screens, the permission, and the member portal's own
 * receipts (never anyone else's).
 *
 * The clock starts at 2026-10-07 18:30 UTC — already 00:30 on 8 October in Dhaka, the moment the old screens printed as 7 October.
 */
class PaymentReceiptDocumentTest extends AdminTestCase
{
    use MakesMembershipTypes;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->at('2026-10-07 18:30:00');
        $this->admin = $this->superAdmin();
        $this->admin->forceFill(['name' => 'Verifier Admin', 'ui_locale' => 'en'])->save();
    }

    private function at(string $utc): void
    {
        $this->travelTo(Carbon::parse($utc, 'UTC'));
    }

    private function type(string $code = 'LM', string $registration = '500', string $monthly = '200', string $name = 'আজীবন সদস্য', ?string $nameEn = 'Lifetime Member'): MembershipType
    {
        return $this->makeMembershipType(
            ['name' => $name, 'name_en' => $nameEn, 'code' => $code, 'slug' => 'rd-'.uniqid(), 'is_public_self_apply' => true, 'is_public_visible' => true],
            ['registration' => $registration, 'monthly' => $monthly],
        );
    }

    /** Every applicant is a different person unless told otherwise (the same e-mail or mobile would mean the same person). */
    private function application(MembershipType $type, string $name = 'রহিম উদ্দিন', ?string $email = null, ?string $phone = null): MembershipApplication
    {
        return MembershipApplication::query()->create([
            'applicant_name' => $name, 'applicant_email' => $email ?? 'rd-'.uniqid().'@example.com', 'applicant_phone' => $phone ?? '017'.random_int(10000000, 99999999),
            'membership_type_id' => $type->id, 'status' => 'under_review', 'review_notes' => 'INTERNAL-NOTE-DO-NOT-PRINT',
        ]);
    }

    /** A verified registration payment, through the real admin actions. */
    private function registrationReceipt(MembershipApplication $application, string $reference = 'REG-REF-77'): PaymentReceipt
    {
        $this->actingAs($this->admin)->post(route('admin.membership.payments.store', $application), [
            'amount_expected' => '500', 'amount_received' => '500', 'received_at' => '2026-10-08', 'reference' => $reference,
        ])->assertSessionHasNoErrors();
        $payment = $application->payments()->latest('id')->firstOrFail();
        $this->actingAs($this->admin)->patch(route('admin.membership.payments.verify', $payment))->assertSessionHas('success');

        return PaymentReceipt::query()->where('payment_id', $payment->id)->firstOrFail();
    }

    private function member(MembershipType $type): Membership
    {
        $application = $this->application($type, 'করিম আহমেদ');
        app(MembershipApprovalService::class)->approve($application, $this->admin);

        return Membership::query()->where('membership_application_id', $application->id)->sole();
    }

    private function monthlyReceipt(Membership $membership, string $amount, string $purpose = 'due', ?int $dueId = null): PaymentReceipt
    {
        $ledger = app(MembershipDueLedger::class);
        $payment = $ledger->recordPayment($membership, $this->admin, ['purpose' => $purpose, 'due_id' => $dueId, 'amount' => $amount, 'received_at' => '2026-10-08', 'method' => 'bank_transfer', 'reference' => 'TRX-9']);
        $ledger->verifyPayment($payment, $this->admin);

        return PaymentReceipt::query()->where('payment_id', $payment->id)->firstOrFail();
    }

    /** The PDF's text, when xpdf's pdftotext is installed (then the PDF itself is read; otherwise only its structure is checked). */
    private function pdfText(string $bytes): ?string
    {
        exec('pdftotext -v 2>&1', $version);
        if (! str_contains(implode(' ', $version), 'pdftotext version')) {
            return null;
        }
        $in = tempnam(sys_get_temp_dir(), 'rcpt');
        file_put_contents($in, $bytes);
        exec('pdftotext -enc UTF-8 -layout '.escapeshellarg($in).' '.escapeshellarg($in.'.txt'));
        $text = is_file($in.'.txt') ? (string) file_get_contents($in.'.txt') : null;
        @unlink($in);
        @unlink($in.'.txt');

        return $text;
    }

    /* ================================================================ the print view */

    public function test_the_print_view_in_english_shows_the_receipt_on_the_dhaka_clock(): void
    {
        $application = $this->application($this->type());
        $receipt = $this->registrationReceipt($application);

        $html = $this->actingAs($this->admin)->get(route('admin.membership.receipts.show', [$receipt, 'lang' => 'en']))->assertOk()->getContent();

        foreach ([
            'PLCC-RCT-2026-000001', 'Payment Receipt', 'Official Receipt', 'Registration Fee', '৳500', 'Amount received',
            'রহিম উদ্দিন', 'Lifetime Member', $application->application_no, 'Cash', 'REG-REF-77', 'Verifier Admin',
            '8 October 2026', // the payment date: the calendar day as typed
            '8 October 2026, 00:30', // issued 18:30 UTC = 00:30 in Dhaka — not 7 October
            'Provatferi Literary and Cultural Center (PLCC)', 'প্রভাতফেরী সাহিত্য ও সাংস্কৃতিক কেন্দ্র',
        ] as $expected) {
            $this->assertStringContainsString($expected, $html, "the English receipt shows {$expected}");
        }
        $this->assertStringNotContainsString('7 October', $html);
        $this->assertStringNotContainsString('৮ অক্টোবর', $html, 'no Bengali digits in the English receipt');
    }

    public function test_the_print_view_in_bangla_uses_bengali_words_and_digits(): void
    {
        $receipt = $this->registrationReceipt($this->application($this->type()));

        $html = $this->actingAs($this->admin)->get(route('admin.membership.receipts.show', [$receipt, 'lang' => 'bn']))->assertOk()->getContent();

        foreach (['PLCC-RCT-2026-000001', 'পরিশোধের রসিদ', 'অফিসিয়াল রসিদ', 'নিবন্ধন ফি', '৳৫০০', 'গৃহীত অর্থ', 'রসিদ নং', 'নগদ', 'আজীবন সদস্য', '৮ অক্টোবর ২০২৬', '৮ অক্টোবর ২০২৬, ০০:৩০', 'গ্রহণ করেছেন', 'যাচাই করেছেন'] as $expected) {
            $this->assertStringContainsString($expected, $html, "the Bangla receipt shows {$expected}");
        }
        $this->assertStringNotContainsString('Registration Fee', $html);
        $this->assertStringNotContainsString('Lifetime Member', $html, 'the Bangla receipt names the type in Bangla');
    }

    public function test_the_language_defaults_to_the_admins_own_and_an_unknown_one_falls_back_to_it(): void
    {
        $receipt = $this->registrationReceipt($this->application($this->type()));

        $this->actingAs($this->admin)->get(route('admin.membership.receipts.show', $receipt))->assertSee('Payment Receipt');
        $this->get(route('admin.membership.receipts.show', [$receipt, 'lang' => 'fr']))->assertSee('Payment Receipt');
        $this->admin->forceFill(['ui_locale' => 'bn'])->save();
        $this->get(route('admin.membership.receipts.show', $receipt))->assertSee('পরিশোধের রসিদ');
    }

    public function test_an_english_receipt_names_a_type_with_no_english_name_in_bangla_never_translated(): void
    {
        $receipt = $this->registrationReceipt($this->application($this->type('GM', '500', '0', 'সাধারণ সদস্য', null)));

        $this->actingAs($this->admin)->get(route('admin.membership.receipts.show', [$receipt, 'lang' => 'en']))->assertOk()->assertSee('সাধারণ সদস্য');
    }

    public function test_the_receipt_prints_nothing_private_and_no_raw_values(): void
    {
        $application = $this->application($this->type(), 'রহিম উদ্দিন', 'secret-applicant@example.com', '01711223344');
        $receipt = $this->registrationReceipt($application);
        app(MembershipApprovalService::class)->approve($application->fresh(), $this->admin, 'ANOTHER-INTERNAL-NOTE');
        $membership = Membership::query()->where('membership_application_id', $application->id)->sole();
        $membership->member->forceFill(['address' => 'SECRET-ADDRESS', 'email' => 'secret-account@example.com'])->save();
        $monthly = $this->monthlyReceipt($membership, '200', 'due', $membership->dues()->firstOrFail()->id);

        foreach ([$receipt, $monthly] as $r) {
            foreach (['en', 'bn'] as $lang) {
                $html = $this->actingAs($this->admin)->get(route('admin.membership.receipts.show', [$r, 'lang' => $lang]))->assertOk()->getContent();
                foreach (['INTERNAL-NOTE-DO-NOT-PRINT', 'ANOTHER-INTERNAL-NOTE', 'SECRET-ADDRESS', 'secret-applicant@example.com', 'secret-account@example.com', '01711223344', 'password', 'monthly_contribution', 'bank_transfer', 'mobile_banking', 'registration_fee'] as $private) {
                    $this->assertStringNotContainsString($private, $html, "{$r->receipt_no} ({$lang}) must not print {$private}");
                }
            }
        }
    }

    public function test_a_multi_month_receipt_lists_every_month_and_the_totals(): void
    {
        $this->at('2026-01-10 04:00:00');
        $membership = $this->member($this->type('QD', '0', '200'));
        $this->at('2026-03-15 04:00:00');
        app(MembershipDueLedger::class)->generateFor($membership);
        $receipt = $this->monthlyReceipt($membership, '600', 'advance');

        $en = $this->actingAs($this->admin)->get(route('admin.membership.receipts.show', [$receipt, 'lang' => 'en']))->assertOk()->getContent();
        foreach (['Advance Contribution', 'January 2026', 'February 2026', 'March 2026', 'Applied to monthly contributions', 'Total received', '৳600', 'Bank transfer'] as $expected) {
            $this->assertStringContainsString($expected, $en);
        }
        $this->assertSame(3, substr_count($en, 'data-testid="receipt-line"'));
        $this->assertStringNotContainsString('Advance credit', $en, 'nothing is left over: no credit row');

        $bn = $this->actingAs($this->admin)->get(route('admin.membership.receipts.show', [$receipt, 'lang' => 'bn']))->assertOk()->getContent();
        foreach (['অগ্রিম চাঁদা', 'জানুয়ারি ২০২৬', 'ফেব্রুয়ারি ২০২৬', 'মার্চ ২০২৬', 'মোট গৃহীত', '৳৬০০'] as $expected) {
            $this->assertStringContainsString($expected, $bn);
        }
    }

    public function test_an_advance_with_credit_left_shows_the_split_and_it_adds_up(): void
    {
        $this->at('2026-01-10 04:00:00');
        $membership = $this->member($this->type('QD', '0', '200')); // January only
        $receipt = $this->monthlyReceipt($membership, '500', 'advance');

        $html = $this->actingAs($this->admin)->get(route('admin.membership.receipts.show', [$receipt, 'lang' => 'en']))->assertOk()->getContent();
        $this->assertStringContainsString('January 2026', $html);
        $this->assertMatchesRegularExpression('/data-testid="receipt-applied">৳200</', $html);
        $this->assertMatchesRegularExpression('/data-testid="receipt-credit">৳300</', $html);
        $this->assertMatchesRegularExpression('/data-testid="receipt-amount">৳500</', $html);
        $this->assertStringContainsString('Held for the months ahead', $html);
    }

    /* ================================================================ the PDF */

    public function test_the_pdf_is_a_real_pdf_in_either_language_and_asking_again_changes_nothing(): void
    {
        $receipt = $this->registrationReceipt($this->application($this->type()));
        $this->actingAs($this->admin);

        $english = $this->get(route('admin.membership.receipts.pdf', [$receipt, 'lang' => 'en']));
        $english->assertOk();
        $this->assertSame('application/pdf', $english->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF-', $english->getContent());
        $this->assertSame('attachment; filename="PLCC-RCT-2026-000001.pdf"', $english->headers->get('Content-Disposition'));
        $this->assertStringContainsString('no-store', (string) $english->headers->get('Cache-Control'));
        $this->assertSame('nosniff', $english->headers->get('X-Content-Type-Options'));

        $bangla = $this->get(route('admin.membership.receipts.pdf', [$receipt, 'lang' => 'bn', 'disposition' => 'inline']));
        $bangla->assertOk();
        $this->assertStringStartsWith('%PDF-', $bangla->getContent());
        $this->assertSame('inline; filename="PLCC-RCT-2026-000001.pdf"', $bangla->headers->get('Content-Disposition'));

        // Asked for again (and again): still ONE receipt, ONE number, one audit entry — and the same facts.
        $this->get(route('admin.membership.receipts.pdf', [$receipt, 'lang' => 'en']))->assertOk();
        $this->assertSame([1, 1], [PaymentReceipt::query()->count(), ApprovalHistory::query()->where('action', 'receipt_issued')->count()]);

        if (($text = $this->pdfText($english->getContent())) !== null) {
            foreach (['PLCC-RCT-2026-000001', 'Payment Receipt', 'Registration Fee', '500', '8 October 2026', 'Lifetime Member', 'REG-REF-77', 'Verifier Admin', 'Provatferi Literary and Cultural Center'] as $expected) {
                $this->assertStringContainsString($expected, $text, "the English PDF's text contains {$expected}");
            }
        }
        if (($text = $this->pdfText($bangla->getContent())) !== null) {
            // Bengali WORDS are not asserted: shaped, their glyphs are in visual order and conjuncts have no Unicode value (see
            // PdfRenderer), so extraction returns fragments. What is Latin or a digit survives, and is what is checked here;
            // that the Bengali is shaped at all is test_the_bengali_in_the_pdf_is_shaped_not_merely_drawn below.
            foreach (['PLCC-RCT-2026-000001', '৫০০', '২০২৬', 'APP-2026-0001', 'Provatferi Literary and Cultural Center (PLCC)'] as $expected) {
                $this->assertStringContainsString($expected, $text, "the Bangla PDF's text contains {$expected}");
            }
        }
    }

    public function test_the_bengali_in_the_pdf_is_shaped_not_merely_drawn(): void
    {
        $pdf = app(PdfRenderer::class)->render('<p>পরিশোধ</p><p>ক্ষ</p><p>Receipt PLCC-RCT-2026-000001 ৳৬০০</p>', 'shaping');

        // Without any external tool: পরিশোধ is six code points but seven glyphs shaped (ি moves before র, ো is two glyphs) and
        // ক্ষ is three code points but ONE glyph (the ligature). Typed order and unformed conjuncts give 6 and 3.
        $bengali = array_values(array_filter(PdfInspector::analyse($pdf)['runs'], fn (array $run) => str_contains($run['base'], 'Shaping')));
        $this->assertSame([7, 1], array_map(fn (array $run) => $run['glyphs'], array_slice($bengali, 0, 2)), 'পরিশোধ ক্ষ is not shaped');

        $text = $this->pdfText($pdf);
        if ($text === null) {
            $this->markTestSkipped('pdftotext is not installed');
        }

        // Unshaped, the text layer is the Unicode as typed — পরিশোধ. Shaped, the glyphs are in visual order (the vowel signs
        // move before their consonants) and the conjunct is a ligature glyph with only a private-use code point: neither the
        // typed word nor a plain ক্ষ can be there. For months the PDFs came out unshaped (পরশিোধ on paper) and every test
        // that read their text called that fine — this is the one that can tell.
        $this->assertStringNotContainsString('পরিশোধ', $text, 'the Bengali was typeset in typed order — the shaping engine is off');
        $this->assertMatchesRegularExpression('/[\x{E000}-\x{F8FF}]/u', $text, 'the conjunct ক্ষ was not formed into a ligature glyph');
        $this->assertStringContainsString('Receipt PLCC-RCT-2026-000001 ৳৬০০', $text, 'Latin text and digits come from the substitution font');
    }

    public function test_the_shaping_tables_are_prepared_once_by_a_throw_away_document(): void
    {
        $renderer = app(PdfRenderer::class);
        $renderer->render('<p>পরিশোধ</p>', 'warm');

        // The cache behaviour itself (stale entries, damaged files, a document that fails) is tests/Feature/Pdf/PdfRendererTest.
        $status = $renderer->cacheStatus();
        $this->assertTrue($status['ready'], 'the Bengali font cache is complete and verified: '.implode(', ', $status['missing']));
        $this->assertFileExists(dirname($status['directory']).'/.pdf-fonts-ready', 'the marker that says the probe document ran and its result was verified');
        $this->assertFileExists($status['directory'].'/'.PdfRenderer::FONT_BENGALI.'.GSUBdata.json', "the Bengali font's parsed shaping tables");
    }

    public function test_the_pdf_service_validates_the_language(): void
    {
        $this->assertSame('en', PaymentReceiptPdfService::language('en', 'bn'));
        $this->assertSame('bn', PaymentReceiptPdfService::language('de', 'bn'));
        $this->assertSame('en', PaymentReceiptPdfService::language(null, 'en'));
        $this->assertSame('bn', PaymentReceiptPdfService::language(['en'], 'xx'));
    }

    /* ================================================================ who may open it, and where it is offered */

    public function test_only_admins_who_may_see_payments_can_open_a_receipt(): void
    {
        $receipt = $this->registrationReceipt($this->application($this->type()));

        foreach (['show', 'pdf'] as $screen) {
            $url = route("admin.membership.receipts.{$screen}", $receipt);
            $this->flushSession();
            auth()->logout();
            $this->get($url)->assertRedirect(route('login'));
            $this->actingAs($this->userWith(['membership.view']))->get($url)->assertForbidden();
            $this->actingAs($this->userWith(['payments.view']))->get($url)->assertOk();
            $this->actingAs($this->admin)->get(route("admin.membership.receipts.{$screen}", 'PLCC-RCT-2026-999999'))->assertNotFound();
        }
    }

    public function test_receipt_links_appear_on_a_verified_payment_only_and_never_on_a_waiver(): void
    {
        $paid = $this->application($this->type('LM'));
        $receipt = $this->registrationReceipt($paid);
        $pending = $this->application($this->type('GM', '100', '0'));
        $this->actingAs($this->admin)->post(route('admin.membership.payments.store', $pending), ['amount_expected' => '100', 'amount_received' => '100', 'received_at' => '2026-10-08']);
        $waived = $this->application($this->type('ST', '0', '0'));
        $this->actingAs($this->admin)->post(route('admin.membership.payments.waive', $waived), ['waiver_reason' => 'অনারারি']);

        $page = $this->get(route('admin.membership.show', $paid))->assertOk();
        $page->assertSee('data-testid="receipt-links"', false)->assertSee('PLCC-RCT-2026-000001')
            ->assertSee(route('admin.membership.receipts.show', $receipt), false)->assertSee(route('admin.membership.receipts.pdf', $receipt), false)
            ->assertSee('View receipt')->assertSee('Download PDF');

        $this->get(route('admin.membership.show', $pending))->assertOk()->assertDontSee('receipt-links', false)->assertDontSee('View receipt');
        $this->get(route('admin.membership.show', $waived))->assertOk()->assertDontSee('receipt-links', false)->assertDontSee('View receipt');
    }

    public function test_the_member_page_offers_the_registration_and_the_monthly_receipts_but_not_for_awaiting_or_cancelled_entries(): void
    {
        $application = $this->application($this->type());
        $registration = $this->registrationReceipt($application);
        app(MembershipApprovalService::class)->approve($application->fresh(), $this->admin);
        $membership = Membership::query()->where('membership_application_id', $application->id)->sole();
        $due = $membership->dues()->firstOrFail();
        $ledger = app(MembershipDueLedger::class);
        $monthly = $this->monthlyReceipt($membership, '100', 'due', $due->id);
        $awaiting = $ledger->recordPayment($membership, $this->admin, ['purpose' => 'due', 'due_id' => $due->id, 'amount' => '50', 'received_at' => '2026-10-08', 'method' => 'cash', 'reference' => 'AWAITING']);
        $cancelled = $ledger->recordPayment($membership, $this->admin, ['purpose' => 'due', 'due_id' => $due->id, 'amount' => '25', 'received_at' => '2026-10-08', 'method' => 'cash', 'reference' => 'CANCELLED']);
        $ledger->cancelPayment($cancelled, $this->admin, 'ভুল');

        $page = $this->actingAs($this->admin)->get(route('admin.membership.members.show', $membership))->assertOk();
        $html = $page->getContent();
        $this->assertSame(2, substr_count($html, 'data-testid="receipt-links"'), 'the registration receipt and the verified monthly one');
        $page->assertSee($registration->receipt_no)->assertSee($monthly->receipt_no);
        $this->assertSame(2, PaymentReceipt::query()->count());
        $this->assertNull(PaymentReceipt::query()->where('payment_id', $awaiting->id)->first());
        $this->assertNull(PaymentReceipt::query()->where('payment_id', $cancelled->id)->first());
    }

    /* ================================================================ the member portal */

    private function portal(Membership $membership): array
    {
        $token = $membership->member->createToken('portal')->plainTextToken;
        $this->app['auth']->forgetGuards();

        return ['Authorization' => "Bearer {$token}"];
    }

    public function test_a_member_reads_and_downloads_their_own_receipts_and_nobody_elses(): void
    {
        $this->at('2026-01-10 04:00:00');
        $mine = $this->member($this->type('QD', '0', '200'));
        $theirs = $this->member($this->type('QE', '0', '200', 'অন্য ধরন', 'Other type'));
        $myReceipt = $this->monthlyReceipt($mine, '200', 'due', $mine->dues()->firstOrFail()->id);
        $theirReceipt = $this->monthlyReceipt($theirs, '200', 'due', $theirs->dues()->firstOrFail()->id);
        $headers = $this->portal($mine);

        // The dashboard lists only their own, and only what a member should see.
        $dashboard = $this->withHeaders($headers)->getJson('/api/v1/member/me')->assertOk();
        $dashboard->assertJsonCount(1, 'data.receipts')
            ->assertJsonPath('data.receipts.0.receipt_no', $myReceipt->receipt_no)
            ->assertJsonPath('data.receipts.0.purpose', 'monthly')
            ->assertJsonPath('data.receipts.0.amount', '200.00')
            ->assertJsonPath('data.receipts.0.credit', '0.00')
            ->assertJsonPath('data.receipts.0.payment_date', '2026-10-08') // the day the helper says the money was received
            ->assertJsonPath('data.receipts.0.periods', ['2026-01']);
        $this->assertStringNotContainsString($theirReceipt->receipt_no, $dashboard->getContent());
        $this->assertStringNotContainsString('Verifier Admin', $dashboard->getContent(), 'no verifier or receiver name is sent to the portal');
        $this->assertSame(['receipt_no', 'purpose', 'amount', 'credit', 'payment_date', 'periods'], array_keys($dashboard->json('data.receipts.0')));

        // Their own PDF opens; another member's receipt is exactly the same 404 as a number that does not exist.
        $own = $this->withHeaders($headers)->get("/api/v1/member/receipts/{$myReceipt->receipt_no}/pdf?lang=bn");
        $own->assertOk();
        $this->assertSame('application/pdf', $own->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF-', $own->getContent());
        $this->assertSame('attachment; filename="'.$myReceipt->receipt_no.'.pdf"', $own->headers->get('Content-Disposition'));
        $this->assertStringContainsString('no-store', (string) $own->headers->get('Cache-Control'));

        $other = $this->withHeaders($headers)->get("/api/v1/member/receipts/{$theirReceipt->receipt_no}/pdf");
        $missing = $this->withHeaders($headers)->get('/api/v1/member/receipts/PLCC-RCT-2026-999999/pdf');
        $this->assertSame([404, 404], [$other->getStatusCode(), $missing->getStatusCode()]);
        $this->assertSame(strlen($missing->getContent()), strlen($other->getContent()), 'the two refusals are indistinguishable');
        $this->assertStringNotContainsString('%PDF', $other->getContent());
    }

    public function test_the_portal_refuses_what_is_not_a_signed_in_member(): void
    {
        $this->at('2026-01-10 04:00:00');
        $mine = $this->member($this->type('QD', '0', '200'));
        $receipt = $this->monthlyReceipt($mine, '200', 'due', $mine->dues()->firstOrFail()->id);
        $url = "/api/v1/member/receipts/{$receipt->receipt_no}/pdf";

        $this->app['auth']->forgetGuards();
        $this->get($url, ['Accept' => 'application/json'])->assertUnauthorized();

        $adminToken = $this->admin->createToken('x')->plainTextToken;
        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', "Bearer {$adminToken}")->get($url, ['Accept' => 'application/json'])->assertForbidden();

        $headers = $this->portal($mine);
        $mine->member->forceFill(['status' => 'suspended'])->save();
        $this->withHeaders($headers)->get($url, ['Accept' => 'application/json'])->assertForbidden();
    }

    public function test_a_members_registration_receipt_reaches_them_once_approved_and_not_before(): void
    {
        $application = $this->application($this->type());
        $receipt = $this->registrationReceipt($application);
        $service = app(\App\Services\PaymentReceiptService::class);

        // Before approval there is no member at all: nobody can reach it through the portal.
        $this->assertSame(0, Member::query()->count());

        app(MembershipApprovalService::class)->approve($application->fresh(), $this->admin);
        $membership = Membership::query()->where('membership_application_id', $application->id)->sole();
        $this->assertSame([$receipt->receipt_no], $service->forMember($membership->member)->pluck('receipt_no')->all());

        $headers = $this->portal($membership);
        $this->withHeaders($headers)->getJson('/api/v1/member/me')->assertOk()
            ->assertJsonPath('data.receipts.0.receipt_no', $receipt->receipt_no)->assertJsonPath('data.receipts.0.purpose', 'registration');
        $this->withHeaders($headers)->get("/api/v1/member/receipts/{$receipt->receipt_no}/pdf?lang=en")->assertOk();
    }
}
