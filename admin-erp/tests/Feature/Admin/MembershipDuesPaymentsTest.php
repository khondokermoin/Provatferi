<?php

namespace Tests\Feature\Admin;

use App\Models\ApprovalHistory;
use App\Models\Membership;
use App\Models\MembershipApplication;
use App\Models\MembershipDue;
use App\Models\MembershipDueAllocation;
use App\Models\MembershipType;
use App\Models\Payment;
use App\Models\User;
use App\Services\MembershipApprovalService;
use App\Services\MembershipDueLedger;
use Illuminate\Support\Carbon;
use Tests\Concerns\MakesMembershipTypes;

/**
 * Membership task 4 (2026-10-08): money against the monthly dues — record, verify, partial, advance credit, waiver,
 * cancel — and where it shows: the member page (Bangla and English), the registry's filters, the member portal.
 *
 * Money counts only once VERIFIED; a month never takes more than it owes; anything left over is advance credit, applied
 * to the oldest month owed first; a waiver is never a payment.
 */
class MembershipDuesPaymentsTest extends AdminTestCase
{
    use MakesMembershipTypes;

    private User $admin;

    private MembershipType $lifetime;

    protected function setUp(): void
    {
        parent::setUp();
        $this->at('2026-01-10 04:00:00'); // 10 January 2026, 10:00 in Dhaka
        $this->admin = $this->superAdmin();
        $this->lifetime = $this->type('LM', '200');
    }

    private function at(string $utc): void
    {
        $this->travelTo(Carbon::parse($utc, 'UTC'));
    }

    private function type(string $code, string $monthly): MembershipType
    {
        return $this->makeMembershipType(
            ['name' => "ধরন {$code}", 'code' => $code, 'slug' => 'pay-'.uniqid(), 'is_public_self_apply' => true, 'is_public_visible' => true],
            ['registration' => '0', 'monthly' => $monthly],
        );
    }

    private function member(?MembershipType $type = null): Membership
    {
        $application = MembershipApplication::query()->create([
            'applicant_name' => 'সদস্য '.uniqid(), 'applicant_email' => 'pay-'.uniqid().'@example.com',
            'applicant_phone' => '018'.random_int(10000000, 99999999), 'membership_type_id' => ($type ?? $this->lifetime)->id, 'status' => 'under_review',
        ]);
        app(MembershipApprovalService::class)->approve($application, $this->admin);

        return Membership::query()->where('membership_application_id', $application->id)->sole();
    }

    private function due(Membership $membership, int $month = 1): MembershipDue
    {
        return $membership->dues()->where('period_month', $month)->firstOrFail();
    }

    /** @param array<string, mixed> $extra */
    private function record(Membership $membership, string $amount, ?MembershipDue $due, array $extra = [], ?User $as = null)
    {
        return $this->actingAs($as ?? $this->admin)->post(route('admin.membership.members.dues.payments.store', $membership), $extra + [
            'purpose' => $due ? 'due' : 'advance', 'due_id' => $due?->id, 'amount' => $amount,
            'received_at' => '2026-01-10', 'method' => 'cash', 'reference' => 'RCPT-'.uniqid(),
        ]);
    }

