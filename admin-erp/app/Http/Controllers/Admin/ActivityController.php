<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\ActivityType;
use App\Models\OrganizationalUnit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator as ValidatorFacade;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Illuminate\View\View;

class ActivityController extends Controller
{
    public function index(Request $request): View
    {
        $filters = [
            'search' => trim((string) $request->query('search', '')),
            'status' => (string) $request->query('status', ''),
            'type' => (string) $request->query('type', ''),
        ];

        $activities = Activity::query()
            ->with(['type', 'organizationUnit'])
            ->when($filters['search'] !== '', fn ($q) => $q->where('title', 'like', '%'.$filters['search'].'%'))
            ->when($filters['status'] !== '', fn ($q) => $q->where('status', $filters['status']))
            ->when($filters['type'] !== '', fn ($q) => $q->where('activity_type_id', $filters['type']))
            ->latest('start_datetime')
            ->paginate(15)
            ->withQueryString();

        return view('admin.activities.index', [
            'title' => 'কার্যক্রম',
            'breadcrumbs' => [['label' => 'কার্যক্রম']],
            'activities' => $activities,
            'filters' => $filters,
            'statuses' => Activity::STATUSES,
            'types' => ActivityType::query()->orderBy('name')->pluck('name', 'id'),
        ]);
    }

    public function create(): View
    {
        return view('admin.activities.form', [
            'title' => 'Create Activity',
            'breadcrumbs' => [['label' => 'কার্যক্রম', 'route' => 'admin.activities.index'], ['label' => 'তৈরি করুন']],
            'activity' => new Activity(['status' => 'draft', 'participant_count' => 0]),
            'types' => $this->typeOptions(),
            'units' => $this->unitOptions(),
            'statuses' => Activity::STATUSES,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $data['slug'] = Str::slug($data['title']).'-'.Str::lower(Str::random(4));
        $data['created_by'] = $request->user()->id;
        $data['featured'] = $request->boolean('featured');
        $data['gallery'] = [];
        $data['related_links'] = [];
        $data = $this->stampPublishedAt($data);

        $activity = Activity::query()->create($data);

        return redirect()->route('admin.activities.show', $activity)->with('success', "\u{201c}{$activity->title}\u{201d} তৈরি হয়েছে।");
    }

    public function show(Activity $activity): View
    {
        $activity->load(['type', 'organizationUnit', 'coordinator']);

        return view('admin.activities.show', [
            'title' => $activity->title,
            'breadcrumbs' => [['label' => 'কার্যক্রম', 'route' => 'admin.activities.index'], ['label' => $activity->title]],
            'activity' => $activity,
        ]);
    }

    public function edit(Activity $activity): View
    {
        return view('admin.activities.form', [
            'title' => 'সম্পাদনা — '.$activity->title,
            'breadcrumbs' => [
                ['label' => 'কার্যক্রম', 'route' => 'admin.activities.index'],
                ['label' => $activity->title, 'route' => 'admin.activities.show', 'params' => $activity],
                ['label' => 'সম্পাদনা'],
            ],
            'activity' => $activity,
            'types' => $this->typeOptions(),
            'units' => $this->unitOptions(),
            'statuses' => Activity::STATUSES,
        ]);
    }

    public function update(Request $request, Activity $activity): RedirectResponse
    {
        $data = $this->validated($request);
        $data['featured'] = $request->boolean('featured');
        $data = $this->stampPublishedAt($data, $activity);

        $activity->update($data);

        return redirect()->route('admin.activities.show', $activity)->with('success', "\u{201c}{$activity->title}\u{201d} হালনাগাদ হয়েছে।");
    }

    public function destroy(Activity $activity): RedirectResponse
    {
        $title = $activity->title;
        $activity->delete();

        return redirect()->route('admin.activities.index')->with('success', "\u{201c}{$title}\u{201d} মুছে ফেলা হয়েছে।");
    }

    /**
     * A published activity must have the minimum real facts a public evidence
     * card needs. Built manually (not $request->validate()) because the
     * conditional-required check needs an ->after() hook, which plain
     * validate() has no way to attach.
     *
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        $validator = ValidatorFacade::make($request->all(), $this->rules(), [], $this->attributes());

        $validator->after(function (Validator $v) use ($request) {
            if ($request->input('status') !== 'published') {
                return;
            }
            foreach (['title', 'activity_type_id', 'summary', 'start_datetime'] as $field) {
                if (blank($request->input($field))) {
                    $v->errors()->add($field, 'প্রকাশ করার জন্য এই তথ্যটি আবশ্যক।');
                }
            }
        });

        return $validator->validate();
    }

    /** @return array<string, mixed> */
    private function rules(): array
    {
        return [
            'activity_type_id' => ['required', Rule::exists('activity_types', 'id')],
            'organization_unit_id' => ['nullable', Rule::exists('organizational_units', 'id')],
            'title' => ['required', 'string', 'max:255'],
            'summary' => ['nullable', 'string', 'max:500'],
            'description' => ['nullable', 'string', 'max:5000'],
            'objective' => ['nullable', 'string', 'max:2000'],
            'what_happened' => ['nullable', 'string', 'max:10000'],
            'outcomes' => ['nullable', 'string', 'max:5000'],
            'facebook_post_url' => ['nullable', 'url', 'max:255'],
            'venue' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:1000'],
            'start_datetime' => ['nullable', 'date'],
            'end_datetime' => ['nullable', 'date', 'after_or_equal:start_datetime'],
            'status' => ['required', Rule::in(array_keys(Activity::STATUSES))],
            'participant_count' => ['required', 'integer', 'min:0'],
        ];
    }

    /**
     * Stamps published_at the first time an activity is published, and clears
     * it only on a return to draft — archiving keeps the original timestamp
     * as a historical record of when it was actually live (same rule as
     * RecruitmentController::stampPublishedAt).
     *
     * @param array<string, mixed> $data
     */
    private function stampPublishedAt(array $data, ?Activity $activity = null): array
    {
        $wasPublished = $activity?->status === 'published';
        if ($data['status'] === 'published' && ! $wasPublished) {
            $data['published_at'] = now();
        } elseif ($data['status'] === 'draft') {
            $data['published_at'] = null;
        }

        return $data;
    }

    /** @return array<string, string> */
    private function attributes(): array
    {
        return [
            'activity_type_id' => 'কার্যক্রমের ধরন', 'title' => 'শিরোনাম', 'summary' => 'সংক্ষিপ্ত বিবরণ',
            'start_datetime' => 'শুরুর তারিখ ও সময়', 'status' => 'স্ট্যাটাস',
        ];
    }

    /** @return array<int, string> */
    private function typeOptions(): array
    {
        return ActivityType::query()->where('status', 'active')->orderBy('sort_order')->pluck('name', 'id')->all();
    }

    /** @return array<int, string> */
    private function unitOptions(): array
    {
        return OrganizationalUnit::query()->orderBy('name')->pluck('name', 'id')->all();
    }
}
