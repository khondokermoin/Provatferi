<?php

namespace Tests\Feature\Admin;

use App\Models\ApprovalHistory;
use App\Models\Committee;
use App\Models\CommitteeMember;
use App\Models\CommitteePosition;
use App\Models\CommitteeRegistrationLink;
use App\Models\CommitteeSubmission;
use App\Models\OrganizationalUnit;

/**
 * §19-28/§36: committee lifecycle, committee-scoped positions, registration
 * links, and public-submission review — the new pieces built on top of the
 * Phase 1 committee CRUD already covered by OrganizationStructureTest.
 */
class CommitteeRegistrationManagementTest extends AdminTestCase
{
    private function unit(): OrganizationalUnit
    {
        return OrganizationalUnit::query()->create([
            'name' => 'কেন্দ্রীয়', 'slug' => 'central-'.uniqid(), 'unit_type' => 'central', 'status' => 'active',
        ]);
    }

    private function committee(string $status = 'draft'): Committee
    {
        return Committee::query()->create([
            'organization_unit_id' => $this->unit()->id, 'name' => 'কমিটি '.uniqid(), 'status' => $status,
        ]);
    }

    private function position(Committee $committee, bool $allowDuplicates = false): CommitteePosition
    {
        return CommitteePosition::query()->create([
            'committee_id' => $committee->id, 'name' => 'সাধারণ সম্পাদক', 'slug' => 'gs-'.uniqid(),
            'display_order' => 0, 'allow_duplicates' => $allowDuplicates, 'status' => 'active',
        ]);
    }

    private function submission(Committee $committee, CommitteePosition $position, string $status = 'pending'): CommitteeSubmission
    {
        return CommitteeSubmission::query()->create([
            'committee_id' => $committee->id, 'committee_position_id' => $position->id,
            'full_name' => 'রফিক উদ্দিন', 'email' => 'rafiq-'.uniqid().'@example.com', 'phone' => '01800000000',
            'provatferi_comment' => 'প্রভাতফেরীর সাথে দীর্ঘদিন যুক্ত আছি।',
            'publishing_consent' => true, 'accuracy_declaration' => true,
            'status' => $status, 'submitted_at' => now(),
        ]);
    }

    /* ---------- Lifecycle (§19/§20) ---------- */

    public function test_activating_a_committee_demotes_the_previously_active_one(): void
    {
        $admin = $this->superAdmin();
        $first = $this->committee('active');
        $second = $this->committee('upcoming');

        $this->actingAs($admin)->patch(route('admin.committees.status', $second), ['status' => 'active'])
            ->assertRedirect();

        $this->assertSame('completed', $first->fresh()->status);
        $this->assertSame('active', $second->fresh()->status);
    }

    public function test_an_invalid_lifecycle_transition_is_rejected(): void
    {
        $admin = $this->superAdmin();
        $committee = $this->committee('draft');

        // draft -> archived is not an allowed jump per CommitteeController::TRANSITIONS.
        $this->actingAs($admin)->patch(route('admin.committees.status', $committee), ['status' => 'archived'])
            ->assertSessionHasErrors('status');

        $this->assertSame('draft', $committee->fresh()->status);
    }

    public function test_the_plain_edit_form_cannot_change_status(): void
    {
        // Regression guard: status must only ever change through the
        // dedicated lifecycle action, never a plain field edit, so
        // Committee::activate()'s single-Active invariant can't be bypassed.
        $admin = $this->superAdmin();
        $committee = $this->committee('active');

        $this->actingAs($admin)->put(route('admin.committees.update', $committee), [
            'name' => 'নতুন নাম', 'organization_unit_id' => $committee->organization_unit_id, 'status' => 'archived',
        ])->assertRedirect();

        $fresh = $committee->fresh();
        $this->assertSame('নতুন নাম', $fresh->name);
        $this->assertSame('active', $fresh->status, 'status must be untouched by the plain edit form');
    }

    public function test_a_new_committee_always_starts_as_draft_regardless_of_posted_status(): void
    {
        $admin = $this->superAdmin();

        $this->actingAs($admin)->post(route('admin.committees.store'), [
            'name' => 'টেস্ট কমিটি', 'organization_unit_id' => $this->unit()->id, 'status' => 'active',
        ])->assertRedirect();

        $this->assertSame('draft', Committee::query()->where('name', 'টেস্ট কমিটি')->firstOrFail()->status);
    }

    /* ---------- Positions (§21) ---------- */

    public function test_a_position_can_be_added_to_a_committee(): void
    {
        $admin = $this->superAdmin();
        $committee = $this->committee();

        $this->actingAs($admin)->post(route('admin.committees.positions.store', $committee), [
            'name' => 'কোষাধ্যক্ষ', 'display_order' => 1, 'status' => 'active',
        ])->assertRedirect();

        $this->assertDatabaseHas('committee_positions', ['committee_id' => $committee->id, 'name' => 'কোষাধ্যক্ষ']);
    }

    public function test_a_position_with_an_active_member_cannot_be_deleted(): void
    {
        $admin = $this->superAdmin();
        $committee = $this->committee();
        $position = $this->position($committee);
        $committee->members()->create(['committee_position_id' => $position->id, 'status' => 'active', 'start_date' => now()]);

        $this->actingAs($admin)->delete(route('admin.committees.positions.destroy', [$committee, $position]))
            ->assertSessionHas('error');

        $this->assertDatabaseHas('committee_positions', ['id' => $position->id]);
    }