    private function verify(Membership $membership, Payment $payment, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->admin)->patch(route('admin.membership.members.dues.payments.verify', [$membership, $payment]));
    }

    private function lastPayment(Membership $membership): Payment
    {
        return $membership->payments()->latest('id')->firstOrFail();
    }

    private function credit(Membership $membership): string
    {
        return app(MembershipDueLedger::class)->summary($membership->fresh())['credit'];
    }

    /* ================================================================ verification is what counts */

    public function test_an_unverified_payment_changes_nothing_and_verification_pays_the_month_once(): void
    {
        $membership = $this->member();
        $this->record($membership, '200', $this->due($membership))->assertSessionHas('success');
        $payment = $this->lastPayment($membership);
        $this->assertSame([Payment::CATEGORY_MONTHLY, null], [$payment->category, $payment->verified_at]);
        $this->assertSame(['due', '0.00', '200.00'], [$this->due($membership)->status, $this->due($membership)->paid_amount, $this->due($membership)->outstanding()],
            'recorded cash that is not verified pays nothing');

        $this->verify($membership, $payment)->assertSessionHas('success');
        $this->verify($membership, $payment)->assertSessionHas('status'); // a repeat: already verified, nothing changes
        $due = $this->due($membership);
        $this->assertSame(['paid', '200.00', '0.00'], [$due->status, $due->paid_amount, $due->outstanding()]);
        $this->assertNotNull($due->paid_at);
        $this->assertSame(1, MembershipDueAllocation::query()->count());
        $this->assertSame(1, ApprovalHistory::query()->where('action', 'monthly_payment_verified')->count());
        $this->assertSame(1, ApprovalHistory::query()->where('action', 'monthly_payment_recorded')->count());
    }

    public function test_partial_payments_add_up_and_never_go_past_the_month(): void
    {
        $membership = $this->member();
        $this->record($membership, '100', $this->due($membership));
        $this->verify($membership, $this->lastPayment($membership));
        $this->assertSame(['partially_paid', '100.00', '100.00'], [$this->due($membership)->status, $this->due($membership)->paid_amount, $this->due($membership)->outstanding()]);

        $this->record($membership, '100', $this->due($membership));
        $this->verify($membership, $this->lastPayment($membership));
        $this->assertSame(['paid', '200.00', '0.00'], [$this->due($membership)->status, $this->due($membership)->paid_amount, $this->due($membership)->outstanding()]);

        $this->record($membership, '50', $this->due($membership))->assertSessionHasErrors('due_id'); // settled: record an advance instead
    }

    public function test_more_than_a_month_owes_needs_an_explicit_choice_and_the_rest_becomes_credit(): void
    {
        $membership = $this->member();
        $this->record($membership, '300', $this->due($membership))->assertSessionHasErrors('amount');
        $this->assertSame(0, Payment::query()->count());

        $this->record($membership, '300', $this->due($membership), ['keep_rest_as_credit' => '1'])->assertSessionHas('success');
        $this->verify($membership, $this->lastPayment($membership));
        $this->assertSame(['paid', '200.00'], [$this->due($membership)->status, $this->due($membership)->paid_amount], 'the month takes only what it owes');
        $this->assertSame('100.00', $this->credit($membership), 'the rest is kept, never lost');

        $this->at('2026-02-02 04:00:00');
        app(MembershipDueLedger::class)->generateFor($membership);
        $february = $this->due($membership, 2);
        $this->assertSame(['partially_paid', '100.00', '100.00'], [$february->status, $february->paid_amount, $february->outstanding()], 'credit pays the new month as it is created');
        $this->assertSame('0.00', $this->credit($membership));
        $this->assertSame(1, MembershipDueAllocation::query()->where('kind', 'credit')->count());
    }

    public function test_an_advance_pays_future_months_as_their_dues_are_created(): void
    {
        $membership = $this->member();
        $this->record($membership, '200', $this->due($membership));
        $this->verify($membership, $this->lastPayment($membership));

        $this->record($membership, '400', null)->assertSessionHas('success'); // an advance: no month yet
        $this->verify($membership, $this->lastPayment($membership));
        $this->assertSame('400.00', $this->credit($membership));

        $this->at('2026-03-05 04:00:00');
        app(MembershipDueLedger::class)->generateFor($membership);
        $this->assertSame(['paid', 'paid'], [$this->due($membership, 2)->status, $this->due($membership, 3)->status]);
        $this->assertSame('0.00', $this->credit($membership));

        $this->at('2026-04-05 04:00:00');
        app(MembershipDueLedger::class)->generateFor($membership);
        $this->assertSame('due', $this->due($membership, 4)->status, 'no fake future dues were created in advance, and the credit is spent');
    }

    public function test_credit_goes_to_the_oldest_month_owed_first(): void
    {
        $membership = $this->member();
        $this->at('2026-02-05 04:00:00');
        app(MembershipDueLedger::class)->generateFor($membership); // January (now overdue) and February owed

        $this->record($membership, '300', null, ['received_at' => '2026-02-05']);
        $this->verify($membership, $this->lastPayment($membership));
        $this->assertSame(['paid', '200.00'], [$this->due($membership, 1)->status, $this->due($membership, 1)->paid_amount]);
        $this->assertSame(['partially_paid', '100.00'], [$this->due($membership, 2)->status, $this->due($membership, 2)->paid_amount]);
    }

    /* ================================================================ waivers and cancellations */

    public function test_a_waiver_is_not_a_payment_needs_a_reason_and_is_audited(): void
    {
        $membership = $this->member();
        $due = $this->due($membership);
        $route = route('admin.membership.members.dues.waive', [$membership, $due]);

        $this->actingAs($this->admin)->post($route, ['waive_amount' => '50'])->assertSessionHasErrors('waiver_reason');
        $this->actingAs($this->admin)->post($route, ['waive_amount' => '250', 'waiver_reason' => 'অতিরিক্ত'])->assertSessionHasErrors('waive_amount');
        $this->actingAs($this->admin)->post($route, ['waive_amount' => '50', 'waiver_reason' => 'আংশিক বিবেচনা'])->assertSessionHas('success');
        $this->assertSame(['due', '50.00', '150.00'], [$this->due($membership)->status, $this->due($membership)->waived_amount, $this->due($membership)->outstanding()]);

        $this->actingAs($this->admin)->post($route, ['waiver_reason' => 'অসুস্থতা'])->assertSessionHas('success'); // the rest
        $due = $this->due($membership);
        $this->assertSame(['waived', '200.00', '0.00', '0.00'], [$due->status, $due->waived_amount, $due->paid_amount, $due->outstanding()]);
        $this->assertSame(0, Payment::query()->count(), 'a waiver never creates a payment');
        $waivers = ApprovalHistory::query()->where('subject_type', MembershipDue::class)->where('subject_id', $due->id)->where('action', 'waived')->get();
        $this->assertCount(2, $waivers);
        $this->assertSame($this->admin->id, $waivers->first()->actor_id);
        $this->assertStringContainsString('আংশিক বিবেচনা', (string) $waivers->first()->note);

        $this->actingAs($this->admin)->post($route, ['waiver_reason' => 'আবার'])->assertSessionHas('status'); // nothing owed any more
    }

    public function test_a_payment_after_a_partial_waiver_settles_the_rest(): void
    {
        $membership = $this->member();
        $due = $this->due($membership);
        $this->actingAs($this->admin)->post(route('admin.membership.members.dues.waive', [$membership, $due]), ['waive_amount' => '50', 'waiver_reason' => 'ছাড়']);
        $this->record($membership, '150', $due);
        $this->verify($membership, $this->lastPayment($membership));

        $this->assertSame(['paid', '150.00', '50.00', '0.00'], [$this->due($membership)->status, $this->due($membership)->paid_amount, $this->due($membership)->waived_amount, $this->due($membership)->outstanding()]);
    }

    public function test_an_unverified_entry_can_be_cancelled_and_never_counts(): void
    {
        $membership = $this->member();
        $this->record($membership, '200', $this->due($membership));
        $payment = $this->lastPayment($membership);
        $route = route('admin.membership.members.dues.payments.cancel', [$membership, $payment]);

        $this->actingAs($this->admin)->patch($route, [])->assertSessionHasErrors('cancellation_reason');
        $this->actingAs($this->admin)->patch($route, ['cancellation_reason' => 'ভুল এন্ট্রি'])->assertSessionHas('success');
        $this->assertSame('cancelled', $payment->fresh()->status);
        $this->verify($membership, $payment)->assertSessionHas('error');
        $this->assertSame(['due', '0.00'], [$this->due($membership)->status, $this->due($membership)->paid_amount]);
        $this->assertNotNull(Payment::query()->find($payment->id), 'cancelled, never deleted');
    }

    public function test_a_voluntary_contribution_is_never_applied_to_dues(): void
    {
        $membership = $this->member();
        $this->record($membership, '500', null, ['purpose' => 'voluntary']);
        $payment = $this->lastPayment($membership);
        $this->verify($membership, $payment);

        $this->assertSame([Payment::CATEGORY_VOLUNTARY, true], [$payment->fresh()->category, $payment->fresh()->verified_at !== null]);
        $this->assertSame(['due', '0.00'], [$this->due($membership)->status, $this->due($membership)->paid_amount]);
        $this->assertSame('0.00', $this->credit($membership));
    }

    public function test_recording_and_verifying_are_separate_permissions(): void
    {
        $membership = $this->member();
        $cashier = $this->userWith(['membership.view', 'payments.create']);
        $this->record($membership, '200', $this->due($membership), [], $cashier)->assertSessionHas('success');
        $payment = $this->lastPayment($membership);

        $this->verify($membership, $payment, $cashier)->assertForbidden();
        $this->actingAs($cashier)->post(route('admin.membership.members.dues.waive', [$membership, $this->due($membership)]), ['waiver_reason' => 'x'])->assertForbidden();
        $this->assertSame('due', $this->due($membership)->status);
    }

    /* ================================================================ where it shows */

    public function test_the_member_page_shows_the_ledger_in_bangla_and_in_english(): void
    {
        $membership = $this->member();
        $this->record($membership, '100', $this->due($membership));
        $this->verify($membership, $this->lastPayment($membership));
        $page = fn () => $this->actingAs($this->admin)->get(route('admin.membership.members.show', $membership))->assertOk();

        $page()->assertSee('মাসিক চাঁদা')->assertSee('জানুয়ারি ২০২৬')->assertSee('৳১০০')->assertSee('আংশিক পরিশোধিত')
            ->assertSee('data-period="2026-01" data-state="partially_paid"', false)->assertDontSee('Monthly contributions');

        $this->admin->forceFill(['ui_locale' => 'en'])->save();
        $page()->assertSee('Monthly contributions')->assertSee('January 2026')->assertSee('৳100')->assertSee('Partially paid')
            ->assertDontSee('মাসিক চাঁদা')->assertDontSee('আংশিক পরিশোধিত');
    }

    public function test_the_registry_filters_by_where_each_member_stands(): void
    {
        $none = $this->member($this->type('GM', '0'));
        $current = $this->member();
        $due = $this->member();
        $partial = $this->member();
        $overdue = $this->member();
        $this->at('2026-02-03 04:00:00');
        app(MembershipDueLedger::class)->generateAll();
        foreach ([[$current, '200', 1], [$current, '200', 2], [$due, '200', 1], [$partial, '200', 1], [$partial, '50', 2]] as [$m, $amount, $month]) {
            $this->record($m, $amount, $this->due($m, $month), ['received_at' => '2026-02-03']);
            $this->verify($m, $this->lastPayment($m));
        }
        // $overdue paid nothing: January is overdue. $due paid January, February is still owed.

        $codes = function (string $standing): array {
            $html = (string) $this->actingAs($this->admin)->get(route('admin.membership.members.index', ['monthly' => $standing]))->assertOk()->getContent();
            preg_match_all('/data-member-code="([^"]+)"/', $html, $found);

            return $found[1];
        };

        $this->assertSame([$none->member_code], $codes('not_required'));
        $this->assertSame([$current->member_code], $codes('current'));
        $this->assertSame([$due->member_code], $codes('due'));
        $this->assertSame([$partial->member_code], $codes('partially_paid'));
        $this->assertSame([$overdue->member_code], $codes('overdue'));

        $this->actingAs($this->admin)->get(route('admin.membership.members.index'))->assertOk()
            ->assertSee('data-standing="overdue"', false)->assertSee('data-standing="not_required"', false);
    }

    public function test_the_member_portal_shows_the_monthly_contribution_without_internal_details(): void
    {
        $membership = $this->member();
        $this->at('2026-02-03 04:00:00');
        app(MembershipDueLedger::class)->generateFor($membership);
        $this->record($membership, '100', $this->due($membership, 1), ['received_at' => '2026-02-03', 'reference' => 'SECRET-REF']);
        $this->verify($membership, $this->lastPayment($membership));

        $token = $membership->member->createToken('portal')->plainTextToken;
        $this->app['auth']->forgetGuards();
        $response = $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/member/me')->assertOk()
            ->assertJsonPath('data.memberships.0.monthly.current_period', '2026-02')
            ->assertJsonPath('data.memberships.0.monthly.current_amount', '200.00')
            ->assertJsonPath('data.memberships.0.monthly.required', true)
            ->assertJsonPath('data.memberships.0.monthly.month_state', 'due')
            ->assertJsonPath('data.memberships.0.monthly.outstanding', '300.00')
            ->assertJsonPath('data.memberships.0.monthly.overdue_count', 1)
            ->assertJsonPath('data.memberships.0.monthly.recent.0.period', '2026-02')
            ->assertJsonPath('data.memberships.0.monthly.recent.1.state', 'overdue')
            ->assertJsonPath('data.memberships.0.monthly.recent.1.paid', '100.00');
        $this->assertStringNotContainsString('SECRET-REF', $response->getContent());
        $this->assertStringNotContainsString($this->admin->name, $response->getContent());

        $general = $this->member($this->type('GM', '0'));
        $token = $general->member->createToken('portal')->plainTextToken;
        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/member/me')->assertOk()
            ->assertJsonPath('data.memberships.0.monthly.required', false)
            ->assertJsonPath('data.memberships.0.monthly.month_state', 'not_required')
            ->assertJsonPath('data.memberships.0.monthly.recent', []);
    }
}
