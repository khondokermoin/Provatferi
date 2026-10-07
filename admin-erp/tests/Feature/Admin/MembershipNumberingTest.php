<?php

namespace Tests\Feature\Admin;

use App\Exceptions\MembershipApprovalBlocked;
use App\Models\Member;
use App\Models\Membership;
use App\Models\MembershipApplication;
use App\Models\MembershipType;
use App\Models\User;
use App\Notifications\MemberInvitationNotification;
use App\Notifications\MembershipApplicationStatusChangedNotification;
use App\Services\MembershipApprovalService;
use App\Services\NumberSequence;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use LogicException;
use RuntimeException;
use Tests\Concerns\MakesMembershipTypes;

/**
 * Membership task 3 (2026-10-07): member numbers PLCC-{type code}-{year}-{nnnn} and application numbers APP-{year}-{nnnn},
 * each from a counter of its own (App\Services\NumberSequence) — never from table ids, never reused, never changed.
 *
 * The owner's list: A-E and G-J and L here; F and K (real simultaneous processes) in MembershipNumberingConcurrencyTest.
 * The clock is 7 October 2026, 10:00 in Dhaka, unless a test moves it.
 */
class MembershipNumberingTest extends AdminTestCase
{
    use MakesMembershipTypes;

    private User $admin;

    private MembershipType $lifetime;

    private MembershipType $general;