    /* ---------- Registration links (§22) ---------- */

    public function test_a_registration_link_can_be_generated_and_points_to_the_public_site(): void
    {
        $admin = $this->superAdmin();
        $committee = $this->committee();

        $response = $this->actingAs($admin)->post(route('admin.committees.registration-links.store', $committee))
            ->assertRedirect();

        $link = CommitteeRegistrationLink::query()->where('committee_id', $committee->id)->firstOrFail();
        $this->assertNotNull($link->token_hash);
        $response->assertSessionHas('generated_registration_link', fn ($url) => str_contains($url, '/committee/register/'));
    }

    public function test_a_revoked_link_is_no_longer_valid(): void
    {
        $admin = $this->superAdmin();
        $committee = $this->committee();
        [$link, $raw] = CommitteeRegistrationLink::issue($committee, null, $admin);

        $this->assertNotNull(CommitteeRegistrationLink::findValidByRawToken($raw));

        $this->actingAs($admin)->patch(route('admin.committees.registration-links.revoke', [$committee, $link]))
            ->assertRedirect();

        $this->assertNull(CommitteeRegistrationLink::findValidByRawToken($raw));
    }

    /* ---------- Submission review (§26-28) ---------- */

    public function test_approving_a_submission_creates_a_committee_member_without_a_user_row(): void
    {
        $admin = $this->superAdmin();
        $committee = $this->committee();
        $position = $this->position($committee);
        $submission = $this->submission($committee, $position);

        $this->actingAs($admin)->patch(route('admin.committees.submissions.approve', [$committee, $submission]))
            ->assertRedirect();

        $this->assertSame('approved', $submission->fresh()->status);
        $member = CommitteeMember::query()->where('committee_submission_id', $submission->id)->firstOrFail();
        $this->assertNull($member->user_id);
        $this->assertSame($position->id, $member->committee_position_id);

        $entry = ApprovalHistory::query()->where('subject_type', CommitteeSubmission::class)->where('subject_id', $submission->id)->firstOrFail();
        $this->assertSame('approved', $entry->action);
    }

    public function test_approving_into_an_occupied_single_seat_position_warns_but_still_succeeds(): void
    {
        $admin = $this->superAdmin();
        $committee = $this->committee();
        $position = $this->position($committee, allowDuplicates: false);
        $committee->members()->create(['committee_position_id' => $position->id, 'status' => 'active', 'start_date' => now()]);

        $submission = $this->submission($committee, $position);

        $this->actingAs($admin)->patch(route('admin.committees.submissions.approve', [$committee, $submission]))
            ->assertRedirect()->assertSessionHas('warning');

        $this->assertSame('approved', $submission->fresh()->status);
        $this->assertSame(2, CommitteeMember::query()->where('committee_position_id', $position->id)->count());
    }

    public function test_rejecting_a_submission_requires_a_reason_and_creates_no_member(): void
    {
        $admin = $this->superAdmin();
        $committee = $this->committee();
        $position = $this->position($committee);
        $submission = $this->submission($committee, $position);

        $this->actingAs($admin)->patch(route('admin.committees.submissions.reject', [$committee, $submission]), [])
            ->assertSessionHasErrors('admin_note');

        $this->actingAs($admin)->patch(route('admin.committees.submissions.reject', [$committee, $submission]), [
            'admin_note' => 'তথ্য অসম্পূর্ণ।',
        ])->assertRedirect();

        $this->assertSame('rejected', $submission->fresh()->status);
        $this->assertSame(0, CommitteeMember::query()->where('committee_submission_id', $submission->id)->count());
    }

    public function test_requesting_correction_issues_a_link_and_a_terminal_status_blocks_further_review(): void
    {
        $admin = $this->superAdmin();
        $committee = $this->committee();
        $position = $this->position($committee);
        $submission = $this->submission($committee, $position);

        $response = $this->actingAs($admin)->patch(route('admin.committees.submissions.request-correction', [$committee, $submission]), [
            'admin_note' => 'ছবি স্পষ্ট নয়।',
        ])->assertRedirect();

        $submission->refresh();
        $this->assertSame('correction_requested', $submission->status);
        $response->assertSessionHas('generated_correction_link', fn ($url) => str_contains($url, '/committee/register/correct/'));

        // A rejected submission is terminal — no further transition is allowed.
        $rejected = $this->submission($committee, $position, 'rejected');
        $this->actingAs($admin)->patch(route('admin.committees.submissions.approve', [$committee, $rejected]))
            ->assertStatus(422);
    }

    /* ---------- RBAC ---------- */

    public function test_only_approve_permission_can_review_submissions(): void
    {
        $committee = $this->committee();
        $position = $this->position($committee);
        $submission = $this->submission($committee, $position);
        $viewer = $this->userWith(['organization.view']);

        $this->actingAs($viewer)->patch(route('admin.committees.submissions.approve', [$committee, $submission]))
            ->assertForbidden();
        $this->actingAs($viewer)->patch(route('admin.committees.status', $committee), ['status' => 'active'])
            ->assertForbidden();
    }
}
