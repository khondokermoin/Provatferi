<?php

namespace Tests\Feature\Admin;

use App\Models\JobApplication;
use App\Models\JobPosting;

class RecruitmentManagementTest extends AdminTestCase
{
    public function test_a_job_posting_can_be_created_as_draft(): void
    {
        $this->actingAs($this->superAdmin())->post(route('admin.recruitment.store'), [
            'title' => 'লাইব্রেরিয়ান', 'description' => 'লাইব্রেরি পরিচালনার দায়িত্ব।', 'status' => 'draft',
        ])->assertRedirect();

        $this->assertDatabaseHas('job_postings', ['title' => 'লাইব্রেরিয়ান', 'status' => 'draft']);
    }

    public function test_publishing_stamps_published_at_and_draft_clears_it(): void
    {
        $admin = $this->superAdmin();
        $this->actingAs($admin)->post(route('admin.recruitment.store'), [
            'title' => 'Coordinator', 'description' => 'D', 'status' => 'open',
        ]);
        $posting = JobPosting::query()->firstOrFail();
        $this->assertNotNull($posting->published_at);

        $this->actingAs($admin)->put(route('admin.recruitment.update', $posting), [
            'title' => 'Coordinator', 'description' => 'D', 'status' => 'draft',
        ])->assertRedirect();

        $this->assertNull($posting->fresh()->published_at);
    }

    public function test_a_posting_with_applications_cannot_be_deleted(): void
    {
        $posting = JobPosting::query()->create(['title' => 'X', 'slug' => 'x', 'description' => 'D', 'status' => 'open']);
        JobApplication::query()->create([
            'application_no' => 'JA-1', 'job_posting_id' => $posting->id,
            'applicant_name' => 'Test Applicant', 'applicant_email' => 'a@example.test', 'status' => 'submitted',
        ]);

        $this->actingAs($this->superAdmin())->delete(route('admin.recruitment.destroy', $posting))
            ->assertRedirect()->assertSessionHas('error');

        $this->assertDatabaseHas('job_postings', ['id' => $posting->id]);
    }

    public function test_a_posting_without_applications_can_be_deleted(): void
    {
        $posting = JobPosting::query()->create(['title' => 'Y', 'slug' => 'y', 'description' => 'D', 'status' => 'draft']);

        $this->actingAs($this->superAdmin())->delete(route('admin.recruitment.destroy', $posting))->assertRedirect();

        $this->assertSoftDeleted($posting);
    }

    public function test_job_postings_can_be_filtered_by_status(): void
    {
        JobPosting::query()->create(['title' => 'Open Role', 'slug' => 'open-role', 'description' => 'D', 'status' => 'open']);
        JobPosting::query()->create(['title' => 'Archived Role', 'slug' => 'archived-role', 'description' => 'D', 'status' => 'archived']);

        $this->actingAs($this->superAdmin())->get(route('admin.recruitment.index', ['status' => 'archived']))
            ->assertOk()->assertSee('Archived Role')->assertDontSee('Open Role');
    }

    /* ---------- Application review workflow ---------- */

    private function application(string $status = 'submitted'): JobApplication
    {
        $posting = JobPosting::query()->create(['title' => 'Role', 'slug' => 'role-'.uniqid(), 'description' => 'D', 'status' => 'open']);

        return JobApplication::query()->create([
            'application_no' => 'JA-'.uniqid(), 'job_posting_id' => $posting->id,
            'applicant_name' => 'নাদিয়া ইসলাম', 'applicant_email' => 'nadia@example.test', 'status' => $status,
        ]);
    }

    public function test_an_application_can_be_shortlisted(): void
    {
        $application = $this->application();

        $this->actingAs($this->superAdmin())->patch(route('admin.recruitment.applications.status', $application), [
            'status' => 'shortlisted', 'interview_notes' => 'ভালো প্রোফাইল।',
        ])->assertRedirect();

        $fresh = $application->fresh();
        $this->assertSame('shortlisted', $fresh->status);
        $this->assertSame('ভালো প্রোফাইল।', $fresh->interview_notes);
    }

    public function test_an_application_can_be_rejected(): void
    {
        $application = $this->application();

        $this->actingAs($this->superAdmin())->patch(route('admin.recruitment.applications.status', $application), [
            'status' => 'rejected',
        ])->assertRedirect();

        $this->assertSame('rejected', $application->fresh()->status);
    }

    public function test_applications_can_be_filtered_by_posting(): void
    {
        $a = $this->application();
        $b = $this->application();

        $this->actingAs($this->superAdmin())
            ->get(route('admin.recruitment.applications.index', ['posting' => $a->job_posting_id]))
            ->assertOk()->assertSee($a->applicant_name);
    }

    /* ---------- RBAC ---------- */

    public function test_only_approve_permission_can_review_applications(): void
    {
        $application = $this->application();
        $viewer = $this->userWith(['recruitment.view']);

        $this->actingAs($viewer)->patch(route('admin.recruitment.applications.status', $application), ['status' => 'shortlisted'])
            ->assertForbidden();
    }

    public function test_rbac_guards_job_posting_management(): void
    {
        $viewer = $this->userWith(['recruitment.view']);
        $this->actingAs($viewer)->get(route('admin.recruitment.index'))->assertOk();
        $this->actingAs($viewer)->get(route('admin.recruitment.create'))->assertForbidden();

        $outsider = $this->userWith(['activities.view']);
        $this->actingAs($outsider)->get(route('admin.recruitment.index'))->assertForbidden();
    }

    public function test_bengali_applicant_name_round_trips(): void
    {
        $application = $this->application();
        $this->assertTrue(mb_check_encoding($application->applicant_name, 'UTF-8'));

        $this->actingAs($this->superAdmin())->get(route('admin.recruitment.applications.show', $application))
            ->assertOk()->assertSee($application->applicant_name, false);
    }
}
