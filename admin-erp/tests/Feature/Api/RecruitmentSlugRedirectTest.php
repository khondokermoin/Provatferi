<?php

namespace Tests\Feature\Api;

use App\Models\JobPosting;
use App\Models\JobPostingSlug;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * §3: the public read contract must keep resolving a retired slug — this is
 * what lets institutional/app/(site)/recruitment/[slug]/page.tsx tell "old
 * URL" apart from "unknown URL" and issue a 308 rather than a 404.
 */
class RecruitmentSlugRedirectTest extends TestCase
{
    use RefreshDatabase;

    private function posting(array $overrides = []): JobPosting
    {
        return JobPosting::query()->create(array_merge([
            'title' => 'Role', 'slug' => 'current-name', 'description' => 'D', 'status' => 'open',
        ], $overrides));
    }

    public function test_the_current_slug_resolves_directly(): void
    {
        $this->posting();

        $this->getJson('/api/v1/job-postings/current-name')
            ->assertOk()
            ->assertJsonPath('data.slug', 'current-name');
    }

    public function test_a_retired_slug_resolves_through_history_to_the_canonical_record(): void
    {
        $posting = $this->posting();
        JobPostingSlug::query()->create(['job_posting_id' => $posting->id, 'slug' => 'old-name']);

        // The response always carries the CURRENT slug, whichever segment was
        // requested — this is the signal the Next.js route redirects on.
        $this->getJson('/api/v1/job-postings/old-name')
            ->assertOk()
            ->assertJsonPath('data.slug', 'current-name')
            ->assertJsonPath('data.id', $posting->id);
    }

    public function test_a_slug_that_was_never_used_still_404s(): void
    {
        $this->getJson('/api/v1/job-postings/never-existed-'.uniqid())->assertNotFound();
    }

    public function test_a_retired_slug_does_not_resolve_once_the_posting_is_no_longer_open(): void
    {
        $posting = $this->posting(['status' => 'closed']);
        JobPostingSlug::query()->create(['job_posting_id' => $posting->id, 'slug' => 'old-name']);

        $this->getJson('/api/v1/job-postings/old-name')->assertNotFound();
    }
}
