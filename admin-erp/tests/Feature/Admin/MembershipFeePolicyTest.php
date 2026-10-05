<?php

namespace Tests\Feature\Admin;

use App\Models\MembershipApplication;
use App\Models\MembershipFeePolicy;
use App\Models\MembershipType;
use App\Models\Payment;
use App\Models\User;
use App\Services\MembershipFeePolicyService;
use App\Services\MembershipInitialPolicyLoader;
use App\Support\Money;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\MakesMembershipTypes;

/**
 * Membership Registry, task 1 (2026-10-05): membership types + effective-dated fee policies.
 *
 * The owner's cases, by letter:
 *   A Lifetime 500/200   B General 100/0   C Student 0/0 (the owner-approved schedule, as the one-time loader writes it)
 *   D a future Student fee: today still 0, the future date returns the new amount
 *   E no overlap     F an old application stays tied to its old policy after a fee change
 *   G zero fees accepted   H negative fees rejected   I a disabled type behaves exactly as before
 * and then the rules those cases rest on (contiguous timeline, cancelling, the organisation's calendar day, the
 * database-level guard), the loader's safety, the admin screens and the public contract.
 *
 * Time is frozen with Carbon::setTestNow; the clock below is 10:00 UTC on 2026-10-05 = 16:00 in Dhaka.
 */
class MembershipFeePolicyTest extends AdminTestCase
{
    use MakesMembershipTypes;

    private const NOW = '2026-10-05 10:00:00';

