<?php

namespace Tests\Feature\Admin;

use App\Models\ApprovalHistory;
use App\Models\Member;
use App\Models\Membership;
use App\Models\MembershipApplication;
use App\Models\MembershipType;
use App\Models\Payment;
use App\Models\User;

class MembershipManagementTest extends AdminTestCase
{
    private function type(): MembershipType
    {
        return MembershipType::query()->create(['name' => 'সাধারণ সদস্য', 'slug' => 'general-'.uniqid(), 'fee' => 0, 'status' => 'active', 'sort_order' => 1]);
    }

    private function application(string $status = 'pending'): MembershipApplication
    {
        return MembershipApplication::query()->create([
            'application_no' => 'APP-'.uniqid(),
            'user_id' => User::factory()->create()->id,
            'membership_type_id' => $this->type()->id,
            'status' => $status,
        ]);
    }

    /* ---------- Membership Types ---------- */

    public function test_membership_type_can_be_created(): void
    {
        $this->actingAs($this->superAdmin())->post(route('admin.membership.types.store'), [
            'name' => 'আজীবন সদস্য', 'fee' => 0, 'status' => 'active', 'sort_order' => 3,
        ])->assertRedirect();

        $this->assertDatabaseHas('membership_types', ['name' => 'আজীবন সদস্য']);
    }

    public function test_membership_type_in_use_cannot_be_deleted(): void
    {
        $type = $this->type();
        MembershipApplication::query()->create([
            'application_no' => 'APP-1', 'user_id' => User::factory()->create()->id,
            'membership_type_id' => $type->id, 'status' => 'pending',
        ]);

        $this->actingAs($this->superAdmin())->delete(route('admin.membership.types.destroy', $type))
            ->assertRedirect()->assertSessionHas('error');

        $this->assertDatabaseHas('membership_types', ['id' => $type->id]);
    }

    /* ---------- Application workflow ---------- */

    public function test_pending_can_move_to_under_review(): void
    {
        $application = $this->application('pending');

        $this->actingAs($this->superAdmin())->patch(route('admin.membership.status', $application), [
            'status' => 'under_review',
        ])->assertRedirect();

        $this->assertSame('under_review', $application->fresh()->status);
    }

    public function test_pending_cannot_jump_directly_to_approved(): void
    {
        $application = $this->application('pending');

        $this->actingAs($this->superAdmin())->patch(route('admin.membership.status', $application), [
            'status' => 'approved',
        ])->assertSessionHasErrors('status');

        $this->assertSame('pending', $application->fresh()->status);
    }

    public function test_under_review_can_request_more_information(): void
    {
        $application = $this->application('under_review');

        $this->actingAs($this->superAdmin())->patch(route('admin.membership.status', $application), [
            'status' => 'need_information', 'review_notes' => 'জন্মতারিখ যুক্ত করুন।',
        ])->assertRedirect();

        $fresh = $application->fresh();
        $this->assertSame('need_information', $fresh->status);
        $this->assertSame('জন্মতারিখ যুক্ত করুন।', $fresh->review_notes);
    }

    public function test_need_information_can_return_to_under_review(): void
    {
        $application = $this->application('need_information');

        $this->actingAs($this->superAdmin())->patch(route('admin.membership.status', $application), [
            'status' => 'under_review',
        ])->assertRedirect();

        $this->assertSame('under_review', $application->fresh()->status);
    }

    public function test_rejecting_requires_a_reason(): void
    {
        $application = $this->application('under_review');

        $this->actingAs($this->superAdmin())->patch(route('admin.membership.status', $application), [
            'status' => 'rejected',
        ])->assertSessionHasErrors('rejection_reason');
    }

    public function test_rejecting_with_a_reason_succeeds(): void
    {
        $application = $this->application('under_review');

        $this->actingAs($this->superAdmin())->patch(route('admin.membership.status', $application), [
            'status' => 'rejected', 'rejection_reason' => 'Incomplete documentation.',
        ])->assertRedirect();

        $this->assertSame('rejected', $application->fresh()->status);
    }

    public function test_terminal_states_accept_no_further_transitions(): void
    {
        $application = $this->application('approved');

        $this->actingAs($this->superAdmin())->patch(route('admin.membership.status', $application), [
            'status' => 'under_review',
        ])->assertSessionHasErrors('status');
    }

