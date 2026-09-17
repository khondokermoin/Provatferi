<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\JobApplication;
use App\Models\JobPosting;
use Illuminate\Http\JsonResponse;

/**
 * Public read-only contract. Only `open` postings are listed or shown —
 * draft, closed and archived postings are all excluded.
 *
 * 2026-09-15: fields are now picked explicitly instead of serializing the
 * model, so dates are plain `Y-m-d` and labels come from the ERP's own label
 * maps. Volunteer roles never expose a salary, rolling calls never expose a
 * deadline, and `notice_slug` links a posting to its public notice.
 */
class JobPostingController extends Controller
{
    public function index(): JsonResponse
    {
        $jobs = JobPosting::query()
            ->where('status', 'open')
            ->with(['notice', 'organizationUnit:id,name'])
            ->orderByDesc('published_at')
            ->get();

        return response()->json(['data' => $jobs->map(fn (JobPosting $job) => $this->present($job))->values()]);
    }

    /** @return array<int, array{key: string, label: string}> */
    private function skillOptions(): array
    {
        return collect(JobApplication::SKILLS)
            ->map(fn (string $label, string $key) => ['key' => $key, 'label' => $label])
            ->values()
            ->all();
    }

    public function show(string $jobPosting): JsonResponse
    {
        $model = JobPosting::query()
            ->where('status', 'open')
            ->where(fn ($q) => $q->where('id', $jobPosting)->orWhere('slug', $jobPosting))
            ->with(['notice', 'organizationUnit:id,name'])
            ->firstOrFail();

        return response()->json(['data' => $this->present($model, true)]);
    }

    /** @return array<string, mixed> */
    private function present(JobPosting $job, bool $detailed = false): array
    {
        return [
            'id' => $job->id,
            'title' => $job->title,
            'slug' => $job->slug,
            'summary' => $job->summary,
            'department' => $job->department,
            'description' => $job->description,
            'requirements' => $job->requirements,
            'organization_unit' => $job->organizationUnit?->name,
            'employment_type' => $job->employment_type,
            'employment_type_label' => $job->employmentTypeLabel(),
            'is_volunteer' => $job->isVolunteer(),
            'volunteer_note' => $job->isVolunteer() ? JobPosting::VOLUNTEER_NOTE : null,
            'salary_range' => $job->isVolunteer() ? null : $job->salary_range,
            'application_mode' => $job->application_mode ?? 'fixed',
            'application_mode_label' => $job->applicationModeLabel(),
            'opening_date' => $job->opening_date?->toDateString(),
            'application_deadline' => $job->isRolling() ? null : $job->application_deadline?->toDateString(),
            'published_at' => $job->published_at?->toIso8601String(),
            'notice_slug' => $job->notice !== null && $job->notice->isPubliclyVisible() ? $job->notice->slug : null,
            // §10/§19: the website form is the primary way to apply, and its
            // path comes from the posting. The linked notice's own action
            // (the WhatsApp community group) rides along as the SECONDARY
            // channel — never as the application route.
            'accepts_applications' => $job->acceptsApplications(),
            'apply_path' => $job->applyPath(),
            'notice_action' => $job->notice !== null && $job->notice->isPubliclyVisible() && filled($job->notice->action_url)
                ? ['url' => $job->notice->action_url, 'label' => $job->notice->action_label ?: 'কমিউনিটি গ্রুপ']
                : null,
            // The skill catalogue only matters where the form is rendered.
            'skill_options' => $detailed && $job->acceptsApplications() ? $this->skillOptions() : null,
        ];
    }
}
