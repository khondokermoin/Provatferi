<?php

namespace Tests\Feature\Admin;

use App\Models\MembershipApplication;
use App\Models\MembershipSeason;
use App\Models\MembershipType;
use App\Models\User;

class MembershipSeasonManagementTest extends AdminTestCase
{
    public function test_index_create_and_edit_pages_render_without_error(): void
    {
        $admin = $this->superAdmin();
        $season = MembershipSeason::query()->create([
            'name' => 'রেন্ডার টেস্ট', 'slug' => 'render-test', 'campaign_type' => 'regular',
            'status' => 'open', 'display_order' => 0, 'opens_at' => now()->subDay(), 'closes_at' => now()->addDay(),
        ]);

        $this->actingAs($admin)->get(route('admin.membership.seasons.index'))->assertOk()->assertSee($season->name);
        $this->actingAs($admin)->get(route('admin.membership.seasons.create'))->assertOk();
        $this->actingAs($admin)->get(route('admin.membership.seasons.edit', $season))->assertOk()->assertSee($season->name);
    }

    public function test_a_season_can_be_created_with_allowed_membership_types(): void
    {
        $admin = $this->superAdmin();
        $type = MembershipType::query()->create([
            'name' => 'সাধারণ সদস্য', 'slug' => 'general', 'fee' => 500, 'status' => 'active',
        ]);

        $this->actingAs($admin)->post(route('admin.membership.seasons.store'), [
            'name' => '২০২৬ প্রথম সিজন',
            'campaign_type' => 'regular',
            'status' => 'draft',
            'display_order' => 0,
            'membership_type_ids' => [$type->id],
        ])->assertRedirect(route('admin.membership.seasons.index'));

        $season = MembershipSeason::query()->where('name', '২০২৬ প্রথম সিজন')->firstOrFail();
        $this->assertSame('draft', $season->status);
        $this->assertTrue($season->membershipTypes->contains($type));
        $this->assertNotEmpty($season->slug, 'a slug must be generated automatically, never typed by the admin');
    }

    public function test_status_changes_are_explicit_and_never_inferred_automatically(): void
    {
        $admin = $this->superAdmin();
        $season = MembershipSeason::query()->create([
            'name' => 'বিশেষ সিজন', 'slug' => 'special-test', 'campaign_type' => 'special',
            'status' => 'scheduled', 'opens_at' => now()->subDay(), 'display_order' => 0,
        ]);

        // Dates alone never flip status — only an explicit admin action does.
        $this->assertSame('scheduled', $season->fresh()->status);
        $this->assertSame('open', $season->suggestedStatusFromDates(), 'the schedule should suggest open, but must not apply it');

        $this->actingAs($admin)->patch(route('admin.membership.seasons.status', $season), ['status' => 'open'])
            ->assertRedirect();

        $this->assertSame('open', $season->fresh()->status);
    }

    public function test_a_season_with_applications_cannot_be_deleted(): void
    {
        $admin = $this->superAdmin();
        $type = MembershipType::query()->create(['name' => 'সাধারণ', 'slug' => 'general-2', 'fee' => 0, 'status' => 'active']);
        $season = MembershipSeason::query()->create([
            'name' => 'সিজন', 'slug' => 'season-with-apps', 'campaign_type' => 'regular', 'status' => 'open', 'display_order' => 0,
        ]);
        MembershipApplication::query()->create([
            'application_no' => 'APP-TEST-1', 'membership_type_id' => $type->id, 'membership_season_id' => $season->id,
            'applicant_name' => 'Test Applicant', 'applicant_email' => 'a@example.com', 'applicant_phone' => '01700000000',
            'status' => 'pending',
        ]);

        $this->actingAs($admin)->delete(route('admin.membership.seasons.destroy', $season))
            ->assertRedirect()->assertSessionHas('error');

        $this->assertNotNull($season->fresh());
    }

    public function test_acceptsApplicationsNow_requires_open_status_and_the_current_date_within_range(): void
    {
        $open = MembershipSeason::query()->create([
            'name' => 'Open', 'slug' => 'open-season', 'campaign_type' => 'regular', 'status' => 'open', 'display_order' => 0,
            'opens_at' => now()->subDay(), 'closes_at' => now()->addDay(),
        ]);
        $this->assertTrue($open->acceptsApplicationsNow());

        $draft = MembershipSeason::query()->create([
            'name' => 'Draft', 'slug' => 'draft-season', 'campaign_type' => 'regular', 'status' => 'draft', 'display_order' => 0,
        ]);
        $this->assertFalse($draft->acceptsApplicationsNow(), 'draft must never accept applications regardless of dates');

        $notYetOpen = MembershipSeason::query()->create([
            'name' => 'Future', 'slug' => 'future-season', 'campaign_type' => 'regular', 'status' => 'open', 'display_order' => 0,
            'opens_at' => now()->addDay(),
        ]);
        $this->assertFalse($notYetOpen->acceptsApplicationsNow());
    }

    public function test_a_user_without_membership_permission_is_denied(): void
    {
        $user = $this->userWith(['organization.view']);

        $this->actingAs($user)->get(route('admin.membership.seasons.index'))->assertForbidden();
    }
}