    public function test_approving_creates_a_linked_membership(): void
    {
        $application = $this->application('under_review');

        $this->actingAs($this->superAdmin())->patch(route('admin.membership.status', $application), [
            'status' => 'approved',
        ])->assertRedirect();

        $this->assertSame('approved', $application->fresh()->status);
        $membership = Membership::query()->where('membership_application_id', $application->id)->first();
        $this->assertNotNull($membership);
        $this->assertSame('active', $membership->status);
        $this->assertSame($application->user_id, $membership->user_id);
        $this->assertNotEmpty($membership->member_code);
    }

    public function test_approve_is_idempotent_even_if_a_membership_already_exists(): void
    {
        // The transition map already blocks re-approving a normal 'approved'
        // application. This exercises the DB-transaction guard in
        // MembershipController::createMembership() directly, for the edge
        // case where a membership row exists but the application status
        // hasn't caught up yet (e.g. a retried request mid-transaction).
        $application = $this->application('under_review');
        Membership::query()->create([
            'membership_application_id' => $application->id,
            'user_id' => $application->user_id,
            'membership_type_id' => $application->membership_type_id,
            'member_code' => 'PF-PRE-EXISTING',
            'start_date' => now()->toDateString(),
            'status' => 'active',
        ]);

        $this->actingAs($this->superAdmin())->patch(route('admin.membership.status', $application), [
            'status' => 'approved',
        ])->assertRedirect();

        $this->assertSame(1, Membership::query()->where('membership_application_id', $application->id)->count());
        $this->assertDatabaseHas('memberships', ['membership_application_id' => $application->id, 'member_code' => 'PF-PRE-EXISTING']);
    }

    public function test_no_public_route_exposes_membership_applications_or_their_review_notes(): void
    {
        // Applications (and their internal review_notes) have no public API
        // resource at all — the strongest guarantee against leaking them.
        $application = $this->application('under_review');
        $application->update(['review_notes' => 'ব্যক্তিগত নোট — শুধু প্রশাসনিক ব্যবহারের জন্য।']);

        $this->getJson("/api/v1/membership-applications/{$application->id}")->assertNotFound();
        $this->assertFalse(app('router')->has('api.membership-applications.show'));
    }

    /* ---------- Members ---------- */

    public function test_member_status_can_be_updated(): void
    {
        $application = $this->application('under_review');
        $this->actingAs($this->superAdmin())->patch(route('admin.membership.status', $application), ['status' => 'approved']);
        $membership = Membership::query()->where('membership_application_id', $application->id)->firstOrFail();

        $this->actingAs($this->superAdmin())->put(route('admin.membership.members.update', $membership), [
            'status' => 'suspended', 'notes' => 'সাময়িক স্থগিত।',
        ])->assertRedirect();

        $fresh = $membership->fresh();
        $this->assertSame('suspended', $fresh->status);
        $this->assertSame('সাময়িক স্থগিত।', $fresh->notes);
    }

    public function test_members_list_shows_empty_state_when_none_exist(): void
    {
        $this->actingAs($this->superAdmin())->get(route('admin.membership.members.index'))
            ->assertOk()->assertSee('এখনো কোনো সদস্য নেই');
    }

    public function test_members_index_and_show_display_public_applicants_name_not_a_dash(): void
    {
        $admin = $this->superAdmin();
        $application = $this->publicApplication($this->type());
        $application->payments()->create([
            'membership_type_id' => $application->membership_type_id,
            'amount_expected' => 0, 'amount_received' => 0, 'received_at' => now()->toDateString(),
            'method' => 'cash', 'status' => 'waived', 'verified_at' => now(), 'received_by' => $admin->id,
        ]);
        $this->actingAs($admin)->patch(route('admin.membership.status', $application), ['status' => 'approved']);
        $membership = Membership::query()->where('membership_application_id', $application->id)->firstOrFail();

        $this->actingAs($admin)->get(route('admin.membership.members.index'))
            ->assertOk()->assertSee('রহিমা খাতুন')->assertDontSee('—</td>', false);

        $this->actingAs($admin)->get(route('admin.membership.members.show', $membership))
            ->assertOk()->assertSee('রহিমা খাতুন')->assertSee($application->applicant_email);
    }

