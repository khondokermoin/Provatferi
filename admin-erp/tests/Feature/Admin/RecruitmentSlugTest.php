<?php

namespace Tests\Feature\Admin;

use App\Models\JobPosting;
use App\Models\JobPostingSlug;

/**
 * §3: the recruitment slug was Str::slug(title).'-'.Str::random(4) at
 * creation and never editable — the origin of the production URL
 * "prvatfereer-swecchasebee-time-zukt-hoozar-ahwan-0rkb". These pin the
 * replacement contract: a clean, admin-editable slug, and a permanent
 * history so an old shared link never breaks.
 */
class RecruitmentSlugTest extends AdminTestCase
{
    private function create(array $overrides = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->superAdmin())->post(route('admin.recruitment.store'), array_merge([
            'title' => 'স্বেচ্ছাসেবী সমন্বয়ক', 'description' => 'বিবরণ', 'status' => 'draft',
        ], $overrides));
    }

    public function test_a_blank_slug_is_generated_from_the_title_with_no_random_suffix(): void
    {
        // An ASCII title keeps the expected slug unambiguous. Bengali titles
        // are covered separately below — Str::slug() DOES transliterate them
        // (the buggy production slug was a phonetic romanization of a
        // Bengali title), but the exact transliteration isn't this test's
        // concern; "no random suffix" is.
        $this->create(['title' => 'Volunteer Coordinator'])->assertRedirect();

        $posting = JobPosting::query()->firstOrFail();
        $this->assertSame('volunteer-coordinator', $posting->slug);
    }

    public function test_a_bengali_title_produces_a_non_empty_transliterated_slug(): void
    {
        $this->create(['title' => 'স্বেচ্ছাসেবী সমন্বয়ক', 'slug' => ''])->assertRedirect();

        $posting = JobPosting::query()->firstOrFail();
        $this->assertNotSame('', $posting->slug);
        $this->assertMatchesRegularExpression('/^[a-z0-9]+(-[a-z0-9]+)*$/', $posting->slug);
    }

    public function test_an_admin_chosen_slug_is_used_as_given(): void
    {
        $this->create(['slug' => 'volunteer-team-call'])->assertRedirect();

        $this->assertDatabaseHas('job_postings', ['slug' => 'volunteer-team-call']);
    }

    public function test_two_postings_with_the_same_title_get_distinct_slugs(): void
    {
        $this->create(['title' => 'Coordinator'])->assertRedirect();
        $this->create(['title' => 'Coordinator'])->assertRedirect();

        $slugs = JobPosting::query()->pluck('slug')->sort()->values();
        $this->assertSame(['coordinator', 'coordinator-2'], $slugs->all());
    }

    public function test_a_slug_matching_a_route_segment_is_suffixed_not_used_verbatim(): void
    {
        $this->create(['slug' => 'apply'])->assertRedirect();

        // "apply" alone would collide with /recruitment/{slug}/apply.
        $this->assertDatabaseMissing('job_postings', ['slug' => 'apply']);
        $this->assertDatabaseHas('job_postings', ['slug' => 'apply-recruitment']);
    }

    public function test_an_empty_slug_field_does_not_fail_validation(): void
    {
        // A blank <input> submits as "", not an absent field — this is the
        // case the nullable-vs-empty-string fix in validated() covers.
        $this->create(['slug' => ''])->assertRedirect()->assertSessionMissing('errors');
    }

    public function test_changing_the_slug_on_update_retires_the_old_one_to_history(): void
    {
        $admin = $this->superAdmin();
        $this->actingAs($admin)->post(route('admin.recruitment.store'), [
            'title' => 'Role', 'slug' => 'old-name', 'description' => 'D', 'status' => 'open',
        ]);
        $posting = JobPosting::query()->firstOrFail();

        $this->actingAs($admin)->put(route('admin.recruitment.update', $posting), [
            'title' => 'Role', 'slug' => 'new-name', 'description' => 'D', 'status' => 'open',
        ])->assertRedirect();

        $fresh = $posting->fresh();
        $this->assertSame('new-name', $fresh->slug);
        $this->assertDatabaseHas('job_posting_slugs', ['job_posting_id' => $posting->id, 'slug' => 'old-name']);
    }

    public function test_leaving_the_slug_unchanged_on_update_creates_no_history_row(): void
    {
        $admin = $this->superAdmin();
        $this->actingAs($admin)->post(route('admin.recruitment.store'), [
            'title' => 'Role', 'slug' => 'steady-slug', 'description' => 'D', 'status' => 'open',
        ]);
        $posting = JobPosting::query()->firstOrFail();

        $this->actingAs($admin)->put(route('admin.recruitment.update', $posting), [
            'title' => 'Role Renamed', 'slug' => 'steady-slug', 'description' => 'D2', 'status' => 'open',
        ])->assertRedirect();

        $this->assertSame('steady-slug', $posting->fresh()->slug);
        $this->assertSame(0, JobPostingSlug::query()->count());
    }

    public function test_a_slug_already_retired_by_another_posting_cannot_be_reused(): void
    {
        $admin = $this->superAdmin();
        $this->actingAs($admin)->post(route('admin.recruitment.store'), [
            'title' => 'A', 'slug' => 'shared-name', 'description' => 'D', 'status' => 'open',
        ]);
        $first = JobPosting::query()->firstOrFail();
        // Retire "shared-name" by renaming the first posting away from it.
        $this->actingAs($admin)->put(route('admin.recruitment.update', $first), [
            'title' => 'A', 'slug' => 'shared-name-moved', 'description' => 'D', 'status' => 'open',
        ]);

        $this->actingAs($admin)->post(route('admin.recruitment.store'), [
            'title' => 'B', 'slug' => 'shared-name', 'description' => 'D', 'status' => 'open',
        ])->assertRedirect();

        // The second posting must NOT have been given the retired slug.
        $second = JobPosting::query()->where('title', 'B')->firstOrFail();
        $this->assertNotSame('shared-name', $second->slug);
    }
}
