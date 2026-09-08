<?php

namespace Tests\Feature\Admin;

use App\Models\Activity;
use App\Models\ActivityType;

class ActivityManagementTest extends AdminTestCase
{
    private function type(string $name = 'Book Reading'): ActivityType
    {
        return ActivityType::query()->create(['name' => $name, 'slug' => str($name)->slug(), 'status' => 'active', 'sort_order' => 1]);
    }

    /* ---------- Activity Types ---------- */

    public function test_activity_type_can_be_created_with_a_unique_slug(): void
    {
        $this->actingAs($this->superAdmin())->post(route('admin.activities.types.store'), [
            'name' => 'পাঠচক্র', 'status' => 'active', 'sort_order' => 1,
        ])->assertRedirect();

        $type = ActivityType::query()->firstOrFail();
        $this->assertNotEmpty($type->slug);
    }

    public function test_activity_type_used_by_an_activity_cannot_be_deleted(): void
    {
        $type = $this->type();
        Activity::query()->create(['activity_type_id' => $type->id, 'title' => 'X', 'slug' => 'x', 'status' => 'draft']);

        $this->actingAs($this->superAdmin())->delete(route('admin.activities.types.destroy', $type))
            ->assertRedirect()->assertSessionHas('error');

        $this->assertDatabaseHas('activity_types', ['id' => $type->id]);
    }

    public function test_activity_types_render_in_sort_order(): void
    {
        ActivityType::query()->create(['name' => 'Second', 'slug' => 'second', 'status' => 'active', 'sort_order' => 2]);
        ActivityType::query()->create(['name' => 'First', 'slug' => 'first', 'status' => 'active', 'sort_order' => 1]);

        $html = $this->actingAs($this->superAdmin())->get(route('admin.activities.types.index'))->getContent();
        $this->assertTrue(strpos($html, 'First') < strpos($html, 'Second'));
    }

    /* ---------- Activities CRUD ---------- */

    public function test_an_activity_can_be_created_as_draft_with_minimal_fields(): void
    {
        $type = $this->type();

        $this->actingAs($this->superAdmin())->post(route('admin.activities.store'), [
            'activity_type_id' => $type->id,
            'title' => 'বইপড়া কর্মসূচি',
            'status' => 'draft',
            'participant_count' => 0,
        ])->assertRedirect();

        $this->assertDatabaseHas('activities', ['title' => 'বইপড়া কর্মসূচি', 'status' => 'draft']);
    }

    public function test_publishing_without_summary_or_date_is_rejected(): void
    {
        $type = $this->type();

        $this->actingAs($this->superAdmin())->post(route('admin.activities.store'), [
            'activity_type_id' => $type->id,
            'title' => 'Incomplete',
            'status' => 'published',
            'participant_count' => 0,
        ])->assertSessionHasErrors(['summary', 'start_datetime']);

        $this->assertDatabaseMissing('activities', ['title' => 'Incomplete']);
    }

    public function test_publishing_with_all_required_fields_succeeds_and_stamps_published_at(): void
    {
        $type = $this->type();

        $this->actingAs($this->superAdmin())->post(route('admin.activities.store'), [
            'activity_type_id' => $type->id,
            'title' => 'Complete Activity',
            'summary' => 'A short summary.',
            'start_datetime' => now()->toDateTimeString(),
            'status' => 'published',
            'participant_count' => 25,
        ])->assertRedirect();

        $activity = Activity::query()->where('title', 'Complete Activity')->firstOrFail();
        $this->assertSame('published', $activity->status);
        $this->assertNotNull($activity->published_at);
    }

