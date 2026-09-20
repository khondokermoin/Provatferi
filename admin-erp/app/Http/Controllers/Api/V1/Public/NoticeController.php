<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Controller;
use App\Models\JobPosting;
use App\Models\Notice;
use App\Services\NoticeFileService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Public notice board contract, consumed server-side by provatferi.org.
 * Every field is picked explicitly — internal columns (status, authorship,
 * storage paths, sync flags, ids) never leave the ERP.
 */
class NoticeController extends Controller
{
    public function __construct(private readonly NoticeFileService $files)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => ['nullable', Rule::in(array_keys(Notice::TYPES))],
            'year' => ['nullable', 'integer', 'between:2000,2100'],
            'q' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'between:1,50'],
        ]);
        $now = now();

        $page = Notice::query()
            ->publiclyVisible()
            ->when($data['type'] ?? null, fn (Builder $q, string $type) => $q->where('notice_type', $type))
            ->when($data['year'] ?? null, function (Builder $q, $year) {
                // Year boundaries are Bangladesh midnights, not UTC ones.
                $start = Carbon::create((int) $year, 1, 1, 0, 0, 0, Notice::DISPLAY_TIMEZONE)->utc();
                $q->where('published_at', '>=', $start)->where('published_at', '<', $start->copy()->addYear());
            })
            ->when(filled($data['q'] ?? null), function (Builder $q) use ($data) {
                $term = '%'.addcslashes($data['q'], '%_\\').'%';
                $q->where(fn (Builder $w) => $w->where('title', 'like', $term)->orWhere('summary', 'like', $term));
            })
            ->orderByRaw("CASE WHEN is_pinned = 1 AND status <> 'archived' AND (expires_at IS NULL OR expires_at > ?) THEN 0 ELSE 1 END", [$now])
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->paginate((int) ($data['per_page'] ?? 20))
            ->withQueryString();

        $typeCounts = Notice::query()->publiclyVisible()
            ->selectRaw('notice_type, COUNT(*) AS total')
            ->groupBy('notice_type')
            ->pluck('total', 'notice_type');

        $years = Notice::query()->publiclyVisible()->pluck('published_at')
            ->map(fn (Carbon $date) => (int) $date->copy()->timezone(Notice::DISPLAY_TIMEZONE)->year)
            ->unique()
            ->sortDesc()
            ->values();

        return response()->json([
            'data' => $page->getCollection()->map(fn (Notice $notice) => $this->summary($notice))->values(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
            'filters' => [
                'types' => collect(Notice::TYPES)
                    ->map(fn (string $label, string $key) => ['key' => $key, 'label' => $label, 'count' => (int) ($typeCounts[$key] ?? 0)])
                    ->filter(fn (array $type) => $type['count'] > 0)
                    ->values(),
                'years' => $years,
            ],
        ]);
    }

    public function show(string $slug): JsonResponse
    {
        $notice = Notice::query()
            ->publiclyVisible()
            ->where('slug', $slug)
            ->with(['organizationUnit:id,name', 'jobPosting'])
            ->firstOrFail();

        return response()->json(['data' => [
            ...$this->summary($notice),
            'body' => $notice->body,
            'organization_unit' => $notice->organizationUnit?->name,
            'action' => filled($notice->action_url)
                ? ['url' => $notice->action_url, 'label' => $notice->action_label ?: 'বিস্তারিত দেখুন']
                : null,
            'cover_image_url' => $notice->cover_image_path ? route('api.public.notices.cover', $notice->slug) : null,
            // §12/§13: the DEDICATED Open Graph image, when the admin uploaded
            // one — never the cover image, which may be a different aspect
            // ratio entirely. null here means "no dedicated image"; the caller
            // (generateMetadata) falls back to cover_image_url, then the
            // global brand mark, so og:image is never empty.
            'share_image_url' => $notice->share_image_path ? route('api.public.notices.share', $notice->slug) : null,
            'attachment' => $notice->attachment_path
                ? ['url' => route('api.public.notices.attachment', $notice->slug), 'size' => $notice->attachment_size, 'mime' => $notice->attachment_mime]
                : null,
            'recruitment' => $this->recruitment($notice->jobPosting),
            'updated_at' => $notice->updated_at?->toIso8601String(),
        ]]);
    }

    public function sitemap(): JsonResponse
    {
        $notices = Notice::query()->publiclyVisible()->orderByDesc('published_at')->limit(5000)->get(['slug', 'updated_at']);

        return response()->json([
            'data' => $notices->map(fn (Notice $notice) => ['slug' => $notice->slug, 'updated_at' => $notice->updated_at?->toIso8601String()])->values(),
        ]);
    }

    public function attachment(string $slug): StreamedResponse
    {
        $notice = Notice::query()->publiclyVisible()->where('slug', $slug)->firstOrFail();
        abort_unless($notice->attachment_path, 404);

        return $this->files->response($notice->attachment_path, "{$notice->slug}.pdf", $notice->attachment_mime ?? 'application/pdf', false);
    }

    public function cover(string $slug): StreamedResponse
    {
        $notice = Notice::query()->publiclyVisible()->where('slug', $slug)->firstOrFail();
        abort_unless($notice->cover_image_path, 404);
        $extension = pathinfo($notice->cover_image_path, PATHINFO_EXTENSION);

        return $this->files->response($notice->cover_image_path, "{$notice->slug}-cover.{$extension}", $this->files->coverMime($notice->cover_image_path), true);
    }

    public function shareImage(string $slug): StreamedResponse
    {
        $notice = Notice::query()->publiclyVisible()->where('slug', $slug)->firstOrFail();
        abort_unless($notice->share_image_path, 404);
        $extension = pathinfo($notice->share_image_path, PATHINFO_EXTENSION);

        return $this->files->response($notice->share_image_path, "{$notice->slug}-share.{$extension}", $this->files->coverMime($notice->share_image_path), true);
    }

    /** @return array<string, mixed> */
    private function summary(Notice $notice): array
    {
        return [
            'slug' => $notice->slug,
            'title' => $notice->title,
            'notice_type' => $notice->notice_type,
            'notice_type_label' => $notice->typeLabel(),
            'summary' => $notice->summary,
            'published_at' => $notice->published_at?->toIso8601String(),
            'expires_at' => $notice->expires_at?->toIso8601String(),
            'is_pinned' => $notice->isActivelyPinned(),
            'is_new' => $notice->isNew(),
            'is_expired' => $notice->isExpired(),
            'is_archived' => $notice->status === 'archived',
        ];
    }

    /**
     * Employment terms are read live from the posting, never copied onto the
     * notice. A draft posting exposes nothing; only an open posting gets a
     * slug, since the public recruitment page only resolves open postings.
     *
     * @return array<string, mixed>|null
     */
    private function recruitment(?JobPosting $job): ?array
    {
        if ($job === null || ! in_array($job->status, ['open', 'closed'], true)) {
            return null;
        }

        return [
            'slug' => $job->status === 'open' ? $job->slug : null,
            'title' => $job->title,
            'employment_type_label' => $job->employmentTypeLabel(),
            'is_volunteer' => $job->isVolunteer(),
            'volunteer_note' => $job->isVolunteer() ? JobPosting::VOLUNTEER_NOTE : null,
            'salary_range' => $job->isVolunteer() ? null : $job->salary_range,
            'application_mode' => $job->application_mode,
            'application_mode_label' => $job->applicationModeLabel(),
            'opening_date' => $job->opening_date?->toDateString(),
            'application_deadline' => $job->isRolling() ? null : $job->application_deadline?->toDateString(),
            'is_open' => $job->status === 'open',
            // §9/§19: the notice's primary CTA is the website form, and the
            // path is derived from the posting rather than written into
            // notice content by hand.
            'accepts_applications' => $job->acceptsApplications(),
            'apply_path' => $job->applyPath(),
        ];
    }
}
