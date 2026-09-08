<?php

namespace Tests\Feature\Admin;

use App\Models\Membership;
use App\Models\MembershipApplication;
use App\Models\MembershipType;
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
