<?php

namespace Tests\Feature\Admin;

use App\Models\JobPosting;
use App\Models\Notice;

class RecruitmentNoticeIntegrationTest extends AdminTestCase
{
    /** @param array<string, mixed> $overrides */
    private function volunteerPayload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'প্রভাতফেরীর স্বেচ্ছাসেবী টিমে যুক্ত হওয়ার আহ্বান',
            'description' => 'দীর্ঘমেয়াদে যুক্ত থাকার আহ্বান।',
            'requirements' => 'নির্দিষ্ট শিক্ষাগত যোগ্যতা বাধ্যতামূলক নয়।',
            'employment_type' => 'volunteer',
            'application_mode' => 'rolling',
            'status' => 'open',
        ], $overrides);
    }

    public function test_publishing_to_the_notice_board_creates_exactly_one_linked_notice(): void
    {
        $admin = $this->superAdmin();
        $this->actingAs($admin)->post(route('admin.recruitment.store'), $this->volunteerPayload(['publish_to_notice_board' => '1']))
            ->assertRedirect()->assertSessionHasNoErrors();

        $job = JobPosting::query()->firstOrFail();
        $notice = Notice::query()->firstOrFail();
        $this->assertSame($job->id, $notice->job_posting_id);
        $this->assertSame('volunteer', $notice->notice_type);
        $this->assertSame('published', $notice->status);
        $this->assertTrue($notice->syncs_from_job_posting);
        $this->assertStringContainsString('যোগ্যতা:', $notice->body);

        $this->actingAs($admin)->put(route('admin.recruitment.update', $job), $this->volunteerPayload(['publish_to_notice_board' => '1']))
            ->assertRedirect();
        $this->assertDatabaseCount('notices', 1);
    }

    public function test_posting_edits_flow_into_the_notice_while_sync_is_on(): void
    {
        $admin = $this->superAdmin();
        $this->actingAs($admin)->post(route('admin.recruitment.store'), $this->volunteerPayload(['publish_to_notice_board' => '1']));
        $job = JobPosting::query()->firstOrFail();

        $this->actingAs($admin)->put(route('admin.recruitment.update', $job), $this->volunteerPayload(['title' => 'হালনাগাদ শিরোনাম']));

        $this->assertSame('হালনাগাদ শিরোনাম', Notice::query()->firstOrFail()->title);
    }

    public function test_hand_editing_the_notice_copy_stops_sync_so_it_is_never_overwritten(): void
    {
        $admin = $this->superAdmin();
        $this->actingAs($admin)->post(route('admin.recruitment.store'), $this->volunteerPayload(['publish_to_notice_board' => '1']));
        $job = JobPosting::query()->firstOrFail();
        $notice = Notice::query()->firstOrFail();

        $this->actingAs($admin)->put(route('admin.notices.update', $notice), [
            'title' => 'নিজস্ব শিরোনাম',
            'notice_type' => 'volunteer',
            'summary' => $notice->summary,
            'body' => $notice->body,
            'status' => 'published',
            'published_at' => Notice::toLocalInput($notice->published_at),
            'syncs_from_job_posting' => '1',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertFalse($notice->fresh()->syncs_from_job_posting);

        $this->actingAs($admin)->put(route('admin.recruitment.update', $job), $this->volunteerPayload(['title' => 'পরে বদলানো শিরোনাম']));

        $this->assertSame('নিজস্ব শিরোনাম', $notice->fresh()->title);
    }

    public function test_a_volunteer_role_stores_and_exposes_no_salary(): void
    {
        $this->actingAs($this->superAdmin())->post(route('admin.recruitment.store'), $this->volunteerPayload(['salary_range' => '৳0']))
            ->assertSessionHasNoErrors();

        $job = JobPosting::query()->firstOrFail();
        $this->assertNull($job->salary_range);

        $this->getJson("/api/v1/job-postings/{$job->slug}")->assertOk()
            ->assertJsonPath('data.salary_range', null)
            ->assertJsonPath('data.is_volunteer', true)
            ->assertJsonPath('data.volunteer_note', JobPosting::VOLUNTEER_NOTE)
            ->assertJsonPath('data.employment_type_label', 'স্বেচ্ছাসেবী');
    }

    public function test_rolling_mode_never_keeps_a_deadline(): void
    {
        $this->actingAs($this->superAdmin())->post(route('admin.recruitment.store'), $this->volunteerPayload([
            'opening_date' => '2026-09-15',
            'application_deadline' => '2026-12-31',
        ]))->assertSessionHasNoErrors();

        $job = JobPosting::query()->firstOrFail();
        $this->assertNull($job->application_deadline);

        $this->getJson("/api/v1/job-postings/{$job->slug}")->assertOk()
            ->assertJsonPath('data.application_mode', 'rolling')
            ->assertJsonPath('data.application_mode_label', 'চলমান')
            ->assertJsonPath('data.application_deadline', null);
    }

    public function test_the_checkbox_does_nothing_without_notice_permission(): void
    {
        $recruiter = $this->userWith(['recruitment.view', 'recruitment.create']);

        $this->actingAs($recruiter)->post(route('admin.recruitment.store'), $this->volunteerPayload([
            'status' => 'draft', 'publish_to_notice_board' => '1',
        ]))->assertRedirect();

        $this->assertDatabaseCount('job_postings', 1);
        $this->assertDatabaseCount('notices', 0);
    }

    public function test_the_admin_posting_page_shows_labels_not_internal_keys(): void
    {
        $job = JobPosting::query()->create([
            'title' => 'স্বেচ্ছাসেবী', 'slug' => 'volunteer-role', 'description' => 'D',
            'employment_type' => 'volunteer', 'application_mode' => 'rolling', 'status' => 'open',
        ]);

        $this->actingAs($this->superAdmin())->get(route('admin.recruitment.show', $job))->assertOk()
            ->assertSee(JobPosting::VOLUNTEER_NOTE)
            ->assertSee('আবেদন চলমান')
            ->assertDontSee('>volunteer<', false);
    }

    public function test_a_linked_notice_exposes_live_recruitment_terms(): void
    {
        $this->actingAs($this->superAdmin())->post(route('admin.recruitment.store'), $this->volunteerPayload(['publish_to_notice_board' => '1']));
        $job = JobPosting::query()->firstOrFail();
        $notice = Notice::query()->firstOrFail();

        $this->getJson("/api/v1/public/notices/{$notice->slug}")->assertOk()
            ->assertJsonPath('data.recruitment.slug', $job->slug)
            ->assertJsonPath('data.recruitment.is_volunteer', true)
            ->assertJsonPath('data.recruitment.application_mode_label', 'চলমান')
            ->assertJsonPath('data.recruitment.salary_range', null);

        $this->getJson("/api/v1/job-postings/{$job->slug}")->assertJsonPath('data.notice_slug', $notice->slug);
    }

    /**
     * Replays the exact production sequence that 500'd on 2026-09-14: posting
     * saved as a draft with the notice box ticked (so the linked notice is a
     * dateless draft), the notice then given its own URL/text/CTA and
     * published with the date left empty, and only then the posting opened.
     */
    public function test_the_linked_draft_flow_publishes_a_customised_notice_that_the_posting_never_overwrites(): void
    {
        $admin = $this->superAdmin();
        $this->actingAs($admin)->post(route('admin.recruitment.store'), $this->volunteerPayload([
            'status' => 'draft', 'publish_to_notice_board' => '1',
        ]))->assertRedirect()->assertSessionHasNoErrors();

        $job = JobPosting::query()->firstOrFail();
        $notice = Notice::query()->firstOrFail();
        $this->assertSame('draft', $notice->status);
        $this->assertNull($notice->published_at);

        $this->actingAs($admin)->put(route('admin.notices.update', $notice), [
            'title' => $notice->title,
            'slug' => 'volunteer-team-call',
            'notice_type' => 'volunteer',
            'summary' => $notice->summary,
            'body' => "সম্পূর্ণ নোটিশের লেখা।\n\nhttps://chat.whatsapp.com/JRJpeNjFVzbFeJf1d9luEb",
            'action_url' => 'https://chat.whatsapp.com/JRJpeNjFVzbFeJf1d9luEb',
            'action_label' => 'স্বেচ্ছাসেবী হিসেবে যুক্ত হোন',
            'status' => 'published',
            'published_at' => '',
            'organization_unit_id' => '',
            'syncs_from_job_posting' => '1',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $notice->refresh();
        $this->assertSame('published', $notice->status);
        $this->assertFalse($notice->syncs_from_job_posting);

        $this->actingAs($admin)->put(route('admin.recruitment.update', $job), $this->volunteerPayload(['status' => 'open']))
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertStringStartsWith('সম্পূর্ণ নোটিশের লেখা।', $notice->fresh()->body);
        $this->getJson("/api/v1/job-postings/{$job->slug}")->assertOk()->assertJsonPath('data.notice_slug', 'volunteer-team-call');
        $this->getJson('/api/v1/public/notices/volunteer-team-call')->assertOk()
            ->assertJsonPath('data.recruitment.slug', $job->slug)
            ->assertJsonPath('data.recruitment.application_mode_label', 'চলমান');
    }
}
