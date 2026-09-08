<?php

namespace Tests\Feature\Api;

use App\Models\Activity;
use App\Models\ActivityType;
use App\Models\JobPosting;
use App\Models\MembershipType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicPhase1cApiTest extends TestCase
{
    use RefreshDatabase;

    /* ---------- Activities ---------- */

    public function test_activities_index_returns_published_only(): void
    {
        $type = ActivityType::query()->create(['name' => 'T', 'slug' => 't', 'status' => 'active']);
        $published = Activity::query()->create([
            'activity_type_id' => $type->id, 'title' => 'Published', 'slug' => 'published-one',
            'status' => 'published', 'published_at' => now(), 'summary' => 'S',
            'start_datetime' => now(), 'participant_count' => 10,
        ]);
        Activity::query()->create([
            'activity_type_id' => $type->id, 'title' => 'Draft', 'slug' => 'draft-one', 'status' => 'draft',
        ]);
        Activity::query()->create([
            'activity_type_id' => $type->id, 'title' => 'Archived', 'slug' => 'archived-one',
            'status' => 'archived', 'published_at' => now()->subMonth(),
        ]);

        $response = $this->getJson('/api/v1/activities')->assertOk();
        $titles = collect($response->json('data'))->pluck('title');

        $this->assertContains('Published', $titles);
        $this->assertNotContains('Draft', $titles);
        $this->assertNotContains('Archived', $titles);
    }

    public function test_activity_show_accepts_both_id_and_slug(): void
    {
        $type = ActivityType::query()->create(['name' => 'T', 'slug' => 't', 'status' => 'active']);
        $activity = Activity::query()->create([
            'activity_type_id' => $type->id, 'title' => 'Findable', 'slug' => 'findable-activity',
            'status' => 'published', 'published_at' => now(),
        ]);

        $this->getJson("/api/v1/activities/{$activity->id}")->assertOk()->assertJsonPath('data.title', 'Findable');
        $this->getJson('/api/v1/activities/findable-activity')->assertOk()->assertJsonPath('data.title', 'Findable');
    }

    public function test_draft_activity_is_not_reachable_by_show(): void
    {
        $type = ActivityType::query()->create(['name' => 'T', 'slug' => 't', 'status' => 'active']);
        $activity = Activity::query()->create([
            'activity_type_id' => $type->id, 'title' => 'Hidden', 'slug' => 'hidden-activity', 'status' => 'draft',
        ]);

        $this->getJson("/api/v1/activities/{$activity->id}")->assertNotFound();
        $this->getJson('/api/v1/activities/hidden-activity')->assertNotFound();
    }

    public function test_activity_response_exposes_gallery_and_related_links_as_arrays_never_null(): void
    {
        $type = ActivityType::query()->create(['name' => 'T', 'slug' => 't', 'status' => 'active']);
        Activity::query()->create([
            'activity_type_id' => $type->id, 'title' => 'A', 'slug' => 'a-activity',
            'status' => 'published', 'published_at' => now(),
        ]);

        $response = $this->getJson('/api/v1/activities')->assertOk();
        $first = $response->json('data.0');

        $this->assertIsArray($first['gallery']);
        $this->assertIsArray($first['related_links']);
    }

    public function test_activity_response_does_not_expose_admin_only_metadata(): void
    {
        $type = ActivityType::query()->create(['name' => 'T', 'slug' => 't', 'status' => 'active']);
        Activity::query()->create([
            'activity_type_id' => $type->id, 'title' => 'A', 'slug' => 'a2-activity',
            'status' => 'published', 'published_at' => now(), 'created_by' => null,
        ]);

        $response = $this->getJson('/api/v1/activities')->assertOk();
        $first = $response->json('data.0');

        $this->assertArrayNotHasKey('created_by', $first);
        $this->assertArrayNotHasKey('deleted_at', $first);
    }

    /* ---------- Membership Types ---------- */

    public function test_membership_types_index_returns_active_only_in_sort_order(): void
    {
        MembershipType::query()->create(['name' => 'Second', 'slug' => 'second', 'status' => 'active', 'sort_order' => 2, 'fee' => 0]);
        MembershipType::query()->create(['name' => 'First', 'slug' => 'first', 'status' => 'active', 'sort_order' => 1, 'fee' => 0]);
        MembershipType::query()->create(['name' => 'Hidden', 'slug' => 'hidden', 'status' => 'inactive', 'sort_order' => 0, 'fee' => 0]);

        $response = $this->getJson('/api/v1/membership-types')->assertOk();
        $names = collect($response->json('data'))->pluck('name');

        $this->assertSame(['First', 'Second'], $names->all());
    }

    /* ---------- Job Postings ---------- */

    public function test_job_postings_index_returns_open_only(): void
    {
        JobPosting::query()->create(['title' => 'Open', 'slug' => 'open-job', 'description' => 'D', 'status' => 'open', 'published_at' => now()]);
        JobPosting::query()->create(['title' => 'Draft', 'slug' => 'draft-job', 'description' => 'D', 'status' => 'draft']);
        JobPosting::query()->create(['title' => 'Closed', 'slug' => 'closed-job', 'description' => 'D', 'status' => 'closed']);
        JobPosting::query()->create(['title' => 'Archived', 'slug' => 'archived-job', 'description' => 'D', 'status' => 'archived']);

        $response = $this->getJson('/api/v1/job-postings')->assertOk();
        $titles = collect($response->json('data'))->pluck('title');

        $this->assertSame(['Open'], $titles->all());
    }

    public function test_job_posting_show_accepts_both_id_and_slug(): void
    {
        $job = JobPosting::query()->create(['title' => 'Findable Job', 'slug' => 'findable-job', 'description' => 'D', 'status' => 'open']);

        $this->getJson("/api/v1/job-postings/{$job->id}")->assertOk()->assertJsonPath('data.title', 'Findable Job');
        $this->getJson('/api/v1/job-postings/findable-job')->assertOk()->assertJsonPath('data.title', 'Findable Job');
    }

    public function test_archived_job_posting_is_not_reachable_by_show(): void
    {
        $job = JobPosting::query()->create(['title' => 'Gone', 'slug' => 'gone-job', 'description' => 'D', 'status' => 'archived']);

        $this->getJson("/api/v1/job-postings/{$job->id}")->assertNotFound();
    }

    /* ---------- Bengali round trip ---------- */

    public function test_bengali_content_round_trips_through_activities_api(): void
    {
        $type = ActivityType::query()->create(['name' => 'সাহিত্য', 'slug' => 'sahitto', 'status' => 'active']);
        $title = 'দোল্লাই নোয়াবপুরে বইপড়া কর্মসূচি';
        Activity::query()->create([
            'activity_type_id' => $type->id, 'title' => $title, 'slug' => 'bengali-activity',
            'status' => 'published', 'published_at' => now(),
        ]);

        $response = $this->getJson('/api/v1/activities')->assertOk();
        $returnedTitle = $response->json('data.0.title');

        $this->assertSame($title, $returnedTitle);
        $this->assertTrue(mb_check_encoding($returnedTitle, 'UTF-8'));
    }
}
