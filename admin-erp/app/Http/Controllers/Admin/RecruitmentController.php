<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\JobPosting;
use App\Models\OrganizationalUnit;
use App\Services\NoticeRecruitmentLinker;
use App\Services\RecruitmentFileService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class RecruitmentController extends Controller
{
    public function __construct(
        private readonly NoticeRecruitmentLinker $linker,
        private readonly RecruitmentFileService $files,
    ) {
    }

    public function index(Request $request): View
    {
        $filters = [
            'search' => trim((string) $request->query('search', '')),
            'status' => (string) $request->query('status', ''),
        ];

        $jobPostings = JobPosting::query()
            ->withCount('applications')
            ->when($filters['search'] !== '', fn ($q) => $q->where('title', 'like', '%'.$filters['search'].'%'))
            ->when($filters['status'] !== '', fn ($q) => $q->where('status', $filters['status']))
            ->latest()
            ->paginate(15)
            ->withQueryString();

        return view('admin.recruitment.index', [
            'title' => 'চাকরির বিজ্ঞপ্তি',
            'breadcrumbs' => [['label' => 'নিয়োগ'], ['label' => 'চাকরির বিজ্ঞপ্তি']],
            'jobPostings' => $jobPostings,
            'filters' => $filters,
            'statuses' => JobPosting::STATUSES,
        ]);
    }

    public function create(): View
    {
        return view('admin.recruitment.form', [
            'title' => 'নতুন চাকরির বিজ্ঞপ্তি',
            'breadcrumbs' => [['label' => 'চাকরির বিজ্ঞপ্তি', 'route' => 'admin.recruitment.index'], ['label' => 'তৈরি করুন']],
            'jobPosting' => new JobPosting(['status' => 'draft', 'application_mode' => 'fixed']),
            ...$this->formOptions(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        // §3: a blank slug field falls back to the title, exactly like a
        // fresh Notice does — but the result is now a readable, admin-chosen
        // URL rather than title-plus-four-random-characters.
        $data['slug'] = JobPosting::uniqueSlug(($data['slug'] ?? null) ?: $data['title']);
        $data['created_by'] = $request->user()->id;
        $data = $this->stampPublishedAt($data);

        // §12: uploaded and validated BEFORE the model is built, so a bad
        // file fails the whole request rather than leaving a half-created
        // posting behind. share_image_path is deliberately not mass-assigned
        // — like Notice's cover_image_path, a raw storage path is never
        // settable straight from $fillable/request data, only from this
        // upload path.
        $newShare = $this->uploadShareImage($request);

        $jobPosting = new JobPosting($data);
        if ($newShare !== null) {
            $jobPosting->share_image_path = $newShare;
        }
        $jobPosting->save();

        $note = $this->publishToNoticeBoard($request, $jobPosting);

        return redirect()->route('admin.recruitment.show', $jobPosting)->with('success', "\u{201c}{$jobPosting->title}\u{201d} তৈরি হয়েছে।".$note);
    }

    public function show(JobPosting $jobPosting): View
    {
        $jobPosting->loadCount('applications')->load(['organizationUnit', 'notice']);

        return view('admin.recruitment.show', [
            'title' => $jobPosting->title,
            'breadcrumbs' => [['label' => 'চাকরির বিজ্ঞপ্তি', 'route' => 'admin.recruitment.index'], ['label' => $jobPosting->title]],
            'jobPosting' => $jobPosting,
        ]);
    }

    public function edit(JobPosting $jobPosting): View
    {
        $jobPosting->load('notice');

        return view('admin.recruitment.form', [
            'title' => 'সম্পাদনা — '.$jobPosting->title,
            'breadcrumbs' => [
                ['label' => 'চাকরির বিজ্ঞপ্তি', 'route' => 'admin.recruitment.index'],
                ['label' => $jobPosting->title, 'route' => 'admin.recruitment.show', 'params' => $jobPosting],
                ['label' => 'সম্পাদনা'],
            ],
            'jobPosting' => $jobPosting,
            ...$this->formOptions(),
        ]);
    }

    public function update(Request $request, JobPosting $jobPosting): RedirectResponse
    {
        $data = $this->validated($request);
        $data = $this->stampPublishedAt($data, $jobPosting);

        // §3: the slug is now editable — but the URL it replaces must keep
        // working. The retiring slug is written to permanent history BEFORE
        // the new one is saved, and only when it actually changed (a no-op
        // edit that leaves the field untouched must not create a history row).
        $newSlug = JobPosting::uniqueSlug(($data['slug'] ?? null) ?: $jobPosting->slug, $jobPosting->id);
        if ($newSlug !== $jobPosting->slug) {
            $jobPosting->slugHistory()->create(['slug' => $jobPosting->slug]);
        }
        $data['slug'] = $newSlug;

        // §12: same upload-before-fill ordering as store(), plus the
        // replace/remove handling — a new upload wins over a ticked "remove"
        // box, matching how Notice's cover image behaves.
        $newShare = $this->uploadShareImage($request);
        $oldShare = $jobPosting->share_image_path;

        $jobPosting->fill($data);
        if ($newShare !== null) {
            $jobPosting->share_image_path = $newShare;
        } elseif ($request->boolean('remove_share_image')) {
            $jobPosting->share_image_path = null;
        }
        $jobPosting->save();

        // Only after the new state is persisted: drop a file nothing references any more.
        if ($oldShare !== null && $oldShare !== $jobPosting->share_image_path) {
            $this->files->delete($oldShare);
        }

        $this->linker->syncFrom($jobPosting, $request->user());
        $note = $this->publishToNoticeBoard($request, $jobPosting);

        return redirect()->route('admin.recruitment.show', $jobPosting)->with('success', "\u{201c}{$jobPosting->title}\u{201d} হালনাগাদ হয়েছে।".$note);
    }

    /**
     * §12: private-disk share image for this posting, streamed only to a
     * signed-in admin holding recruitment.view — mirrors
     * NoticeController::file()'s cover branch exactly.
     */
    public function shareImage(JobPosting $jobPosting): StreamedResponse
    {
        abort_unless($jobPosting->share_image_path, 404);
        $extension = pathinfo($jobPosting->share_image_path, PATHINFO_EXTENSION);

        return $this->files->response(
            $jobPosting->share_image_path,
            "{$jobPosting->slug}-share.{$extension}",
            $this->files->mime($jobPosting->share_image_path),
        );
    }

    /** @throws ValidationException when a file was provided but failed validation */
    private function uploadShareImage(Request $request): ?string
    {
        if (! $request->hasFile('share_image')) {
            return null;
        }

        try {
            return $this->files->storeShareImage($request->file('share_image'));
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['share_image' => $e->getMessage()]);
        }
    }

    public function destroy(JobPosting $jobPosting): RedirectResponse
    {
        if ($jobPosting->applications()->exists()) {
            return back()->with('error', 'এই বিজ্ঞপ্তিতে আবেদন জমা পড়েছে — মুছে ফেলার বদলে "Archived" করুন।');
        }

        $title = $jobPosting->title;
        $jobPosting->delete();

        return redirect()->route('admin.recruitment.index')->with('success', "\u{201c}{$title}\u{201d} মুছে ফেলা হয়েছে।");
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        // §3: a blank slug field means "auto-generate from the title", which
        // requires the value to be genuinely absent for the `nullable` rule
        // to take effect — an empty string is not null to Laravel's
        // validator, so left as-is it would fail the slug regex instead.
        if ($request->input('slug') === '') {
            $request->merge(['slug' => null]);
        }

        $data = $request->validate($this->rules(), [], $this->attributes());
        $data['application_mode'] = $data['application_mode'] ?? 'fixed';

        // A rolling call has no closing date; keeping a stale one would render as a fake deadline.
        if ($data['application_mode'] === 'rolling') {
            $data['application_deadline'] = null;
        }
        // A volunteer role never shows a salary, so none is stored either.
        if (($data['employment_type'] ?? null) === 'volunteer') {
            $data['salary_range'] = null;
        }

        // An unticked checkbox is absent from the request entirely, so the
        // flag is read from the request rather than the validated payload —
        // otherwise turning it off would silently leave it on.
        $data['accepts_applications'] = $request->boolean('accepts_applications');

        // §Application Form Settings: only known keys are ever stored — a
        // stray key could never reach here from this form anyway (it isn't
        // in $this->rules()'s field_requirements.* validation), but this
        // guards the case of a hand-crafted request too. A value equal to
        // the class default is kept as-is rather than pruned: an explicit
        // "required" that happens to match the default is still a real,
        // intentional admin choice, not noise.
        $data['field_requirements'] = array_intersect_key(
            $request->input('field_requirements', []),
            JobPosting::CONFIGURABLE_APPLICATION_FIELDS,
        );

        unset($data['publish_to_notice_board']);

        return $data;
    }

    /** Creates the linked notice at most once; unticking later never deletes a notice. */
    private function publishToNoticeBoard(Request $request, JobPosting $jobPosting): string
    {
        if (! $request->boolean('publish_to_notice_board') || ! $request->user()->can('notices.create')) {
            return '';
        }
        if ($jobPosting->notice()->exists()) {
            return '';
        }

        $notice = $this->linker->createFor($jobPosting, $request->user());

        return $notice->isPubliclyVisible()
            ? ' নোটিশ বোর্ডেও প্রকাশিত হয়েছে।'
            : ' নোটিশ বোর্ডে খসড়া হিসেবে যুক্ত হয়েছে।';
    }

    /** @return array<string, mixed> */
    private function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            // §3: lowercase-dash form only — uniqueSlug() re-slugifies it
            // anyway, but rejecting an obviously-wrong value here (spaces,
            // uppercase, punctuation) gives the admin an error next to the
            // field instead of a silently-transformed result.
            'slug' => ['nullable', 'string', 'max:80', 'regex:/^[a-z0-9]+(-[a-z0-9]+)*$/'],
            'summary' => ['nullable', 'string', 'max:500'],
            'organization_unit_id' => ['nullable', Rule::exists('organizational_units', 'id')],
            'department' => ['nullable', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:10000'],
            'requirements' => ['nullable', 'string', 'max:5000'],
            'employment_type' => ['nullable', Rule::in(array_keys(JobPosting::EMPLOYMENT_TYPES))],
            'salary_range' => ['nullable', 'string', 'max:255'],
            'opening_date' => ['nullable', 'date'],
            'application_mode' => ['nullable', Rule::in(array_keys(JobPosting::APPLICATION_MODES))],
            'application_deadline' => ['nullable', 'date', 'after_or_equal:opening_date'],
            'accepts_applications' => ['nullable', 'boolean'],
            'status' => ['required', Rule::in(array_keys(JobPosting::STATUSES))],
            'field_requirements' => ['nullable', 'array'],
            'field_requirements.*' => ['nullable', 'string', Rule::in(['required', 'optional'])],
            'publish_to_notice_board' => ['nullable', 'boolean'],
            // §12: same rule shape as Notice's cover_image — PhotoUploadService
            // enforces JPG/PNG/WEBP and the 5 MB limit inside storeShareImage().
            'share_image' => ['nullable', 'file', 'max:5120'],
            'remove_share_image' => ['nullable', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    private function attributes(): array
    {
        return [
            'title' => 'শিরোনাম', 'slug' => 'ইউআরএল স্লাগ', 'description' => 'বিবরণ', 'status' => 'স্ট্যাটাস',
            'application_mode' => 'আবেদনের পদ্ধতি', 'share_image' => 'সামাজিক শেয়ার ছবি',
        ];
    }

    /** @param array<string, mixed> $data */
    private function stampPublishedAt(array $data, ?JobPosting $jobPosting = null): array
    {
        $wasOpen = in_array($jobPosting?->status, ['open', 'closed'], true);
        if ($data['status'] === 'open' && ! $wasOpen) {
            $data['published_at'] = now();
        } elseif ($data['status'] === 'draft') {
            $data['published_at'] = null;
        }

        return $data;
    }

    /** @return array<string, mixed> */
    private function formOptions(): array
    {
        return [
            'units' => OrganizationalUnit::query()->orderBy('name')->pluck('name', 'id')->all(),
            'statuses' => JobPosting::STATUSES,
            'employmentTypes' => JobPosting::EMPLOYMENT_TYPES,
            'applicationModes' => JobPosting::APPLICATION_MODES,
            'configurableFields' => JobPosting::CONFIGURABLE_APPLICATION_FIELDS,
            'fieldRequirementOptions' => ['required' => 'আবশ্যক', 'optional' => 'ঐচ্ছিক'],
        ];
    }
}
