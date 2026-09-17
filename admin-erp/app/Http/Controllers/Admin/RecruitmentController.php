<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\JobPosting;
use App\Models\OrganizationalUnit;
use App\Services\NoticeRecruitmentLinker;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class RecruitmentController extends Controller
{
    public function __construct(private readonly NoticeRecruitmentLinker $linker)
    {
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
        $data['slug'] = Str::slug($data['title']).'-'.Str::lower(Str::random(4));
        $data['created_by'] = $request->user()->id;
        $data = $this->stampPublishedAt($data);

        $jobPosting = JobPosting::query()->create($data);
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

        $jobPosting->update($data);
        $this->linker->syncFrom($jobPosting, $request->user());
        $note = $this->publishToNoticeBoard($request, $jobPosting);

        return redirect()->route('admin.recruitment.show', $jobPosting)->with('success', "\u{201c}{$jobPosting->title}\u{201d} হালনাগাদ হয়েছে।".$note);
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
            'publish_to_notice_board' => ['nullable', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    private function attributes(): array
    {
        return ['title' => 'শিরোনাম', 'description' => 'বিবরণ', 'status' => 'স্ট্যাটাস', 'application_mode' => 'আবেদনের পদ্ধতি'];
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
        ];
    }
}
