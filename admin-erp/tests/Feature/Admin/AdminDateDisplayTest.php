<?php

namespace Tests\Feature\Admin;

use App\Models\Activity;
use App\Models\ActivityType;
use App\Models\Committee;
use App\Models\CommitteeRegistrationLink;
use App\Models\JobApplication;
use App\Models\JobPosting;
use App\Models\Membership;
use App\Models\MembershipApplication;
use App\Models\MembershipSeason;
use App\Models\MembershipType;
use App\Models\OrganizationalUnit;
use App\Models\Payment;
use App\Models\User;
use App\Services\MembershipApprovalService;
use App\Services\MembershipDueLedger;
use Illuminate\Support\Carbon;
use Tests\Concerns\MakesMembershipTypes;

/**
 * The admin screens show every TIMESTAMP on the Dhaka clock and every calendar DATE as stored (2026-10-08,
 * docs/DATES_AND_TIMES.md). The clock starts at 2026-10-07 18:30 UTC — already 8 October, 00:30 in Dhaka: the moment
 * the old screens printed as 7 October.
 */
class AdminDateDisplayTest extends AdminTestCase
{
    use MakesMembershipTypes;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->at('2026-10-07 18:30:00');
        $this->admin = $this->superAdmin();
        $this->admin->forceFill(['ui_locale' => 'bn'])->save();
    }

    private function at(string $utc): void
    {
        $this->travelTo(Carbon::parse($utc, 'UTC'));
    }

    private function english(): void
    {
        $this->admin->forceFill(['ui_locale' => 'en'])->save();
    }

    private function lifetime(): MembershipType
    {
        return $this->makeMembershipType(['name' => 'আজীবন সদস্য', 'code' => 'LM', 'slug' => 'life-'.uniqid()], ['registration' => '500', 'monthly' => '200']);
    }

    private function application(MembershipType $type): MembershipApplication
    {
        return MembershipApplication::query()->create([
            'applicant_name' => 'রহিম উদ্দিন', 'applicant_email' => 'rahim-'.uniqid().'@example.com', 'applicant_phone' => '017'.random_int(10000000, 99999999),
            'membership_type_id' => $type->id, 'status' => 'under_review',
        ]);
    }

    public function test_the_application_and_its_registration_payment_read_on_the_dhaka_clock(): void
    {
        $application = $this->application($this->lifetime());

        $page = $this->actingAs($this->admin)->get(route('admin.membership.show', $application))->assertOk();
        $page->assertSee('৮ অক্টোবর ২০২৬, ০০:৩০', false)->assertDontSee('৭ অক্টোবর ২০২৬', false);
        // The payment form proposes the organisation's today — the UTC date is still the 7th.
        $page->assertSee('name="received_at"', false)->assertSee('value="2026-10-08"', false);

        $this->post(route('admin.membership.payments.store', $application), ['amount_expected' => '500', 'amount_received' => '500', 'received_at' => '2026-10-08'])->assertRedirect();
        $this->at('2026-10-07 18:45:00');
        $this->patch(route('admin.membership.payments.verify', Payment::query()->sole()))->assertRedirect();

        $page = $this->get(route('admin.membership.show', $application))->assertOk();
        $page->assertSee('data-testid="payment-received-at">৮ অক্টোবর ২০২৬</span>', false);
        $page->assertSee('data-testid="payment-verified-at">৮ অক্টোবর ২০২৬, ০০:৪৫</span>', false);
        $this->assertSame('2026-10-07 18:45:00', Payment::query()->sole()->verified_at->format('Y-m-d H:i:s'), 'stored UTC — unchanged');

        $this->english();
        $this->get(route('admin.membership.show', $application))->assertOk()
            ->assertSee('8 October 2026, 00:30', false)
            ->assertSee('data-testid="payment-verified-at">8 October 2026, 00:45</span>', false);
    }

    public function test_the_member_page_history_and_monthly_ledger_read_on_the_dhaka_clock(): void
    {
        $application = $this->application($this->lifetime());
        $application->forceFill(['status' => 'under_review'])->save();
        Payment::query()->create([
            'payable_type' => MembershipApplication::class, 'payable_id' => $application->id, 'category' => 'registration', 'membership_type_id' => $application->membership_type_id,
            'amount_expected' => '500', 'amount_received' => '500', 'method' => 'cash', 'received_at' => '2026-10-08', 'status' => 'paid', 'verified_at' => now(), 'verified_by' => $this->admin->id,
        ]);
        $this->at('2026-10-07 19:00:00'); // 01:00 on 8 October in Dhaka
        app(MembershipApprovalService::class)->approve($application, $this->admin);
        $membership = Membership::query()->sole();

        $this->at('2026-10-07 19:30:00');
        $ledger = app(MembershipDueLedger::class);
        $payment = $ledger->recordPayment($membership, $this->admin, ['purpose' => 'due', 'due_id' => $membership->dues()->sole()->id, 'amount' => '200', 'received_at' => '2026-10-08', 'method' => 'cash']);
        $ledger->verifyPayment($payment, $this->admin);

        $page = $this->actingAs($this->admin)->get(route('admin.membership.members.show', $membership))->assertOk();
        $page->assertSee('data-testid="member-approved-at">৮ অক্টোবর ২০২৬, ০১:০০</span>', false);
        $page->assertSee('৮ অক্টোবর ২০২৬', false); // joining date: a calendar DATE (the organisation's today at approval)
        $this->assertSame('2026-10-08', $membership->start_date->toDateString());
        // The history: the machine-readable instant stays UTC, the text is Dhaka time.
        $page->assertSee('<time datetime="2026-10-07T19:00:00+00:00">৮ অক্টোবর ২০২৬, ০১:০০</time>', false);
        $page->assertSee('<time datetime="2026-10-07T19:30:00+00:00">৮ অক্টোবর ২০২৬, ০১:৩০</time>', false);
        $page->assertSee('· ৮ অক্টোবর ২০২৬, ০১:৩০</span>', false); // the monthly payment's verification
        $page->assertDontSee('৭ অক্টোবর ২০২৬', false);

        $this->english();
        $this->get(route('admin.membership.members.show', $membership))->assertOk()
            ->assertSee('data-testid="member-approved-at">8 October 2026, 01:00</span>', false)
            ->assertSee('<time datetime="2026-10-07T19:00:00+00:00">8 October 2026, 01:00</time>', false);
    }

    public function test_the_application_list_and_its_date_filter_use_the_dhaka_day(): void
    {
        $application = $this->application($this->lifetime());

        $list = $this->actingAs($this->admin)->get(route('admin.membership.index'))->assertOk();
        $list->assertSee('৮ অক্টোবর ২০২৬', false)->assertDontSee('৭ অক্টোবর ২০২৬', false);

        $this->get(route('admin.membership.index', ['date' => '2026-10-08']))->assertOk()->assertSee($application->application_no);
        $this->get(route('admin.membership.index', ['date' => '2026-10-07']))->assertOk()->assertDontSee($application->application_no);
        $this->get(route('admin.membership.index', ['date' => 'not-a-date']))->assertOk()->assertSee($application->application_no);
    }

    public function test_recruitment_applications_list_filter_detail_and_document_use_the_dhaka_clock(): void
    {
        $posting = JobPosting::query()->create([
            'title' => 'স্বেচ্ছাসেবী আহ্বান', 'slug' => 'volunteer-'.uniqid(), 'description' => 'বিবরণ', 'employment_type' => 'volunteer',
            'application_mode' => 'rolling', 'accepts_applications' => true, 'status' => 'open',
        ]);
        $application = JobApplication::query()->create([
            'application_no' => JobApplication::generateApplicationNo(), 'job_posting_id' => $posting->id, 'applicant_name' => 'নাদিয়া', 'applicant_email' => 'nadia@example.test',
            'applicant_phone' => '+8801711223344', 'district' => 'কুমিল্লা', 'accuracy_declaration' => true, 'privacy_consent' => true, 'contact_consent' => true,
            'status' => 'submitted', 'submitted_at' => now(),
        ]);

        $this->actingAs($this->admin)->get(route('admin.recruitment.applications.index'))->assertOk()
            ->assertSee('৮ অক্টোবর ২০২৬', false)->assertDontSee('৭ অক্টোবর ২০২৬', false);
        $this->get(route('admin.recruitment.applications.index', ['from' => '2026-10-08', 'to' => '2026-10-08']))->assertSee($application->application_no);
        $this->get(route('admin.recruitment.applications.index', ['from' => '2026-10-07', 'to' => '2026-10-07']))->assertDontSee($application->application_no);
        $this->get(route('admin.recruitment.applications.show', $application))->assertOk()
            ->assertSee('data-testid="applied-at">৮ অক্টোবর ২০২৬, ০০:৩০</dd>', false);

        $this->at('2026-10-07 20:00:00');
        $this->get(route('admin.recruitment.applications.print', [$application, 'doclang' => 'en']))->assertOk()
            ->assertSee('8 October 2026, 00:30', false)   // applied
            ->assertSee('8 October 2026, 02:00', false);  // generated
    }

    public function test_a_season_window_is_typed_and_read_in_bangladesh_time_and_stored_in_utc(): void
    {
        $this->actingAs($this->admin)->post(route('admin.membership.seasons.store'), [
            'name' => 'নভেম্বর সিজন', 'campaign_type' => 'regular', 'status' => 'open', 'display_order' => 0,
            'opens_at' => '2026-11-01T00:00', 'closes_at' => '2026-11-30T23:59',
        ])->assertRedirect();
        $season = MembershipSeason::query()->sole();
        $this->assertSame('2026-10-31 18:00:00', $season->opens_at->format('Y-m-d H:i:s'), 'midnight in Dhaka is 18:00 UTC the day before');
        $this->assertSame('2026-11-30 17:59:00', $season->closes_at->format('Y-m-d H:i:s'));

        $this->at('2026-10-31 17:59:00');
        $this->assertFalse($season->fresh()->acceptsApplicationsNow(), '23:59 on 31 October in Dhaka: not yet');
        $this->at('2026-10-31 18:00:00');
        $this->assertTrue($season->fresh()->acceptsApplicationsNow(), '00:00 on 1 November in Dhaka: open');

        $this->get(route('admin.membership.seasons.edit', $season))->assertOk()
            ->assertSee('value="2026-11-01T00:00"', false)->assertSee('value="2026-11-30T23:59"', false);
        $this->get(route('admin.membership.seasons.index'))->assertOk()
            ->assertSee('১ নভেম্বর ২০২৬, ০০:০০ – ৩০ নভেম্বর ২০২৬, ২৩:৫৯', false);

        // Saving the form as shown changes nothing: no six-hour drift on every save.
        $this->put(route('admin.membership.seasons.update', $season), [
            'name' => 'নভেম্বর সিজন', 'campaign_type' => 'regular', 'status' => 'open', 'display_order' => 0,
            'opens_at' => '2026-11-01T00:00', 'closes_at' => '2026-11-30T23:59',
        ])->assertRedirect();
        $this->assertSame('2026-10-31 18:00:00', $season->fresh()->opens_at->format('Y-m-d H:i:s'));
    }

    public function test_a_registration_link_expiry_is_typed_in_bangladesh_time(): void
    {
        $unit = OrganizationalUnit::query()->create(['name' => 'কেন্দ্রীয়', 'slug' => 'central-'.uniqid(), 'unit_type' => 'central', 'status' => 'active']);
        $committee = Committee::query()->create(['organization_unit_id' => $unit->id, 'name' => 'কমিটি', 'status' => 'active']);

        $this->actingAs($this->admin)->post(route('admin.committees.registration-links.store', $committee), ['expires_at' => '2026-10-10T18:00'])->assertRedirect();
        $this->assertSame('2026-10-10 12:00:00', CommitteeRegistrationLink::query()->sole()->expires_at->format('Y-m-d H:i:s'));
        $this->get(route('admin.committees.show', $committee))->assertOk()->assertSee('১০ অক্টোবর ২০২৬, ১৮:০০', false);

        // 00:10 on 8 October in Dhaka has already passed (it is 00:30) — even though as a UTC time it would be future.
        $this->post(route('admin.committees.registration-links.store', $committee), ['expires_at' => '2026-10-08T00:10'])
            ->assertSessionHasErrors('expires_at');
        $this->assertSame(1, CommitteeRegistrationLink::query()->count());
    }

    public function test_an_activity_time_is_wall_clock_and_its_publication_is_a_timestamp(): void
    {
        $type = ActivityType::query()->create(['name' => 'পাঠচক্র', 'slug' => 'reading-'.uniqid(), 'status' => 'active', 'sort_order' => 1]);
        $activity = Activity::query()->create([
            'activity_type_id' => $type->id, 'title' => 'পাঠচক্র', 'slug' => 'reading-'.uniqid(), 'status' => 'published',
            'start_datetime' => '2026-10-10 18:00:00', 'published_at' => now(),
        ]);

        $this->actingAs($this->admin)->get(route('admin.activities.show', $activity))->assertOk()
            ->assertSee('১০ অক্টোবর ২০২৬, ১৮:০০', false)      // as typed: 6 pm in Dhaka
            ->assertDontSee('১১ অক্টোবর ২০২৬, ০০:০০', false)   // never converted
            ->assertSee('৮ অক্টোবর ২০২৬, ০০:৩০', false);       // published_at: a UTC instant
    }

    public function test_a_users_last_sign_in_and_the_footer_year_are_on_the_dhaka_clock(): void
    {
        $user = User::factory()->create(['last_login_at' => now(), 'status' => 'active']);
        $this->actingAs($this->admin)->get(route('admin.users.show', $user))->assertOk()
            ->assertSee('৮ অক্টোবর ২০২৬, ০০:৩০', false)->assertDontSee('৭ অক্টোবর ২০২৬', false);

        $this->at('2026-12-31 18:30:00'); // 00:30 on 1 January 2027 in Dhaka
        $this->get(route('admin.users.show', $user))->assertOk()->assertSee('&copy; ২০২৭', false);
    }
}
