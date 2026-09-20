<?php

namespace Tests\Feature\Api;

use App\Models\JobPosting;
use App\Models\Notice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * §12/§13: share_image_url on both public read contracts, and the priority
 * chain on JobPosting specifically — its own upload, then its linked
 * notice's own share image, then that notice's cover image, then null (the
 * Next.js caller applies the global brand mark, so og:image is never empty).
 *
 * share_image_path is deliberately absent from both models' $fillable (same
 * reasoning as cover_image_path: a raw storage path is never settable from
 * mass-assigned request data) — every fixture below sets it via a direct
 * property assignment + save(), the same path the real controllers use.
 */
class ShareImageContractTest extends TestCase
{
    use RefreshDatabase;

    private function publishedNotice(array $overrides = []): Notice
    {
        return Notice::query()->create(array_merge([
            'title' => 'N', 'slug' => 'n-'.uniqid(), 'notice_type' => 'general', 'body' => 'B',
            'status' => 'published', 'published_at' => now(),
        ], $overrides));
    }

    private function openPosting(array $overrides = []): JobPosting
    {
        return JobPosting::query()->create(array_merge([
            'title' => 'P', 'slug' => 'p-'.uniqid(), 'description' => 'D', 'status' => 'open',
        ], $overrides));
    }

    public function test_a_notice_with_no_share_image_reports_null(): void
    {
        $notice = $this->publishedNotice();

        $this->getJson('/api/v1/public/notices/'.$notice->slug)
            ->assertOk()->assertJsonPath('data.share_image_url', null);
    }

    public function test_a_notice_with_a_share_image_reports_its_url(): void
    {
        $notice = $this->publishedNotice();
        $notice->share_image_path = 'notices/share/x.jpg';
        $notice->save();

        $this->getJson('/api/v1/public/notices/'.$notice->slug)
            ->assertOk()
            ->assertJsonPath('data.share_image_url', route('api.public.notices.share', $notice->slug));
    }

    public function test_a_posting_prefers_its_own_share_image_over_its_notices(): void
    {
        $posting = $this->openPosting();
        $posting->share_image_path = 'job_postings/share/own.jpg';
        $posting->save();

        $notice = $this->publishedNotice(['job_posting_id' => $posting->id]);
        $notice->share_image_path = 'notices/share/notice.jpg';
        $notice->save();

        $this->getJson('/api/v1/job-postings/'.$posting->slug)
            ->assertOk()
            ->assertJsonPath('data.share_image_url', route('api.job-postings.share', $posting->slug));
    }

    public function test_a_posting_without_its_own_image_falls_back_to_its_notices_share_image(): void
    {
        $posting = $this->openPosting();
        $notice = $this->publishedNotice(['job_posting_id' => $posting->id]);
        $notice->share_image_path = 'notices/share/notice.jpg';
        $notice->save();

        $this->getJson('/api/v1/job-postings/'.$posting->slug)
            ->assertOk()
            ->assertJsonPath('data.share_image_url', route('api.public.notices.share', $notice->slug));
    }

    public function test_a_posting_falls_back_to_its_notices_cover_image_when_neither_has_a_share_image(): void
    {
        $posting = $this->openPosting();
        $notice = $this->publishedNotice(['job_posting_id' => $posting->id]);
        $notice->cover_image_path = 'notices/covers/notice.jpg';
        $notice->save();

        $this->getJson('/api/v1/job-postings/'.$posting->slug)
            ->assertOk()
            ->assertJsonPath('data.share_image_url', route('api.public.notices.cover', $notice->slug));
    }

    public function test_a_posting_with_no_images_anywhere_reports_null(): void
    {
        $posting = $this->openPosting();

        $this->getJson('/api/v1/job-postings/'.$posting->slug)
            ->assertOk()->assertJsonPath('data.share_image_url', null);
    }

    public function test_a_postings_own_image_is_preferred_even_when_its_notice_is_not_publicly_visible(): void
    {
        $posting = $this->openPosting();
        $posting->share_image_path = 'job_postings/share/own.jpg';
        $posting->save();
        // A draft notice must never be consulted at all, own image or not.
        $this->publishedNotice(['job_posting_id' => $posting->id, 'status' => 'draft', 'published_at' => null]);

        $this->getJson('/api/v1/job-postings/'.$posting->slug)
            ->assertOk()
            ->assertJsonPath('data.share_image_url', route('api.job-postings.share', $posting->slug));
    }

    public function test_a_draft_notices_images_are_never_used_as_a_postings_fallback(): void
    {
        $posting = $this->openPosting();
        $notice = $this->publishedNotice(['job_posting_id' => $posting->id, 'status' => 'draft', 'published_at' => null]);
        $notice->share_image_path = 'notices/share/notice.jpg';
        $notice->save();

        $this->getJson('/api/v1/job-postings/'.$posting->slug)
            ->assertOk()->assertJsonPath('data.share_image_url', null);
    }

    public function test_the_public_notice_share_image_route_404s_when_the_notice_is_not_visible(): void
    {
        $notice = $this->publishedNotice(['status' => 'draft', 'published_at' => null]);
        $notice->share_image_path = 'notices/share/x.jpg';
        $notice->save();

        $this->get(route('api.public.notices.share', $notice->slug))->assertNotFound();
    }

    public function test_the_public_job_posting_share_image_route_404s_when_the_posting_is_not_open(): void
    {
        $posting = $this->openPosting(['status' => 'draft']);
        $posting->share_image_path = 'job_postings/share/x.jpg';
        $posting->save();

        $this->get(route('api.job-postings.share', $posting->slug))->assertNotFound();
    }
}
