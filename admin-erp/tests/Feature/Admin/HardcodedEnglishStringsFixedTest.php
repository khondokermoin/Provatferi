<?php

namespace Tests\Feature\Admin;

use App\Models\Activity;
use App\Models\ActivityType;
use App\Models\MembershipType;
use App\Models\Role;

/**
 * Phase 3 Step 5: targeted regression coverage for the specific hardcoded
 * English literals the final bidirectional scan found sitting directly in
 * Blade views (bypassing __() entirely, so they showed the same English word
 * even under the Bangla admin locale). Each of these now follows the admin's
 * locale like every other UI string — these tests prove that by checking
 * both directions: the Bangla word appears under `bn`, and the (different)
 * English word appears under `en`.
 */
class HardcodedEnglishStringsFixedTest extends AdminTestCase
{
    private function admin(string $locale): \App\Models\User
    {
        $admin = $this->superAdmin();
        $admin->update(['ui_locale' => $locale]);

        return $admin;
    }

    public function test_mission_page_title_and_preview_badge_follow_the_admin_locale(): void
    {
        $this->actingAs($this->admin('bn'))
            ->get(route('admin.content.mission.edit'))
            ->assertOk()->assertSee('লক্ষ্য');

        $this->actingAs($this->admin('en'))
            ->get(route('admin.content.mission.edit'))
            ->assertOk()->assertSee('Mission')->assertDontSee('লক্ষ্য');
    }

    public function test_vision_page_title_and_preview_badge_follow_the_admin_locale(): void
    {
        $this->actingAs($this->admin('bn'))
            ->get(route('admin.content.vision.edit'))
            ->assertOk()->assertSee('দৃষ্টিভঙ্গি');

        $this->actingAs($this->admin('en'))
            ->get(route('admin.content.vision.edit'))
            ->assertOk()->assertSee('Vision')->assertDontSee('দৃষ্টিভঙ্গি');
    }

    public function test_role_system_and_custom_badges_follow_the_admin_locale(): void
    {
        Role::query()->create(['name' => 'Scoped Reviewer', 'slug' => 'scoped-reviewer-'.uniqid(), 'is_system_role' => false]);

        $this->actingAs($this->admin('bn'))
            ->get(route('admin.roles.index'))
            ->assertOk()->assertSee('সিস্টেম')->assertSee('কাস্টম');

        $this->actingAs($this->admin('en'))
            ->get(route('admin.roles.index'))
            ->assertOk()->assertSee('System')->assertSee('Custom');
    }

    public function test_membership_type_student_badge_follows_the_admin_locale(): void
    {
        MembershipType::query()->create([
            'name' => 'শিক্ষার্থী সদস্যপদ', 'slug' => 'student-'.uniqid(), 'is_student' => true, 'fee' => 0, 'status' => 'active', 'sort_order' => 1,
        ]);

        $this->actingAs($this->admin('bn'))
            ->get(route('admin.membership.types.index'))
            ->assertOk()->assertSee('শিক্ষার্থী');

        $this->actingAs($this->admin('en'))
            ->get(route('admin.membership.types.index'))
            ->assertOk()->assertSee('Student');
    }

    public function test_activity_featured_badge_follows_the_admin_locale(): void
    {
        $type = ActivityType::query()->create(['name' => 'পাঠচক্র', 'slug' => 'type-'.uniqid(), 'status' => 'active']);
        Activity::query()->create([
            'activity_type_id' => $type->id, 'title' => 'কার্যক্রম', 'slug' => 'activity-'.uniqid(),
            'status' => 'draft', 'participant_count' => 0, 'featured' => true,
        ]);

        $this->actingAs($this->admin('bn'))
            ->get(route('admin.activities.index'))
            ->assertOk()->assertSee('ফিচার্ড');

        $this->actingAs($this->admin('en'))
            ->get(route('admin.activities.index'))
            ->assertOk()->assertSee('Featured');
    }

    public function test_send_password_reset_button_follows_the_admin_locale(): void
    {
        $target = $this->superAdmin();

        $this->actingAs($this->admin('bn'))
            ->get(route('admin.users.show', $target))
            ->assertOk()->assertSee('পাসওয়ার্ড রিসেট পাঠান');

        $this->actingAs($this->admin('en'))
            ->get(route('admin.users.show', $target))
            ->assertOk()->assertSee('Send password reset');
    }
}
