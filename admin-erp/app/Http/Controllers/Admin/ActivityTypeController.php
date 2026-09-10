<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ActivityTypeController extends Controller
{
    public const STATUSES = ['active' => 'Active', 'inactive' => 'Inactive'];

    public function index(): View
    {
        $types = ActivityType::query()->withCount('activities')
            ->orderBy('sort_order')->orderBy('name')->paginate(15);

        return view('admin.activities.types.index', [
            'title' => 'কার্যক্রমের ধরন',
            'breadcrumbs' => [['label' => 'কার্যক্রম'], ['label' => 'কার্যক্রমের ধরন']],
            'types' => $types,
        ]);
    }

    public function create(): View
    {
        return view('admin.activities.types.form', [
            'title' => 'নতুন কার্যক্রমের ধরন',
            'breadcrumbs' => [['label' => 'কার্যক্রমের ধরন', 'route' => 'admin.activities.types.index'], ['label' => 'তৈরি করুন']],
            'type' => new ActivityType(['status' => 'active', 'sort_order' => 0]),
            'statuses' => self::STATUSES,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate($this->rules(), [], $this->attributes());
        $data['slug'] = Str::slug($data['name']).'-'.Str::lower(Str::random(4));

        $type = ActivityType::query()->create($data);

        return redirect()->route('admin.activities.types.index')->with('success', "\u{201c}{$type->name}\u{201d} তৈরি হয়েছে।");
    }

    public function edit(ActivityType $activityType): View
    {
        return view('admin.activities.types.form', [
            'title' => 'সম্পাদনা — '.$activityType->name,
            'breadcrumbs' => [['label' => 'কার্যক্রমের ধরন', 'route' => 'admin.activities.types.index'], ['label' => $activityType->name]],
            'type' => $activityType,
            'statuses' => self::STATUSES,
        ]);
    }

    public function update(Request $request, ActivityType $activityType): RedirectResponse
    {
        $activityType->update($request->validate($this->rules(), [], $this->attributes()));

        return redirect()->route('admin.activities.types.index')->with('success', "\u{201c}{$activityType->name}\u{201d} হালনাগাদ হয়েছে।");
    }

    public function destroy(ActivityType $activityType): RedirectResponse
    {
        if ($activityType->activities()->exists()) {
            return back()->with('error', 'এই ধরনটি কার্যক্রমে ব্যবহৃত হচ্ছে — আগে সেগুলোর ধরন পরিবর্তন করুন।');
        }

        $name = $activityType->name;
        $activityType->delete();

        return redirect()->route('admin.activities.types.index')->with('success', "\u{201c}{$name}\u{201d} মুছে ফেলা হয়েছে।");
    }

    /** @return array<string, mixed> */
    private function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'icon' => ['nullable', 'string', 'max:60'],
            'status' => ['required', Rule::in(array_keys(self::STATUSES))],
            'sort_order' => ['required', 'integer', 'min:0', 'max:65535'],
        ];
    }

    /** @return array<string, string> */
    private function attributes(): array
    {
        return ['name' => 'নাম', 'status' => 'স্ট্যাটাস', 'sort_order' => 'ক্রম'];
    }
}
