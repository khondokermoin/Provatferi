<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\JobApplication;
use App\Models\JobPosting;
use App\Models\JobPostingSlug;
use App\Services\RecruitmentFileService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

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
    public function __construct(private readonly RecruitmentFileService $files)
    {
    }

    public function index(): JsonResponse
    {
        $jobs = JobPosting::query()
            ->where('status', 'open')
            ->with(['notice', 'organizationUnit:id,name,name_en'])
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
            ->with(['notice', 'organizationUnit:id,name,name_en'])
            ->first();

        // §3: the requested value may be a slug this posting used to have.
        // present() always reads $job->slug (the current column), so the
        // caller can tell a resolved-via-history lookup apart from a direct
        // one just by comparing the segment it requested against the "slug"
        // the response comes back with — no separate field is needed.
        if ($model === null) {
            $historical = JobPostingSlug::query()->where('slug', $jobPosting)->first();
            if ($historical !== null) {
                $model = JobPosting::query()
                    ->where('status', 'open')
                    ->where('id', $historical->job_posting_id)
                    ->with(['notice', 'organizationUnit:id,name,name_en'])
                    ->first();
            }
        }

        abort_if($model === null, 404);

        return response()->json(['data' => $this->present($model, true)]);
    }

    /** @return array<string, mixed> */
    private function present(JobPosting $job, bool $detailed = false): array
    {
        return [
            'id' => $job->id,
            'title' => $job->title,
            'title_en' => $job->title_en,
            'slug' => $job->slug,
            'summary' => $job->summary,
            'summary_en' => $job->summary_en,
            'department' => $job->department,
            'description' => $job->description,
            'description_en' => $job->description_en,
            'requirements' => $job->requirements,
            'requirements_en' => $job->requirements_en,
            'organization_unit' => $job->organizationUnit?->name,
            'organization_unit_en' => $job->organizationUnit?->name_en,
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
                ? [
                    'url' => $job->notice->action_url,
                    'label' => $job->notice->action_label ?: 'কমিউনিটি গ্রুপ',
                    'label_en' => $job->notice->action_label_en,
                ]
                : null,
            // The skill catalogue only matters where the form is rendered.
            'skill_options' => $detailed && $job->acceptsApplications() ? $this->skillOptions() : null,
            // Required/optional per field for this posting — rides along with
            // skill_options (detail view + form open only) so the public form
            // can show the right indicator and Laravel's own validation
            // (built from the same resolvedFieldRequirements()) is always the
            // authority; this is presentation only, never trusted for its own sake.
            'field_requirements' => $detailed && $job->acceptsApplications() ? $job->resolvedFieldRequirements() : null,
            'share_image_url' => $this->shareImageUrl($job),
        ];
    }

    /**
     * §12/§13 share-image priority: 1) this posting's own uploaded image,
     * 2) its linked notice's own share image, 3) that notice's cover image,
     * 4) null — the caller falls back to the global brand mark, so og:image
     * is never empty. A closed-off or unpublished linked notice is skipped
     * entirely, same visibility rule notice_action already applies.
     */
    private function shareImageUrl(JobPosting $job): ?string
    {
        if ($job->share_image_path) {
            return route('api.job-postings.share', $job->slug);
        }

        $notice = $job->notice;
        if ($notice === null || ! $notice->isPubliclyVisible()) {
            return null;
        }
        if ($notice->share_image_path) {
            return route('api.public.notices.share', $notice->slug);
        }
        if ($notice->cover_image_path) {
            return route('api.public.notices.cover', $notice->slug);
        }

        return null;
    }

    /** §12: public, unauthenticated — mirrors PublicNoticeController::cover()/shareImage(). */
    public function shareImage(string $jobPosting): StreamedResponse
    {
        $job = JobPosting::query()
            ->where('status', 'open')
            ->where(fn ($q) => $q->where('id', $jobPosting)->orWhere('slug', $jobPosting))
            ->firstOrFail();

        abort_unless($job->share_image_path, 404);
        $extension = pathinfo($job->share_image_path, PATHINFO_EXTENSION);

        return $this->files->response($job->share_image_path, "{$job->slug}-share.{$extension}", $this->files->mime($job->share_image_path));
    }
}
