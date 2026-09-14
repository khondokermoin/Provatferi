<?php

namespace Tests\Feature\Api;

use App\Models\Notice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PublicNoticeApiTest extends TestCase
{
    use RefreshDatabase;

    /** @param array<string, mixed> $attributes */
    private function notice(array $attributes = []): Notice
    {
        return Notice::query()->create(array_merge([
            'title' => 'নোটিশ '.uniqid(),
            'slug' => 'notice-'.uniqid(),
            'notice_type' => 'general',
            'body' => 'বিবরণ',
            'status' => 'published',
            'published_at' => now()->subHour(),
        ], $attributes));
    }

    public function test_only_publicly_visible_notices_are_listed(): void
    {
        $this->notice(['title' => 'Published']);
        $this->notice(['title' => 'Archived', 'status' => 'archived', 'published_at' => now()->subYear()]);
        $this->notice(['title' => 'Due scheduled', 'status' => 'scheduled', 'published_at' => now()->subMinute()]);
        $this->notice(['title' => 'Future scheduled', 'status' => 'scheduled', 'published_at' => now()->addDay()]);
        $this->notice(['title' => 'Draft', 'status' => 'draft', 'published_at' => null]);
        $this->notice(['title' => 'Trashed'])->delete();

        $titles = collect($this->getJson('/api/v1/public/notices')->assertOk()->json('data'))->pluck('title');

        $this->assertEqualsCanonicalizing(['Published', 'Archived', 'Due scheduled'], $titles->all());
    }

    public function test_active_pins_come_first_then_newest(): void
    {
        $this->notice(['title' => 'Old pinned', 'published_at' => now()->subDays(10), 'is_pinned' => true]);
        $this->notice(['title' => 'Newest', 'published_at' => now()->subDay()]);
        $this->notice(['title' => 'Archived pinned', 'status' => 'archived', 'published_at' => now()->subDays(2), 'is_pinned' => true]);

        $titles = collect($this->getJson('/api/v1/public/notices')->json('data'))->pluck('title')->all();

        $this->assertSame(['Old pinned', 'Newest', 'Archived pinned'], $titles);
    }

    public function test_internal_fields_never_leave_the_erp(): void
    {
        $notice = $this->notice(['summary' => 'S']);
        $notice->forceFill(['attachment_path' => 'notices/attachments/x.pdf', 'cover_image_path' => 'notices/covers/x.jpg', 'created_by' => null])->save();

        foreach ([$this->getJson('/api/v1/public/notices')->json('data.0'), $this->getJson("/api/v1/public/notices/{$notice->slug}")->json('data')] as $payload) {
            foreach (['id', 'status', 'created_by', 'updated_by', 'attachment_path', 'cover_image_path', 'job_posting_id', 'syncs_from_job_posting', 'first_published_at', 'deleted_at'] as $key) {
                $this->assertArrayNotHasKey($key, $payload);
            }
        }
    }

    public function test_type_year_and_search_filters_with_facets(): void
    {
        $this->travelTo(Carbon::parse('2026-09-15 06:00:00', 'UTC'));
        $this->notice(['title' => 'স্বেচ্ছাসেবী আহ্বান', 'notice_type' => 'volunteer', 'published_at' => Carbon::parse('2026-09-01 06:00:00', 'UTC')]);
        $this->notice(['title' => 'বই সরবরাহের দরপত্র', 'notice_type' => 'tender', 'published_at' => Carbon::parse('2025-06-01 06:00:00', 'UTC')]);
        $this->notice(['title' => 'খসড়া', 'notice_type' => 'result', 'status' => 'draft', 'published_at' => null]);

        $this->getJson('/api/v1/public/notices?type=tender')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.notice_type', 'tender');
        $this->getJson('/api/v1/public/notices?year=2025')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/public/notices?q='.urlencode('দরপত্র'))->assertOk()->assertJsonCount(1, 'data');

        $response = $this->getJson('/api/v1/public/notices')->assertOk();
        $this->assertEqualsCanonicalizing(['volunteer', 'tender'], collect($response->json('filters.types'))->pluck('key')->all());
        $this->assertSame([2026, 2025], $response->json('filters.years'));
        $this->assertSame('দরপত্র', collect($response->json('filters.types'))->firstWhere('key', 'tender')['label']);
    }

    public function test_an_invalid_type_filter_is_rejected(): void
    {
        $this->getJson('/api/v1/public/notices?type=nonsense')->assertStatus(422);
    }

    public function test_detail_is_not_reachable_for_unpublished_notices(): void
    {
        $draft = $this->notice(['status' => 'draft', 'published_at' => null]);
        $future = $this->notice(['status' => 'scheduled', 'published_at' => now()->addDay()]);

        $this->getJson("/api/v1/public/notices/{$draft->slug}")->assertNotFound();
        $this->getJson("/api/v1/public/notices/{$future->slug}")->assertNotFound();
    }

    public function test_bengali_body_and_new_marker_round_trip(): void
    {
        $body = "প্রভাতফেরী সাহিত্য ও সাংস্কৃতিক কেন্দ্র\n\n• দরপত্র • ফলাফল";
        $recent = $this->notice(['body' => $body, 'published_at' => now()->subDay()]);
        $old = $this->notice(['published_at' => now()->subDays(30)]);

        $returned = $this->getJson("/api/v1/public/notices/{$recent->slug}")->assertOk()
            ->assertJsonPath('data.is_new', true)
            ->assertJsonPath('data.notice_type_label', 'সাধারণ বিজ্ঞপ্তি')
            ->json('data.body');
        $this->assertSame($body, $returned);
        $this->assertTrue(mb_check_encoding($returned, 'UTF-8'));

        $this->getJson("/api/v1/public/notices/{$old->slug}")->assertJsonPath('data.is_new', false);
    }

    public function test_sitemap_lists_only_visible_slugs(): void
    {
        $visible = $this->notice();
        $this->notice(['status' => 'draft', 'published_at' => null]);

        $slugs = collect($this->getJson('/api/v1/public/notices/sitemap')->assertOk()->json('data'))->pluck('slug')->all();

        $this->assertSame([$visible->slug], $slugs);
    }
}