    public function test_members_show_lists_season_history_for_public_applicants(): void
    {
        $admin = $this->superAdmin();
        $type = $this->type();
        $season = \App\Models\MembershipSeason::query()->create([
            'name' => '২০২৬ মৌসুম', 'slug' => 'season-'.uniqid(), 'campaign_type' => 'regular',
            'status' => 'open', 'display_order' => 0,
        ]);
        $application = $this->publicApplication($type);
        $application->update(['membership_season_id' => $season->id]);
        $application->payments()->create([
            'membership_type_id' => $type->id,
            'amount_expected' => 0, 'amount_received' => 0, 'received_at' => now()->toDateString(),
            'method' => 'cash', 'status' => 'waived', 'verified_at' => now(), 'received_by' => $admin->id,
        ]);
        $this->actingAs($admin)->patch(route('admin.membership.status', $application), ['status' => 'approved']);
        $membership = Membership::query()->where('membership_application_id', $application->id)->firstOrFail();

        $this->assertDatabaseHas('member_season_history', [
            'member_id' => $membership->member_id, 'membership_season_id' => $season->id,
        ]);

        $this->actingAs($admin)->get(route('admin.membership.members.show', $membership))
            ->assertOk()->assertSee('২০২৬ মৌসুম');
    }

    /* ---------- §0/§18: public applicant identity (no ERP account) ---------- */

    private function paidType(): MembershipType
    {
        return MembershipType::query()->create(['name' => 'সাধারণ (ফি সহ)', 'slug' => 'general-paid-'.uniqid(), 'fee' => 500, 'status' => 'active', 'sort_order' => 2]);
    }

    private function publicApplication(MembershipType $type, string $status = 'under_review'): MembershipApplication
    {
        return MembershipApplication::query()->create([
            'application_no' => 'APP-PUB-'.uniqid(),
            'applicant_name' => 'রহিমা খাতুন', 'applicant_email' => 'rohima-'.uniqid().'@example.com', 'applicant_phone' => '01700000000',
            'membership_type_id' => $type->id,
            'status' => $status,
        ]);
    }

    public function test_a_fee_bearing_application_cannot_be_approved_before_any_payment_is_recorded(): void
    {
        $application = $this->publicApplication($this->paidType());

        $this->actingAs($this->superAdmin())->patch(route('admin.membership.status', $application), ['status' => 'approved'])
            ->assertRedirect()->assertSessionHas('error');

        $this->assertSame('under_review', $application->fresh()->status);
        $this->assertNull(Membership::query()->where('membership_application_id', $application->id)->first());
    }

    public function test_recording_a_payment_without_verification_still_blocks_approval(): void
    {
        $admin = $this->superAdmin();
        $application = $this->publicApplication($this->paidType());

        $this->actingAs($admin)->post(route('admin.membership.payments.store', $application), [
            'amount_expected' => 500, 'amount_received' => 500, 'received_at' => now()->toDateString(),
        ])->assertRedirect();

        $this->actingAs($admin)->patch(route('admin.membership.status', $application), ['status' => 'approved'])
            ->assertSessionHas('error');
        $this->assertSame('under_review', $application->fresh()->status);
    }

    public function test_a_verified_payment_allows_approval_and_creates_a_member_not_a_user(): void
    {
        $admin = $this->superAdmin();
        $application = $this->publicApplication($this->paidType());

        $payment = $application->payments()->create([
            'membership_type_id' => $application->membership_type_id,
            'amount_expected' => 500, 'amount_received' => 500, 'received_at' => now()->toDateString(),
            'method' => 'cash', 'status' => 'paid', 'received_by' => $admin->id,
        ]);
        $this->actingAs($admin)->patch(route('admin.membership.payments.verify', $payment))->assertRedirect();

        $this->actingAs($admin)->patch(route('admin.membership.status', $application), ['status' => 'approved'])
            ->assertRedirect();

        $membership = Membership::query()->where('membership_application_id', $application->id)->firstOrFail();
        $this->assertNull($membership->user_id, 'a public applicant must never gain an ERP users row');
        $this->assertNotNull($membership->member_id);
        $member = Member::query()->findOrFail($membership->member_id);
        $this->assertSame('rohima', mb_substr($member->email, 0, 6), 'the member row should carry the applicant\'s own email');
    }