    private MembershipFeePolicyService $fees;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(self::NOW);
        $this->fees = app(MembershipFeePolicyService::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** The four types production holds, in the state the seeder left them (no codes, English names, policies or order). */
    private function seedProductionLikeTypes(): void
    {
        foreach ([
            ['slug' => 'general', 'name' => 'সাধারণ সদস্য'],
            ['slug' => 'student', 'name' => 'শিক্ষার্থী সদস্য', 'is_student' => true],
            ['slug' => 'life', 'name' => 'আজীবন সদস্য'],
            ['slug' => 'honorary', 'name' => 'সম্মানসূচক সদস্য'],
        ] as $attributes) {
            MembershipType::query()->create($attributes + ['status' => 'active', 'sort_order' => 0]);
        }
    }

    private function loaded(): void
    {
        $this->seedProductionLikeTypes();
        $report = app(MembershipInitialPolicyLoader::class)->apply();
        $this->assertTrue($report['ok'], implode(' | ', $report['problems']));
    }

    /** A super admin reading the panel in the given language (the panel's default is Bangla, so English assertions must ask for English). */
    private function admin(string $locale = 'en'): User
    {
        $admin = $this->superAdmin();
        $admin->update(['ui_locale' => $locale]);

        return $admin;
    }

    private function byCode(string $code): MembershipType
    {
        return MembershipType::query()->where('code', $code)->firstOrFail();
    }

    /** A policy through the service, the way the admin screen creates one. */
    private function newPolicy(MembershipType $type, string $registration, string $monthly, string $from, string $note = 'test'): MembershipFeePolicy
    {
        return $this->fees->create($type, ['registration_fee' => $registration, 'monthly_contribution' => $monthly, 'effective_from' => $from, 'note' => $note]);
    }

    private function bareType(string $code = 'ST'): MembershipType
    {
        return $this->makeMembershipType(['name' => 'শিক্ষার্থী সদস্য', 'slug' => 'student-'.uniqid(), 'code' => $code, 'is_student' => true], null);
    }

    private function applyPublicly(MembershipType $type, string $email = 'applicant@example.com'): MembershipApplication
    {
        $response = $this->postJson('/api/v1/public/membership/applications', [
            'applicant_name' => 'রহিমা খাতুন', 'applicant_email' => $email, 'applicant_phone' => '01712345678',
            'membership_type_id' => $type->id,
        ]);
        $response->assertCreated();

        return MembershipApplication::query()->where('application_no', $response->json('data.application_no'))->firstOrFail();
    }

    /* ======================================================================================== A, B, C */

    public function test_a_lifetime_member_pays_500_registration_and_200_monthly(): void
    {
        $this->loaded();

        $policy = $this->fees->effectiveFor($this->byCode('LM'));

        $this->assertSame('500.00', $policy->registration_fee);
        $this->assertSame('200.00', $policy->monthly_contribution);
    }

    public function test_a_general_member_pays_100_registration_and_0_monthly(): void
    {
        $this->loaded();

        $policy = $this->fees->effectiveFor($this->byCode('GM'));

        $this->assertSame('100.00', $policy->registration_fee);
        $this->assertSame('0.00', $policy->monthly_contribution);
    }

    public function test_a_student_member_currently_pays_nothing(): void
    {
        $this->loaded();

        $policy = $this->fees->effectiveFor($this->byCode('ST'));

        $this->assertSame('0.00', $policy->registration_fee);
        $this->assertSame('0.00', $policy->monthly_contribution);
    }

    /* ======================================================================================== D */

    public function test_a_future_student_fee_changes_nothing_today_and_applies_from_its_date(): void
    {
        $this->loaded();
        $student = $this->byCode('ST');

        $future = $this->newPolicy($student, '100', '50', '2026-12-01', 'student fees introduced');

        $this->assertSame('0.00', $this->fees->effectiveFor($student)->registration_fee, 'today is untouched');
        $this->assertSame('0.00', $this->fees->effectiveFor($student, '2026-11-30')->registration_fee, 'the day before it starts');
        $this->assertSame($future->id, $this->fees->effectiveFor($student, '2026-12-01')->id, 'the day it starts');
        $this->assertSame('100.00', $this->fees->effectiveFor($student, '2027-06-01')->registration_fee);
        $this->assertSame('50.00', $this->fees->effectiveFor($student, '2027-06-01')->monthly_contribution);

        $this->assertSame($future->id, $this->fees->upcomingFor($student)->id);
    }

    public function test_the_previous_policy_is_closed_automatically_the_day_before_the_new_one_starts(): void
    {
        $this->loaded();
        $student = $this->byCode('ST');
        $initial = $this->fees->effectiveFor($student);
        $this->assertNull($initial->untilDate(), 'the initial policy is open-ended');

        $future = $this->newPolicy($student, '100', '50', '2026-12-01');

        $this->assertSame('2026-11-30', $initial->fresh()->untilDate());
        $this->assertNull($future->fresh()->untilDate());
        $this->assertSame('0.00', $initial->fresh()->registration_fee, 'the old row keeps its amounts');
    }

    public function test_a_policy_can_be_inserted_between_two_and_the_timeline_stays_contiguous(): void
    {
        $type = $this->bareType();
        $first = $this->newPolicy($type, '0', '0', '2026-10-05');
        $last = $this->newPolicy($type, '300', '30', '2027-01-01');

        $middle = $this->newPolicy($type, '100', '10', '2026-12-01');

        $this->assertSame('2026-11-30', $first->fresh()->untilDate());
        $this->assertSame('2026-12-31', $middle->fresh()->untilDate(), 'ends the day before the next one begins');
        $this->assertNull($last->fresh()->untilDate());
        foreach (['2026-10-05' => $first, '2026-11-30' => $first, '2026-12-01' => $middle, '2026-12-31' => $middle, '2027-01-01' => $last, '2030-01-01' => $last] as $day => $expected) {
            $this->assertSame($expected->id, $this->fees->effectiveFor($type, $day)?->id, "the policy on $day");
        }
    }

    /* ======================================================================================== E */

    public function test_two_policies_cannot_start_on_the_same_day(): void
    {
        $type = $this->bareType();
        $this->newPolicy($type, '100', '0', '2026-12-01');

        try {
            $this->newPolicy($type, '200', '0', '2026-12-01');
            $this->fail('a second active policy for the same start date must be refused');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('effective_from', $e->errors());
        }

        $this->assertSame(1, MembershipFeePolicy::query()->where('membership_type_id', $type->id)->count());
    }

    public function test_a_policy_cannot_start_in_the_past(): void
    {
        $type = $this->bareType();

        try {
            $this->newPolicy($type, '100', '0', '2026-10-04'); // yesterday on the organisation's calendar
            $this->fail('a past start date would rewrite what an elapsed day cost');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('effective_from', $e->errors());
        }

        $this->assertSame(0, $type->feePolicies()->count());
        $this->newPolicy($type, '100', '0', '2026-10-05'); // today is allowed
        $this->assertSame(1, $type->feePolicies()->count());
    }

    public function test_an_impossible_calendar_date_is_refused(): void
    {
        $type = $this->bareType();

        foreach (['2026-02-30', '2026-13-01', 'tomorrow', '', '05/12/2026'] as $bad) {
            try {
                $this->newPolicy($type, '1', '1', $bad);
                $this->fail("'$bad' must be refused");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('effective_from', $e->errors(), "'$bad'");
            }
        }
    }

    public function test_the_database_itself_refuses_two_active_policies_with_one_start_date(): void
    {
        $type = $this->bareType();
        $this->makeFeePolicy($type, '1', '1', '2026-12-01');

        $this->expectException(QueryException::class);
        $this->makeFeePolicy($type, '2', '2', '2026-12-01'); // bypasses the service on purpose
    }

    public function test_a_cancelled_policy_does_not_block_its_date_in_the_database(): void
    {
        $type = $this->bareType();
        $this->makeFeePolicy($type, '1', '1', '2026-12-01', null, false); // cancelled
        $this->makeFeePolicy($type, '2', '2', '2026-12-01'); // active, same date: allowed

        $this->assertSame(2, MembershipFeePolicy::query()->where('membership_type_id', $type->id)->count());
    }

    /* ======================================================================================== F */

    public function test_an_application_keeps_the_fee_it_was_quoted_after_the_fee_changes(): void
    {
        $this->loaded();
        $student = $this->byCode('ST');
        $initial = $this->fees->effectiveFor($student);

        $old = $this->applyPublicly($student, 'old@example.com'); // submitted today, under the free policy
        $this->assertSame($initial->id, $old->fee_policy_id);
        $this->assertSame('0.00', $old->quotedRegistrationFee());

        $this->newPolicy($student, '100', '50', '2026-10-06');
        Carbon::setTestNow('2026-10-07 10:00:00'); // the new fee is now in force

        $this->assertSame('100.00', $this->fees->effectiveFor($student)->registration_fee, 'the type now costs 100');
        $old->refresh();
        $this->assertSame($initial->id, $old->fee_policy_id, 'still tied to the policy it was quoted');
        $this->assertSame('0.00', $old->quotedRegistrationFee());
        $this->assertSame('0.00', $old->quotedMonthlyContribution());
        $this->assertSame('policy', $old->fee_snapshot_source);

        $new = $this->applyPublicly($student, 'new@example.com');
        $this->assertSame('100.00', $new->quotedRegistrationFee());
        $this->assertSame('50.00', $new->quotedMonthlyContribution());
        $this->assertNotSame($old->fee_policy_id, $new->fee_policy_id);
    }

    public function test_a_fee_change_does_not_make_an_old_free_application_suddenly_need_a_payment(): void
    {
        $this->loaded();
        $student = $this->byCode('ST');
        $old = $this->applyPublicly($student, 'old@example.com');
        $old->update(['status' => 'under_review']);

        $this->newPolicy($student, '100', '0', '2026-10-06');
        Carbon::setTestNow('2026-10-07 10:00:00');
        $new = $this->applyPublicly($student, 'new@example.com');
        $new->update(['status' => 'under_review']);

        $admin = $this->superAdmin();
        // approved with no payment at all: it was quoted nothing
        $this->actingAs($admin)->patch(route('admin.membership.status', $old), ['status' => 'approved'])->assertSessionHas('success');
        $this->assertSame('approved', $old->fresh()->status);
        // blocked: it was quoted 100
        $this->actingAs($admin)->patch(route('admin.membership.status', $new), ['status' => 'approved'])->assertSessionHas('error');
        $this->assertSame('under_review', $new->fresh()->status);
    }

    public function test_waiving_and_the_payment_form_use_the_quoted_fee_not_todays_policy(): void
    {
        $this->loaded();
        $general = $this->byCode('GM');
        $application = $this->applyPublicly($general); // quoted 100
        $this->newPolicy($general, '250', '0', '2026-10-06');
        Carbon::setTestNow('2026-10-07 10:00:00');

        $admin = $this->superAdmin();
        $this->actingAs($admin)->get(route('admin.membership.show', $application))->assertOk()->assertSee('value="100.00"', false);

        $this->actingAs($admin)->post(route('admin.membership.payments.waive', $application), ['waiver_reason' => 'honorary gesture'])->assertSessionHas('success');
        $this->assertSame('100.00', Payment::query()->where('payable_id', $application->id)->firstOrFail()->amount_expected);
    }

    public function test_an_explicit_snapshot_is_respected_and_a_type_without_a_policy_leaves_the_quote_empty(): void
    {
        $bare = $this->bareType();
        $application = MembershipApplication::query()->create([
            'application_no' => 'APP-NOPOLICY', 'applicant_name' => 'ক', 'applicant_email' => 'k@example.com', 'applicant_phone' => '0171',
            'membership_type_id' => $bare->id, 'status' => 'pending',
        ]);
        $this->assertNull($application->fresh()->registration_fee_amount);
        $this->assertNull($application->fresh()->fee_snapshot_source);

        $explicit = MembershipApplication::query()->create([
            'application_no' => 'APP-EXPLICIT', 'applicant_name' => 'খ', 'applicant_email' => 'kh@example.com', 'applicant_phone' => '0171',
            'membership_type_id' => $bare->id, 'status' => 'pending',
            'registration_fee_amount' => '75.50', 'monthly_contribution_amount' => '5.00', 'fee_snapshot_source' => 'legacy_flat_fee',
        ]);
        $this->assertSame('75.50', $explicit->fresh()->quotedRegistrationFee());
    }

    public function test_an_application_with_no_recorded_fee_is_never_treated_as_free(): void
    {
        $bare = $this->bareType();
        $application = MembershipApplication::query()->create([
            'application_no' => 'APP-NOFEE', 'applicant_name' => 'ক', 'applicant_email' => 'k@example.com', 'applicant_phone' => '0171',
            'membership_type_id' => $bare->id, 'status' => 'under_review',
        ]);

        $this->actingAs($this->superAdmin())->patch(route('admin.membership.status', $application), ['status' => 'approved'])->assertSessionHas('error');

        $this->assertSame('under_review', $application->fresh()->status);
    }

    /* ======================================================================================== G, H */

    public function test_zero_is_a_valid_fee(): void
    {
        $type = $this->bareType();

        $policy = $this->newPolicy($type, '0', '0', '2026-10-05');

        $this->assertSame('0.00', $policy->registration_fee);
        $this->assertSame('0.00', $policy->monthly_contribution);
    }

    public function test_negative_and_malformed_amounts_are_rejected(): void
    {
        $type = $this->bareType();

        foreach (['-1', '-0.01', '-100', '1e3', '12,5', 'abc', '', ' ', '0.125', '100000000', '1.', '.5'] as $bad) {
            foreach (['registration_fee', 'monthly_contribution'] as $field) {
                $input = ['registration_fee' => '10', 'monthly_contribution' => '10', 'effective_from' => '2026-12-01', 'note' => 'x', $field => $bad];
                try {
                    $this->fees->create($type, $input);
                    $this->fail("'$bad' must be refused as $field");
                } catch (ValidationException $e) {
                    $this->assertArrayHasKey($field, $e->errors(), "'$bad' as $field");
                }
            }
        }

        $this->assertSame(0, $type->feePolicies()->count(), 'a refused policy writes nothing');
    }

    public function test_amounts_are_stored_exactly_with_two_decimals(): void
    {
        $type = $this->bareType();

        $policy = $this->newPolicy($type, '99.5', '0.10', '2026-12-01');
        $other = $this->newPolicy($type, '99999999.99', '1', '2027-01-01');

        $this->assertSame('99.50', $policy->registration_fee);
        $this->assertSame('0.10', $policy->monthly_contribution);
        $this->assertSame('99999999.99', $other->registration_fee);
        $this->assertSame('1.00', $other->monthly_contribution);
    }

    public function test_money_helper_parses_strictly_and_displays_without_floats(): void
    {
        $this->assertSame('0.00', Money::parse('0'));
        $this->assertSame('500.00', Money::parse(500));
        $this->assertSame('12.50', Money::parse('12.5'));
        $this->assertSame('12.50', Money::parse(12.5));
        $this->assertSame('7.00', Money::parse('007'));
        foreach (['-5', '5e2', ' ', '', null, [], '1.234', '123456789', '5,00', true] as $bad) {
            $this->assertNull(Money::parse($bad), var_export($bad, true));
        }

        $this->assertTrue(Money::isPositive('0.01'));
        $this->assertFalse(Money::isPositive('0.00'));
        $this->assertFalse(Money::isPositive(null));
        $this->assertTrue(Money::equals('5', '5.00'));
        $this->assertFalse(Money::equals('5', '5.01'));

        $this->assertSame('৳0', Money::display('0.00'));
        $this->assertSame('৳500', Money::display('500.00'));
        $this->assertSame('৳1,500', Money::display('1500'));
        $this->assertSame('৳99.50', Money::display('99.5'));
        $this->assertSame('৳1,234,567.89', Money::display('1234567.89'));
        $this->assertSame('—', Money::display(null));
    }

    /* ======================================================================================== cancelling */

    public function test_a_scheduled_policy_can_be_cancelled_and_the_previous_one_continues(): void
    {
        $type = $this->bareType();
        $current = $this->newPolicy($type, '0', '0', '2026-10-05');
        $future = $this->newPolicy($type, '100', '50', '2026-12-01');
        $this->assertSame('2026-11-30', $current->fresh()->untilDate());

        $cancelled = $this->fees->cancel($future, $this->superAdmin(), 'entered by mistake');

        $this->assertFalse($cancelled->active);
        $this->assertSame('entered by mistake', $cancelled->cancellation_reason);
        $this->assertNotNull($cancelled->cancelled_at);
        $this->assertNull($current->fresh()->untilDate(), 'the previous policy is open-ended again');
        $this->assertSame($current->id, $this->fees->effectiveFor($type, '2027-01-01')->id);
        $this->assertNull($this->fees->upcomingFor($type));
        $this->assertSame(2, $type->feePolicies()->count(), 'the cancelled row stays for the audit trail');
    }

    public function test_a_cancelled_date_can_be_used_again(): void
    {
        $type = $this->bareType();
        $first = $this->newPolicy($type, '100', '0', '2026-12-01');
        $this->fees->cancel($first, $this->superAdmin(), 'wrong amount');

        $second = $this->newPolicy($type, '150', '0', '2026-12-01');

        $this->assertSame($second->id, $this->fees->effectiveFor($type, '2026-12-01')->id);
    }

    public function test_a_policy_that_has_started_can_never_be_cancelled(): void
    {
        $type = $this->bareType();
        $current = $this->newPolicy($type, '0', '0', '2026-10-05'); // starts today

        try {
            $this->fees->cancel($current, $this->superAdmin(), 'oops');
            $this->fail('a policy already in force must not be cancellable');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('policy', $e->errors());
        }

        $this->assertTrue($current->fresh()->active);
    }

    public function test_a_policy_an_application_was_quoted_can_never_be_cancelled(): void
    {
        $type = $this->bareType();
        $policy = $this->newPolicy($type, '0', '0', '2026-12-01');
        MembershipApplication::query()->create([ // forced reference, as a safety-net check
            'application_no' => 'APP-REF', 'applicant_name' => 'ক', 'applicant_email' => 'k@example.com', 'applicant_phone' => '0171',
            'membership_type_id' => $type->id, 'status' => 'pending', 'fee_policy_id' => $policy->id,
            'registration_fee_amount' => '0.00', 'fee_snapshot_source' => 'policy',
        ]);

        $this->expectException(ValidationException::class);
        $this->fees->cancel($policy, $this->superAdmin(), 'oops');
    }

    public function test_cancelling_requires_a_reason_and_a_cancelled_policy_cannot_be_cancelled_twice(): void
    {
        $type = $this->bareType();
        $future = $this->newPolicy($type, '100', '0', '2026-12-01');

        try {
            $this->fees->cancel($future, $this->superAdmin(), '   ');
            $this->fail('a reason is required');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('cancellation_reason', $e->errors());
        }

        $this->fees->cancel($future, $this->superAdmin(), 'wrong');
        $this->expectException(ValidationException::class);
        $this->fees->cancel($future->fresh(), $this->superAdmin(), 'again');
    }

    /* ======================================================================================== the organisation's calendar */

    public function test_the_policy_of_a_day_is_decided_on_the_organisations_calendar_not_utc(): void
    {
        $type = $this->bareType();
        $this->newPolicy($type, '0', '0', '2026-10-05');
        $this->newPolicy($type, '100', '0', '2026-12-01');

        // 19:00 UTC on 30 November is already 01:00 on 1 December in Dhaka
        Carbon::setTestNow('2026-11-30 19:00:00');
        $this->assertSame('2026-12-01', $this->fees->today());
        $this->assertSame('100.00', $this->fees->effectiveFor($type)->registration_fee);
        $this->assertSame('100.00', $this->fees->effectiveFor($type, Carbon::parse('2026-11-30 19:00:00'))->registration_fee);

        Carbon::setTestNow('2026-11-30 17:59:59'); // 23:59:59 in Dhaka: still the old fee
        $this->assertSame('2026-11-30', $this->fees->today());
        $this->assertSame('0.00', $this->fees->effectiveFor($type)->registration_fee);
    }

    public function test_an_application_is_quoted_the_policy_of_the_dhaka_day_it_was_submitted(): void
    {
        $type = $this->bareType();
        $old = $this->newPolicy($type, '0', '0', '2026-10-05');
        $new = $this->newPolicy($type, '100', '0', '2026-12-01');

        Carbon::setTestNow('2026-11-30 19:00:00'); // 1 December in Dhaka
        $application = $this->applyPublicly($type);

        $this->assertSame($new->id, $application->fee_policy_id);
        $this->assertSame('2026-12-01', $application->fee_effective_on->toDateString());
        $this->assertNotSame($old->id, $application->fee_policy_id);
    }

    /* ======================================================================================== I and the public contract */

    public function test_a_disabled_type_behaves_exactly_as_before(): void
    {
        $this->loaded();
        $student = $this->byCode('ST');
        $student->update(['status' => 'inactive']);

        $names = collect($this->getJson('/api/v1/membership-types')->assertOk()->json('data'))->pluck('code');
        $this->assertNotContains('ST', $names->all(), 'an inactive type is not listed');

        $this->postJson('/api/v1/public/membership/applications', [
            'applicant_name' => 'ক', 'applicant_email' => 'k@example.com', 'applicant_phone' => '0171', 'membership_type_id' => $student->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('membership_type_id');

        $this->assertSame(0, MembershipApplication::query()->count());
        // switching it off never touches its fee history, and switching it back on restores it
        $this->assertSame('0.00', $this->fees->effectiveFor($student)->registration_fee);
        $student->update(['status' => 'active']);
        $this->assertContains('ST', collect($this->getJson('/api/v1/membership-types')->json('data'))->pluck('code')->all());
    }

    public function test_the_public_list_quotes_the_policy_in_force_today_as_decimal_strings(): void
    {
        $this->loaded();
        $this->newPolicy($this->byCode('ST'), '100', '50', '2026-12-01'); // a future change must not leak into today's list

        $types = collect($this->getJson('/api/v1/membership-types')->assertOk()->json('data'))->keyBy('code');

        $this->assertSame(['LM', 'GM', 'ST'], $types->keys()->take(3)->all(), 'ordered by the display order the loader set');
        $this->assertSame('500.00', $types['LM']['registration_fee']);
        $this->assertSame('200.00', $types['LM']['monthly_contribution']);
        $this->assertSame('100.00', $types['GM']['registration_fee']);
        $this->assertSame('0.00', $types['GM']['monthly_contribution']);
        $this->assertSame('0.00', $types['ST']['registration_fee']);
        $this->assertSame('0.00', $types['ST']['monthly_contribution']);
        $this->assertSame('2026-10-05', $types['ST']['fee_effective_from']);
        $this->assertSame('500.00', $types['LM']['fee'], '`fee` stays as the deprecated alias of registration_fee for older builds of the site');
        $this->assertIsString($types['LM']['registration_fee']);
    }

    public function test_a_type_with_no_policy_in_force_is_not_offered_and_cannot_be_applied_to(): void
    {
        $bare = $this->bareType('XX');
        $this->newPolicy($bare, '10', '0', '2026-12-01'); // only a FUTURE policy: nothing in force today

        $this->assertSame([], $this->getJson('/api/v1/membership-types')->assertOk()->json('data'));
        $this->postJson('/api/v1/public/membership/applications', [
            'applicant_name' => 'ক', 'applicant_email' => 'k@example.com', 'applicant_phone' => '0171', 'membership_type_id' => $bare->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('membership_type_id');

        Carbon::setTestNow('2026-12-01 10:00:00'); // and it is offered the day the policy starts
        $this->assertCount(1, $this->getJson('/api/v1/membership-types')->json('data'));
    }

    public function test_a_type_hidden_from_the_public_site_is_neither_listed_nor_applicable(): void
    {
        $type = $this->makeMembershipType(['name' => 'লুকানো', 'slug' => 'hidden', 'code' => 'HD', 'is_public_visible' => false]);

        $this->assertSame([], $this->getJson('/api/v1/membership-types')->json('data'));
        $this->postJson('/api/v1/public/membership/applications', [
            'applicant_name' => 'ক', 'applicant_email' => 'k@example.com', 'applicant_phone' => '0171', 'membership_type_id' => $type->id,
        ])->assertUnprocessable();
    }

    public function test_the_campaign_payload_carries_the_quoted_fees_and_english_names(): void
    {
        $this->loaded();
        $season = \App\Models\MembershipSeason::query()->create([
            'name' => 'সিজন', 'slug' => 'season-'.uniqid(), 'campaign_type' => 'regular', 'status' => 'open', 'display_order' => 0,
        ]);
        $season->membershipTypes()->attach(MembershipType::query()->whereIn('code', ['LM', 'ST'])->pluck('id'));

        $types = collect($this->getJson('/api/v1/public/membership/campaigns/current')->assertOk()->json('data.0.membership_types'))->keyBy('code');

        $this->assertSame('Lifetime Member', $types['LM']['name_en']);
        $this->assertSame('500.00', $types['LM']['registration_fee']);
        $this->assertSame('200.00', $types['LM']['monthly_contribution']);
        $this->assertSame('0.00', $types['ST']['registration_fee']);
        $this->assertSame('500.00', $types['LM']['fee']);
    }

    /* ======================================================================================== the one-time loader */

    public function test_the_loader_sets_codes_english_names_order_and_the_first_policies(): void
    {
        $this->loaded();

        $codes = MembershipType::query()->orderBy('sort_order')->get()->map(fn ($t) => [$t->slug, $t->code, $t->name_en, $t->sort_order])->all();
        $this->assertSame([
            ['life', 'LM', 'Lifetime Member', 1],
            ['general', 'GM', 'General Member', 2],
            ['student', 'ST', 'Student Member', 3],
            ['honorary', null, null, 4],
        ], $codes);

        $honorary = MembershipType::query()->where('slug', 'honorary')->firstOrFail();
        $carry = $this->fees->effectiveFor($honorary);
        $this->assertSame('0.00', $carry->registration_fee, 'honorary is carried over from its legacy flat fee (0.00), not given a new price');
        $this->assertStringContainsString('carried over', $carry->note);

        $this->assertSame(4, MembershipFeePolicy::query()->count());
        $this->assertSame('2026-10-05', $this->fees->effectiveFor($this->byCode('LM'))->fromDate());
    }

    public function test_the_loader_is_idempotent_and_never_overwrites_what_an_admin_set(): void
    {
        $this->loaded();
        $lifetime = $this->byCode('LM');
        $lifetime->update(['name_en' => 'Life Member (admin wording)', 'sort_order' => 9]);

        $second = app(MembershipInitialPolicyLoader::class)->apply();

        $this->assertTrue($second['ok']);
        $this->assertSame(0, $second['created_policies'], 'every type already has a policy');
        $this->assertSame(4, MembershipFeePolicy::query()->count());
        $this->assertSame('Life Member (admin wording)', $lifetime->fresh()->name_en);
        $this->assertSame(9, $lifetime->fresh()->sort_order);
    }

    public function test_the_loader_refuses_uncertain_data_and_writes_nothing(): void
    {
        $this->seedProductionLikeTypes();
        MembershipType::query()->where('slug', 'student')->update(['name' => 'অন্য কিছু']); // same slug, a different name: not what we think it is

        $report = app(MembershipInitialPolicyLoader::class)->apply();

        $this->assertFalse($report['ok']);
        $this->assertFalse($report['applied']);
        $this->assertStringContainsString("slug 'student'", implode(' ', $report['problems']));
        $this->assertSame(0, MembershipFeePolicy::query()->count());
        $this->assertSame(0, MembershipType::query()->whereNotNull('code')->count());
    }

    public function test_the_loader_never_creates_a_type_and_refuses_when_one_is_missing(): void
    {
        MembershipType::query()->create(['slug' => 'general', 'name' => 'সাধারণ সদস্য', 'status' => 'active']);

        $report = app(MembershipInitialPolicyLoader::class)->apply();

        $this->assertFalse($report['ok']);
        $this->assertSame(1, MembershipType::query()->count());
        $this->assertSame(0, MembershipFeePolicy::query()->count());
    }

    public function test_the_loader_refuses_a_code_that_belongs_to_another_type(): void
    {
        $this->seedProductionLikeTypes();
        MembershipType::query()->where('slug', 'honorary')->update(['code' => 'LM']);

        $report = app(MembershipInitialPolicyLoader::class)->apply();

        $this->assertFalse($report['ok']);
        $this->assertStringContainsString('LM', implode(' ', $report['problems']));
        $this->assertSame(0, MembershipFeePolicy::query()->count());
    }

    public function test_the_loader_command_is_a_dry_run_unless_told_otherwise(): void
    {
        $this->seedProductionLikeTypes();

        $this->artisan('membership:load-initial-policies')->expectsOutputToContain('DRY RUN')->assertExitCode(0);
        $this->assertSame(0, MembershipFeePolicy::query()->count());

        $this->artisan('membership:load-initial-policies', ['--apply' => true])->expectsOutputToContain('4 fee policies created')->assertExitCode(0);
        $this->assertSame(4, MembershipFeePolicy::query()->count());

        $this->artisan('membership:load-initial-policies', ['--apply' => true])->expectsOutputToContain('0 fee policies created')->assertExitCode(0);
        $this->assertSame(4, MembershipFeePolicy::query()->count());
    }

    /* ======================================================================================== the admin screens */

    public function test_the_index_shows_each_types_fees_since_when_and_a_scheduled_change(): void
    {
        $this->loaded();
        $this->newPolicy($this->byCode('ST'), '100', '50', '2026-12-01');

        $page = $this->actingAs($this->admin())->get(route('admin.membership.types.index'))->assertOk();

        $page->assertSee('Lifetime Member')->assertSee('LM')->assertSee('৳500')->assertSee('৳200')->assertSee('৳100')->assertSee('৳0');
        $page->assertSee('Scheduled change')->assertSee('1 December 2026');
        $page->assertSee('5 October 2026');
    }

    public function test_the_index_renders_bengali_digits_under_the_bangla_admin(): void
    {
        $this->loaded();
        $this->actingAs($this->admin('bn'))->get(route('admin.membership.types.index'))->assertOk()
            ->assertSee('৳৫০০')->assertSee('৳২০০')->assertSee('৳১০০');
    }

    public function test_the_index_warns_when_a_type_has_no_policy_in_force(): void
    {
        $this->bareType('XX');

        $this->actingAs($this->admin())->get(route('admin.membership.types.index'))->assertOk()->assertSee('No fee policy in force');
    }

    public function test_the_type_page_lists_the_full_history_including_cancelled_versions_and_who_made_them(): void
    {
        $this->loaded();
        $admin = $this->admin();
        $student = $this->byCode('ST');
        $future = $this->fees->create($student, ['registration_fee' => '100', 'monthly_contribution' => '50', 'effective_from' => '2026-12-01', 'note' => 'student fees introduced'], $admin);
        $this->fees->cancel($future, $admin, 'owner changed their mind');

        $page = $this->actingAs($admin)->get(route('admin.membership.types.show', $student))->assertOk();

        $page->assertSee('Fee history')->assertSee('student fees introduced')->assertSee('owner changed their mind')
            ->assertSee('Cancelled')->assertSee('In force')->assertSee($admin->name)->assertSee('Initial policy — owner-approved fee schedule');
        $page->assertSee('data-policy-state="cancelled"', false)->assertSee('data-policy-state="current"', false);
    }

    public function test_help_text_with_an_apostrophe_is_escaped_once_not_twice(): void
    {
        $this->loaded();

        // "this type's fees" travels through a component attribute; it once rendered as a literal "&#039;" on screen
        $page = $this->actingAs($this->admin())->get(route('admin.membership.types.show', $this->byCode('ST')))->assertOk();

        $page->assertSee('this type&#039;s fees', false);
        $page->assertDontSee('&amp;#039;', false);

        $this->actingAs($this->admin())->get(route('admin.membership.types.create'))->assertOk()
            ->assertSee('the type&#039;s own page', false)->assertDontSee('&amp;#039;', false);
    }

    public function test_an_admin_can_create_a_future_policy_from_the_screen(): void
    {
        $this->loaded();
        $student = $this->byCode('ST');

        $this->actingAs($this->superAdmin())->post(route('admin.membership.types.fee-policies.store', $student), [
            'registration_fee' => '100', 'monthly_contribution' => '50', 'effective_from' => '2026-12-01', 'note' => 'student fees introduced',
        ])->assertRedirect(route('admin.membership.types.show', $student))->assertSessionHas('success');

        $this->assertSame('100.00', $this->fees->effectiveFor($student, '2026-12-01')->registration_fee);
        $this->assertSame('0.00', $this->fees->effectiveFor($student)->registration_fee);
    }

    public function test_the_screen_refuses_a_past_date_a_negative_fee_and_a_missing_reason_with_field_errors(): void
    {
        $this->loaded();
        $student = $this->byCode('ST');
        $admin = $this->superAdmin();
        $url = route('admin.membership.types.fee-policies.store', $student);

        $this->actingAs($admin)->post($url, ['registration_fee' => '100', 'monthly_contribution' => '0', 'effective_from' => '2026-10-01', 'note' => 'x'])
            ->assertSessionHasErrors('effective_from');
        $this->actingAs($admin)->post($url, ['registration_fee' => '-5', 'monthly_contribution' => '0', 'effective_from' => '2026-12-01', 'note' => 'x'])
            ->assertSessionHasErrors('registration_fee');
        $this->actingAs($admin)->post($url, ['registration_fee' => '5', 'monthly_contribution' => '0', 'effective_from' => '2026-12-01', 'note' => ''])
            ->assertSessionHasErrors('note');

        $this->assertSame(1, $student->feePolicies()->count(), 'nothing was added');
    }

    public function test_an_admin_can_cancel_a_scheduled_policy_but_not_a_current_one_from_the_screen(): void
    {
        $this->loaded();
        $student = $this->byCode('ST');
        $admin = $this->superAdmin();
        $future = $this->newPolicy($student, '100', '50', '2026-12-01');
        $current = $this->fees->effectiveFor($student);

        $this->actingAs($admin)->post(route('admin.membership.types.fee-policies.cancel', [$student, $current]), ['cancellation_reason' => 'oops'])
            ->assertSessionHasErrors('policy');
        $this->assertTrue($current->fresh()->active);

        $this->actingAs($admin)->post(route('admin.membership.types.fee-policies.cancel', [$student, $future]), ['cancellation_reason' => 'mistake'])
            ->assertRedirect()->assertSessionHas('success');
        $this->assertFalse($future->fresh()->active);

        $this->actingAs($admin)->post(route('admin.membership.types.fee-policies.cancel', [$student, $future]), [])->assertSessionHasErrors('cancellation_reason');
    }

    public function test_a_policy_cannot_be_cancelled_through_another_types_url(): void
    {
        $this->loaded();
        $future = $this->newPolicy($this->byCode('ST'), '100', '50', '2026-12-01');

        $this->actingAs($this->superAdmin())->post(route('admin.membership.types.fee-policies.cancel', [$this->byCode('LM'), $future]), ['cancellation_reason' => 'x'])
            ->assertNotFound();
        $this->assertTrue($future->fresh()->active);
    }

    public function test_only_people_with_the_update_permission_can_change_fees(): void
    {
        $this->loaded();
        $student = $this->byCode('ST');
        $viewer = $this->userWith(['membership.view']);
        $payload = ['registration_fee' => '100', 'monthly_contribution' => '0', 'effective_from' => '2026-12-01', 'note' => 'x'];

        $this->actingAs($viewer)->get(route('admin.membership.types.show', $student))->assertOk()->assertDontSee('Create fee policy');
        $this->actingAs($viewer)->post(route('admin.membership.types.fee-policies.store', $student), $payload)->assertForbidden();
        $this->actingAs($viewer)->patch(route('admin.membership.types.toggle', $student))->assertForbidden();
        $this->actingAs($viewer)->post(route('admin.membership.types.move-up', $student))->assertForbidden();

        $editor = $this->userWith(['membership.view', 'membership.update']);
        $this->actingAs($editor)->post(route('admin.membership.types.fee-policies.store', $student), $payload)->assertRedirect();
        $this->assertSame(2, $student->feePolicies()->count());
    }

    public function test_the_edit_form_has_no_fee_field_and_the_tabbed_bilingual_editor(): void
    {
        $this->loaded();

        $page = $this->actingAs($this->admin())->get(route('admin.membership.types.edit', $this->byCode('ST')))->assertOk();

        $page->assertDontSee('name="fee"', false)->assertDontSee('name="registration_fee"', false);
        $page->assertSee('name="name_en"', false)->assertSee('pf-bilingual-tabs', false)->assertSee('name="description_en"', false);
        $page->assertSee('Fee policy & history');
    }

    public function test_metadata_updates_never_touch_the_fees_and_a_code_once_set_is_permanent(): void
    {
        $this->loaded();
        $student = $this->byCode('ST');
        $policyBefore = $this->fees->effectiveFor($student)->only(['id', 'registration_fee', 'monthly_contribution']);

        $this->actingAs($this->superAdmin())->put(route('admin.membership.types.update', $student), [
            'name' => 'শিক্ষার্থী সদস্য', 'name_en' => 'Student Member', 'description' => 'নতুন বিবরণ', 'description_en' => 'New description',
            'code' => 'ZZ', 'status' => 'active', 'sort_order' => 3, 'is_student' => '1', 'is_public_self_apply' => '1', 'is_public_visible' => '1',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $student->refresh();
        $this->assertSame('ST', $student->code, 'the code cannot be changed once set');
        $this->assertSame('New description', $student->description_en);
        $this->assertSame($policyBefore, $this->fees->effectiveFor($student)->only(['id', 'registration_fee', 'monthly_contribution']));
    }

    public function test_a_legacy_type_without_a_code_can_be_given_one_once_and_codes_are_unique_and_upper_case(): void
    {
        $this->loaded();
        $honorary = MembershipType::query()->where('slug', 'honorary')->firstOrFail();
        $admin = $this->superAdmin();
        $update = fn (string $code) => $this->actingAs($admin)->put(route('admin.membership.types.update', $honorary), [
            'name' => 'সম্মানসূচক সদস্য', 'status' => 'active', 'sort_order' => 4, 'code' => $code,
            'is_public_self_apply' => '1', 'is_public_visible' => '1',
        ]);

        $update('lm')->assertSessionHasErrors('code'); // upper-cased to LM, which belongs to another type
        $update('1X')->assertSessionHasErrors('code');
        $update('hm')->assertSessionHasNoErrors();
        $this->assertSame('HM', $honorary->fresh()->code);
    }

    public function test_a_new_type_is_created_with_its_first_policy_in_one_step_or_not_at_all(): void
    {
        $admin = $this->superAdmin();

        $this->actingAs($admin)->post(route('admin.membership.types.store'), [
            'name' => 'আজীবন সদস্য', 'name_en' => 'Lifetime Member', 'code' => 'lm', 'status' => 'active', 'sort_order' => 1,
            'registration_fee' => '500', 'monthly_contribution' => '200', 'effective_from' => '2026-10-05', 'fee_note' => '',
            'is_public_self_apply' => '1', 'is_public_visible' => '1',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $type = MembershipType::query()->where('code', 'LM')->firstOrFail();
        $this->assertSame('lifetime-member', $type->slug);
        $this->assertSame('500.00', $this->fees->effectiveFor($type)->registration_fee);
        $this->assertSame($admin->id, $this->fees->effectiveFor($type)->created_by);

        // a refused policy (past date) rolls the whole creation back: no type without a price
        $this->actingAs($admin)->post(route('admin.membership.types.store'), [
            'name' => 'অন্য', 'code' => 'XX', 'status' => 'active', 'sort_order' => 2,
            'registration_fee' => '5', 'monthly_contribution' => '0', 'effective_from' => '2026-01-01',
        ])->assertSessionHasErrors('effective_from');
        $this->assertDatabaseMissing('membership_types', ['code' => 'XX']);
    }

    public function test_activate_deactivate_and_reordering_work_from_the_list(): void
    {
        $this->loaded();
        $admin = $this->superAdmin();
        $student = $this->byCode('ST');

        $this->actingAs($admin)->patch(route('admin.membership.types.toggle', $student))->assertRedirect()->assertSessionHas('success');
        $this->assertSame('inactive', $student->fresh()->status);
        $this->actingAs($admin)->patch(route('admin.membership.types.toggle', $student))->assertRedirect();
        $this->assertSame('active', $student->fresh()->status);

        // LM(1) GM(2) ST(3) honorary(4): move ST up above GM
        $this->actingAs($admin)->post(route('admin.membership.types.move-up', $student))->assertRedirect();
        $this->assertSame(['LM', 'ST', 'GM'], MembershipType::query()->whereNotNull('code')->orderBy('sort_order')->pluck('code')->all());
        $this->actingAs($admin)->post(route('admin.membership.types.move-down', $student))->assertRedirect();
        $this->assertSame(['LM', 'GM', 'ST'], MembershipType::query()->whereNotNull('code')->orderBy('sort_order')->pluck('code')->all());
    }

    public function test_reordering_works_even_when_every_type_still_has_order_zero(): void
    {
        $this->seedProductionLikeTypes(); // all sort_order 0, as on production before the loader
        $second = MembershipType::query()->orderBy('sort_order')->orderBy('name')->get()[1];
        $first = MembershipType::query()->orderBy('sort_order')->orderBy('name')->first();

        $this->actingAs($this->superAdmin())->post(route('admin.membership.types.move-up', $second))->assertRedirect();

        $this->assertSame($second->id, MembershipType::query()->orderBy('sort_order')->orderBy('name')->first()->id);
        $this->assertSame([1, 2, 3, 4], MembershipType::query()->orderBy('sort_order')->pluck('sort_order')->map(fn ($n) => (int) $n)->all());
        $this->assertNotSame($first->id, $second->id);
    }

    public function test_a_type_that_was_never_used_can_be_deleted_with_its_policies_but_a_used_one_cannot(): void
    {
        $admin = $this->superAdmin();
        $unused = $this->makeMembershipType(['name' => 'অব্যবহৃত', 'slug' => 'unused', 'code' => 'UU']);
        $used = $this->makeMembershipType(['name' => 'ব্যবহৃত', 'slug' => 'used', 'code' => 'US']);
        MembershipApplication::query()->create([
            'application_no' => 'APP-U', 'user_id' => User::factory()->create()->id, 'membership_type_id' => $used->id, 'status' => 'pending',
        ]);

        $this->actingAs($admin)->delete(route('admin.membership.types.destroy', $unused))->assertRedirect()->assertSessionHas('success');
        $this->assertDatabaseMissing('membership_types', ['id' => $unused->id]);
        $this->assertSame(0, MembershipFeePolicy::query()->where('membership_type_id', $unused->id)->count());

        $this->actingAs($admin)->delete(route('admin.membership.types.destroy', $used))->assertSessionHas('error');
        $this->assertDatabaseHas('membership_types', ['id' => $used->id]);
        $this->assertSame(1, MembershipFeePolicy::query()->where('membership_type_id', $used->id)->count());
    }

    public function test_the_legacy_flat_fee_column_is_no_longer_written_or_read(): void
    {
        $this->loaded();
        $student = $this->byCode('ST');

        $this->actingAs($this->superAdmin())->put(route('admin.membership.types.update', $student), [
            'name' => 'শিক্ষার্থী সদস্য', 'status' => 'active', 'sort_order' => 3, 'fee' => '999',
            'is_public_self_apply' => '1', 'is_public_visible' => '1',
        ])->assertRedirect();

        $this->assertSame('0.00', (string) $student->fresh()->getRawOriginal('fee'), 'the legacy column is untouched');
        $this->assertSame('0.00', $this->fees->effectiveFor($student)->registration_fee, 'and is not what the policy answers with');
        $this->assertNotContains('fee', (new MembershipType)->getFillable());
    }

    public function test_the_application_page_shows_the_quoted_fees_not_the_types_current_ones(): void
    {
        $this->loaded();
        $general = $this->byCode('GM');
        $application = $this->applyPublicly($general); // quoted 100 / 0
        $this->newPolicy($general, '250', '30', '2026-10-06');
        Carbon::setTestNow('2026-10-07 10:00:00');

        $this->actingAs($this->admin())->get(route('admin.membership.show', $application))->assertOk()
            ->assertSee('Registration fee (as quoted)')->assertSee('৳100')->assertDontSee('৳250')
            ->assertSee('Quoted under the fee policy in force on 5 October 2026');
    }
}