    public function test_archiving_preserves_the_original_published_at(): void
    {
        $type = $this->type();
        $publishedAt = now()->subDays(10);
        $activity = Activity::query()->create([
            'activity_type_id' => $type->id, 'title' => 'T', 'slug' => 't',
            'status' => 'published', 'published_at' => $publishedAt, 'participant_count' => 0,
        ]);

        $this->actingAs($this->superAdmin())->put(route('admin.activities.update', $activity), [
            'activity_type_id' => $type->id, 'title' => 'T', 'status' => 'archived', 'participant_count' => 0,
        ])->assertRedirect();

        $this->assertNotNull($activity->fresh()->published_at);
        // MySQL DATETIME columns carry no fractional seconds, so compare at
        // second precision rather than exact Carbon equality.
        $this->assertSame($publishedAt->format('Y-m-d H:i:s'), $activity->fresh()->published_at->format('Y-m-d H:i:s'));
    }

    public function test_reverting_to_draft_clears_published_at(): void
    {
        $type = $this->type();
        $activity = Activity::query()->create([
            'activity_type_id' => $type->id, 'title' => 'T', 'slug' => 't3',
            'status' => 'published', 'published_at' => now(), 'participant_count' => 0,
        ]);

        $this->actingAs($this->superAdmin())->put(route('admin.activities.update', $activity), [
            'activity_type_id' => $type->id, 'title' => 'T', 'status' => 'draft', 'participant_count' => 0,
        ])->assertRedirect();

        $this->assertNull($activity->fresh()->published_at);
    }

    public function test_featured_checkbox_resets_to_false_when_unchecked(): void
    {
        $type = $this->type();
        $activity = Activity::query()->create([
            'activity_type_id' => $type->id, 'title' => 'T', 'slug' => 't2',
            'status' => 'draft', 'featured' => true, 'participant_count' => 0,
        ]);

        // Submitting the form without the "featured" field, exactly as an
        // unchecked HTML checkbox would.
        $this->actingAs($this->superAdmin())->put(route('admin.activities.update', $activity), [
            'activity_type_id' => $type->id, 'title' => 'T', 'status' => 'draft', 'participant_count' => 0,
        ])->assertRedirect();

        $this->assertFalse($activity->fresh()->featured);
    }

    public function test_activities_can_be_filtered_by_status_and_type(): void
    {
        $typeA = $this->type('A');
        $typeB = $this->type('B');
        Activity::query()->create(['activity_type_id' => $typeA->id, 'title' => 'Draft One', 'slug' => 'd1', 'status' => 'draft', 'participant_count' => 0]);
        Activity::query()->create(['activity_type_id' => $typeB->id, 'title' => 'Published One', 'slug' => 'p1', 'status' => 'published', 'published_at' => now(), 'participant_count' => 0]);

        $admin = $this->superAdmin();
        $this->actingAs($admin)->get(route('admin.activities.index', ['status' => 'published']))
            ->assertOk()->assertSee('Published One')->assertDontSee('Draft One');

        $this->actingAs($admin)->get(route('admin.activities.index', ['type' => $typeA->id]))
            ->assertOk()->assertSee('Draft One')->assertDontSee('Published One');
    }

    public function test_rbac_guards_activity_management(): void
    {
        $viewer = $this->userWith(['activities.view']);
        $this->actingAs($viewer)->get(route('admin.activities.index'))->assertOk();
        $this->actingAs($viewer)->get(route('admin.activities.create'))->assertForbidden();
        $this->actingAs($viewer)->get(route('admin.activities.types.create'))->assertForbidden();

        $outsider = $this->userWith(['membership.view']);
        $this->actingAs($outsider)->get(route('admin.activities.index'))->assertForbidden();
    }

    public function test_bengali_activity_content_round_trips(): void
    {
        $type = $this->type();
        $text = 'দোল্লাই নোয়াবপুর সরকারি কলেজে পরিচ্ছন্নতা কর্মসূচি।';

        $this->actingAs($this->superAdmin())->post(route('admin.activities.store'), [
            'activity_type_id' => $type->id, 'title' => 'পরিচ্ছন্নতা কর্মসূচি', 'summary' => $text,
            'status' => 'draft', 'participant_count' => 0,
        ])->assertRedirect();

        $activity = Activity::query()->firstOrFail();
        $this->assertSame($text, $activity->summary);
        $this->assertTrue(mb_check_encoding($activity->summary, 'UTF-8'));
    }
}