    public function test_a_free_type_can_be_waived_and_then_approved(): void
    {
        $admin = $this->superAdmin();
        $type = $this->paidType();
        $application = $this->publicApplication($type);

        $this->actingAs($admin)->post(route('admin.membership.payments.waive', $application), [
            'waiver_reason' => 'সাংগঠনিক সিদ্ধান্তে মওকুফ।',
        ])->assertRedirect();

        $payment = Payment::query()->where('payable_id', $application->id)->where('payable_type', MembershipApplication::class)->firstOrFail();
        $this->assertSame('waived', $payment->status);
        $this->assertNotNull($payment->verified_at, 'a waiver counts as verified — it should not need a second verify step');

        $this->actingAs($admin)->patch(route('admin.membership.status', $application), ['status' => 'approved'])
            ->assertRedirect();
        $this->assertSame('approved', $application->fresh()->status);
    }

    public function test_a_repeat_applicant_reuses_the_existing_member_rather_than_duplicating(): void
    {
        $admin = $this->superAdmin();
        $type = MembershipType::query()->create(['name' => 'ফ্রি টাইপ', 'slug' => 'free-'.uniqid(), 'fee' => 0, 'status' => 'active']);

        $first = MembershipApplication::query()->create([
            'application_no' => 'APP-A-'.uniqid(), 'applicant_name' => 'করিম', 'applicant_email' => 'karim@example.com',
            'applicant_phone' => '01711111111', 'membership_type_id' => $type->id, 'status' => 'under_review',
        ]);
        $this->actingAs($admin)->patch(route('admin.membership.status', $first), ['status' => 'approved']);
        $firstMemberId = Membership::query()->where('membership_application_id', $first->id)->firstOrFail()->member_id;

        $second = MembershipApplication::query()->create([
            'application_no' => 'APP-B-'.uniqid(), 'applicant_name' => 'করিম', 'applicant_email' => 'karim@example.com',
            'applicant_phone' => '01711111111', 'membership_type_id' => $type->id, 'status' => 'under_review',
        ]);
        $this->actingAs($admin)->patch(route('admin.membership.status', $second), ['status' => 'approved']);
        $secondMemberId = Membership::query()->where('membership_application_id', $second->id)->firstOrFail()->member_id;

        $this->assertSame($firstMemberId, $secondMemberId, 'the same email should resolve to one Member, not a duplicate');
        $this->assertSame(1, Member::query()->where('email', 'karim@example.com')->count());
    }

    public function test_approval_and_rejection_are_recorded_in_shared_approval_history(): void
    {
        $admin = $this->superAdmin();
        $application = $this->application('under_review');

        $this->actingAs($admin)->patch(route('admin.membership.status', $application), [
            'status' => 'rejected', 'rejection_reason' => 'Incomplete documentation.',
        ]);

        $entry = ApprovalHistory::query()->where('subject_type', MembershipApplication::class)->where('subject_id', $application->id)->firstOrFail();
        $this->assertSame('rejected', $entry->action);
        $this->assertSame($admin->id, $entry->actor_id);
    }

    /* ---------- RBAC ---------- */

    public function test_only_approve_permission_can_change_status(): void
    {
        $application = $this->application('under_review');
        $viewer = $this->userWith(['membership.view']);

        $this->actingAs($viewer)->patch(route('admin.membership.status', $application), ['status' => 'approved'])
            ->assertForbidden();
    }

    public function test_rbac_guards_membership_types_and_members(): void
    {
        $viewer = $this->userWith(['membership.view']);
        $this->actingAs($viewer)->get(route('admin.membership.types.index'))->assertOk();
        $this->actingAs($viewer)->get(route('admin.membership.types.create'))->assertForbidden();
        $this->actingAs($viewer)->get(route('admin.membership.members.index'))->assertOk();

        $outsider = $this->userWith(['recruitment.view']);
        $this->actingAs($outsider)->get(route('admin.membership.index'))->assertForbidden();
    }
}