    private MembershipType $student;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-07 04:00:00', 'UTC'));
        $this->admin = $this->superAdmin();
        $this->lifetime = $this->type('আজীবন সদস্য', 'LM');
        $this->general = $this->type('সাধারণ সদস্য', 'GM');
        $this->student = $this->type('শিক্ষার্থী সদস্য', 'ST');
    }

    private function type(string $name, ?string $code, string $registrationFee = '0'): MembershipType
    {
        return $this->makeMembershipType(
            ['name' => $name, 'code' => $code, 'slug' => 'numbering-'.uniqid(), 'is_public_self_apply' => true, 'is_public_visible' => true],
            ['registration' => $registrationFee, 'monthly' => '0'],
        );
    }

    /** An application under review, numbered the way every application is now (no number given). */
    private function application(MembershipType $type): MembershipApplication
    {
        return MembershipApplication::query()->create([
            'applicant_name' => 'নাসরিন আক্তার',
            'applicant_email' => 'nasrin-'.uniqid().'@example.com',
            'applicant_phone' => '017'.random_int(10000000, 99999999),
            'membership_type_id' => $type->id,
            'status' => 'under_review',
        ]);
    }

    private function approve(MembershipApplication $application): Membership
    {
        app(MembershipApprovalService::class)->approve($application, $this->admin);

        return Membership::query()->where('membership_application_id', $application->id)->sole();
    }

    /** A submission through the real public endpoint. @param array<string, mixed> $overrides */
    private function apply(MembershipType $type, array $overrides = [])
    {
        $season = $this->openSeasonOffering($type);

        return $this->postJson('/api/v1/public/membership/applications', $overrides + [
            'applicant_name' => 'রফিকুল ইসলাম',
            'applicant_email' => 'rafiq-'.uniqid().'@example.com',
            'applicant_phone' => '018'.random_int(10000000, 99999999),
            'membership_type_id' => $type->id,
            'membership_season_id' => $season->id,
        ]);
    }

    private function counter(string $key): int
    {
        return app(NumberSequence::class)->current($key);
    }

    /* ================================================================ A-E member numbers */

    public function test_a_b_the_first_lifetime_member_of_2026_is_0001_and_the_next_0002(): void
    {
        $this->assertSame('PLCC-LM-2026-0001', $this->approve($this->application($this->lifetime))->member_code);
        $this->assertSame('PLCC-LM-2026-0002', $this->approve($this->application($this->lifetime))->member_code);
        $this->assertSame(2, $this->counter('member:LM:2026'));
    }

    public function test_c_d_every_type_counts_on_its_own(): void
    {
        $this->approve($this->application($this->lifetime));
        $this->approve($this->application($this->lifetime));

        $this->assertSame('PLCC-GM-2026-0001', $this->approve($this->application($this->general))->member_code);
        $this->assertSame('PLCC-ST-2026-0001', $this->approve($this->application($this->student))->member_code);
        $this->assertSame('PLCC-LM-2026-0003', $this->approve($this->application($this->lifetime))->member_code);
    }

    public function test_a_type_added_later_numbers_its_members_with_its_own_code(): void
    {
        $honorary = $this->type('সম্মানসূচক সদস্য', 'HM');

        $this->assertSame('PLCC-HM-2026-0001', $this->approve($this->application($honorary))->member_code);
    }

    public function test_e_a_new_year_starts_every_type_again_at_0001_on_the_organisations_calendar(): void
    {
        $this->travelTo(Carbon::parse('2026-12-31 17:59:00', 'UTC')); // 23:59 on 31 December 2026 in Dhaka
        $this->assertSame('PLCC-ST-2026-0001', $this->approve($this->application($this->student))->member_code);
        $this->assertSame('PLCC-ST-2026-0002', $this->approve($this->application($this->student))->member_code);

        $this->travelTo(Carbon::parse('2026-12-31 18:01:00', 'UTC')); // 00:01 on 1 January 2027 in Dhaka — still 2026 in UTC
        $this->assertSame('PLCC-ST-2027-0001', $this->approve($this->application($this->student))->member_code);
        $this->assertSame('PLCC-LM-2027-0001', $this->approve($this->application($this->lifetime))->member_code);

        $this->assertSame(2, $this->counter('member:ST:2026'), 'the 2026 counter is left exactly where it was');
        $this->assertSame(1, $this->counter('member:ST:2027'));
    }

    /* ================================================================ G-H never consumed twice, never reused */

    public function test_g_a_retried_approval_returns_the_same_number_and_takes_nothing_from_the_counter(): void
    {
        Notification::fake();
        $application = $this->application($this->student);

        $this->actingAs($this->admin)->patch(route('admin.membership.status', $application), ['status' => 'approved'])->assertSessionHas('success');
        $this->actingAs($this->admin)->patch(route('admin.membership.status', $application), ['status' => 'approved'])->assertSessionHas('status');
        $retry = app(MembershipApprovalService::class)->approve($application->fresh(), $this->admin);

        $this->assertTrue($retry->alreadyApproved);
        $this->assertSame('PLCC-ST-2026-0001', $retry->membership->member_code);
        $this->assertSame(1, $this->counter('member:ST:2026'), 'the retries took no number');
        $this->assertSame('PLCC-ST-2026-0002', $this->approve($this->application($this->student))->member_code);
    }

    public function test_a_refused_approval_takes_no_number(): void
    {
        $paid = $this->type('সাধারণ সদস্য (ফি)', 'GF', '100');
        $application = $this->application($paid);

        $this->actingAs($this->admin)->patch(route('admin.membership.status', $application), ['status' => 'approved'])->assertSessionHas('error');
        $this->assertSame(0, $this->counter('member:GF:2026'), 'blocked by the unpaid fee: no number was taken');

        $this->actingAs($this->admin)->post(route('admin.membership.payments.waive', $application), ['waiver_reason' => 'পরীক্ষা'])->assertRedirect();
        $this->assertSame('PLCC-GF-2026-0001', $this->approve($application->fresh())->member_code);
    }

    public function test_a_number_taken_inside_a_transaction_that_rolls_back_is_handed_out_again(): void
    {
        $sequences = app(NumberSequence::class);
        try {
            DB::transaction(function () use ($sequences) {
                $this->assertSame(1, $sequences->next('member:RB:2026'));
                throw new RuntimeException('the insert that would have carried the number failed');
            });
        } catch (RuntimeException) {
        }

        $this->assertSame(0, $sequences->current('member:RB:2026'));
        $this->assertSame(1, DB::transaction(fn () => $sequences->next('member:RB:2026')));
    }

    public function test_h_an_archived_or_deleted_membership_never_gives_its_number_back(): void
    {
        $first = $this->approve($this->application($this->lifetime));
        $this->actingAs($this->admin)->patch(route('admin.membership.members.status', $first), ['action' => 'archive', 'reason' => 'পরীক্ষামূলক সংরক্ষণ'])->assertSessionHas('success');
        $this->assertSame('archived', $first->fresh()->status);
        $this->assertSame('PLCC-LM-2026-0001', $first->fresh()->member_code, 'archiving keeps the number');

        $second = $this->approve($this->application($this->lifetime));
        $this->assertSame('PLCC-LM-2026-0002', $second->member_code);

        // Even a membership row removed from the database (nothing in the admin can do that) does not free its number.
        DB::table('memberships')->where('id', $second->id)->delete();
        $this->assertSame('PLCC-LM-2026-0003', $this->approve($this->application($this->lifetime))->member_code);
        $this->assertSame(3, $this->counter('member:LM:2026'));
    }

    public function test_a_number_some_record_already_carries_is_skipped_never_issued_twice(): void
    {
        // e.g. a membership recorded by hand, or imported, before the counter existed
        Membership::query()->create(['membership_type_id' => $this->student->id, 'member_code' => 'PLCC-ST-2026-0001', 'start_date' => '2026-01-10', 'status' => 'active']);

        $this->assertSame('PLCC-ST-2026-0002', $this->approve($this->application($this->student))->member_code);
    }

    /* ================================================================ I-J application numbers */

    public function test_i_application_numbers_are_app_year_0001_0002_0003(): void
    {
        $numbers = array_map(fn () => $this->apply($this->student)->assertCreated()->json('data.application_no'), range(1, 3));

        $this->assertSame(['APP-2026-0001', 'APP-2026-0002', 'APP-2026-0003'], $numbers);
        $this->assertSame(3, $this->counter('application:2026'));
    }

    public function test_j_a_deleted_application_does_not_give_its_number_back(): void
    {
        $this->apply($this->student)->assertCreated();
        $second = $this->apply($this->student)->assertCreated()->json('data.application_no');
        MembershipApplication::query()->where('application_no', $second)->delete();

        $this->assertSame('APP-2026-0003', $this->apply($this->student)->assertCreated()->json('data.application_no'));
    }

    public function test_a_refused_submission_takes_no_application_number(): void
    {
        $this->apply($this->student, ['applicant_name' => ''])->assertUnprocessable();
        $this->apply($this->student, ['membership_season_id' => 999999])->assertUnprocessable();
        $this->assertSame(0, $this->counter('application:2026'), 'refused before anything was stored: no number taken');

        $this->assertSame('APP-2026-0001', $this->apply($this->student)->assertCreated()->json('data.application_no'));
    }

    public function test_an_application_number_some_record_already_carries_is_skipped(): void
    {
        MembershipApplication::query()->create([
            'application_no' => 'APP-2026-0001', 'applicant_name' => 'পুরোনো আবেদন', 'applicant_email' => 'old@example.com',
            'applicant_phone' => '01700000000', 'membership_type_id' => $this->student->id, 'status' => 'pending',
        ]);

        $this->assertSame('APP-2026-0002', $this->apply($this->student)->assertCreated()->json('data.application_no'));
    }

    /* ================================================================ L the type's code */

    public function test_l_a_type_without_a_valid_code_cannot_be_approved_and_the_admin_is_told_why(): void
    {
        $honorary = $this->type('সম্মানসূচক সদস্য', null);
        $application = $this->application($honorary);

        $this->actingAs($this->admin)->get(route('admin.membership.show', $application))->assertOk()
            ->assertSee('data-testid="check-numbering" data-ok="0"', false)
            ->assertSee(route('admin.membership.types.edit', $honorary), false);
        $this->actingAs($this->admin)->patch(route('admin.membership.status', $application), ['status' => 'approved'])
            ->assertSessionHas('error', fn (string $message) => str_contains($message, 'সম্মানসূচক সদস্য'));

        $this->assertSame('under_review', $application->fresh()->status);
        $this->assertSame(0, Membership::query()->count());
        $this->assertSame(0, DB::table('number_sequences')->where('sequence_key', 'like', 'member:%')->count(), 'no counter was touched');

        // A code that is not a valid one (written straight into the database, past the form's validation) counts as none.
        $odd = $this->type('বিশেষ সদস্য', null);
        DB::table('membership_types')->where('id', $odd->id)->update(['code' => 'x-1']);
        try {
            app(MembershipApprovalService::class)->approve($this->application($odd->fresh()), $this->admin);
            $this->fail('approved a type whose code is not valid');
        } catch (MembershipApprovalBlocked $blocked) {
            $this->assertSame('numbering', $blocked->reason);
        }

        // Once an admin gives the type its code, the same application is approved and numbered with it.
        $honorary->update(['code' => 'HM']);
        $this->assertSame('PLCC-HM-2026-0001', $this->approve($application->fresh())->member_code);
    }

    /* ================================================================ permanent, shown, searchable */

    public function test_issued_numbers_can_never_be_changed(): void
    {
        $membership = $this->approve($this->application($this->student));
        $changes = [
            fn () => $membership->update(['member_code' => 'PLCC-ST-2026-9999']),
            fn () => $membership->application->update(['application_no' => 'APP-2026-9999']),
            fn () => $membership->member->update(['member_code' => 'PLCC-ST-2026-9999']),
        ];
        foreach ($changes as $change) {
            try {
                $change();
                $this->fail('an issued number was changed');
            } catch (LogicException) {
            }
        }

        // Other changes go through as before (fresh copies: the refused ones above still hold the rejected numbers).
        $membership->member->fresh()->update(['name' => 'নতুন নাম', 'phone' => '01799999999']);
        $membership->fresh()->update(['status' => 'suspended']);
        $this->assertSame('PLCC-ST-2026-0001', $membership->fresh()->member_code);
        $this->assertSame('PLCC-ST-2026-0001', $membership->member->fresh()->member_code, 'a profile change leaves the number alone');
        $this->assertSame('APP-2026-0001', $membership->application->fresh()->application_no);
    }

    public function test_the_e_mails_the_registry_and_the_member_portal_show_the_new_number(): void
    {
        Notification::fake();
        $application = $this->application($this->student);
        $this->actingAs($this->admin)->patch(route('admin.membership.status', $application), ['status' => 'approved'])->assertSessionHas('success');

        Notification::assertSentOnDemand(MembershipApplicationStatusChangedNotification::class,
            fn ($notification, $channels, $notifiable) => in_array('আপনার সদস্য নম্বর: PLCC-ST-2026-0001', $notification->toMail($notifiable)->introLines, true));
        $member = Member::query()->where('member_code', 'PLCC-ST-2026-0001')->sole();
        Notification::assertSentTo($member, MemberInvitationNotification::class,
            fn ($notification) => in_array('আপনার সদস্য নম্বর: PLCC-ST-2026-0001', $notification->toMail($member)->introLines, true));

        $membership = $member->memberships()->sole();
        $this->actingAs($this->admin)->get(route('admin.membership.members.index'))->assertOk()->assertSee('data-member-code="PLCC-ST-2026-0001"', false);
        $this->actingAs($this->admin)->get(route('admin.membership.members.show', $membership))->assertOk()->assertSee('PLCC-ST-2026-0001');
        $this->actingAs($this->admin)->get(route('admin.membership.show', $application))->assertOk()->assertSee('PLCC-ST-2026-0001')->assertSee('APP-2026-0001');

        $token = $member->createToken('portal')->plainTextToken;
        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/member/me')->assertOk()
            ->assertJsonPath('data.profile.member_code', 'PLCC-ST-2026-0001')
            ->assertJsonPath('data.memberships.0.member_code', 'PLCC-ST-2026-0001');
    }

    public function test_search_finds_a_number_exactly_in_part_or_written_without_its_zeros(): void
    {
        $this->approve($this->application($this->lifetime)); // APP-2026-0001 → PLCC-LM-2026-0001
        $this->approve($this->application($this->student));  // APP-2026-0002 → PLCC-ST-2026-0001
        $registry = fn (string $q) => $this->actingAs($this->admin)->get(route('admin.membership.members.index', ['search' => $q]))->assertOk();

        $registry('PLCC-LM-2026-0001')->assertSee('data-member-code="PLCC-LM-2026-0001"', false)->assertDontSee('data-member-code="PLCC-ST-2026-0001"', false);
        $registry('lm-2026')->assertSee('data-member-code="PLCC-LM-2026-0001"', false)->assertDontSee('data-member-code="PLCC-ST-2026-0001"', false);
        $registry('ST-2026-1')->assertSee('data-member-code="PLCC-ST-2026-0001"', false)->assertDontSee('data-member-code="PLCC-LM-2026-0001"', false);
        $registry('plcc')->assertSee('data-member-code="PLCC-LM-2026-0001"', false)->assertSee('data-member-code="PLCC-ST-2026-0001"', false);

        $applications = fn (string $q) => $this->actingAs($this->admin)->get(route('admin.membership.index', ['search' => $q]))->assertOk();
        $applications('2026-2')->assertSee('APP-2026-0002')->assertDontSee('APP-2026-0001');
        $applications('APP-2026-0001')->assertSee('APP-2026-0001')->assertDontSee('APP-2026-0002');
    }

    public function test_the_type_page_shows_the_next_member_number_or_why_there_cannot_be_one(): void
    {
        $this->approve($this->application($this->lifetime));
        $this->actingAs($this->admin)->get(route('admin.membership.types.show', $this->lifetime))->assertOk()
            ->assertSee('data-testid="member-number-format"', false)->assertSee('PLCC-LM-2026-0002');

        $honorary = $this->type('সম্মানসূচক সদস্য', null);
        $this->actingAs($this->admin)->get(route('admin.membership.types.show', $honorary))->assertOk()
            ->assertSee('data-testid="member-number-format"', false)->assertSee(__('admin.fee_policy.member_numbers_need_code'))->assertDontSee('PLCC-');
    }
}
