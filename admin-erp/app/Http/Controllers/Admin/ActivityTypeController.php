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
    public const STATUSES = ['active' => 'সক্রিয়', 'inactive' => 'নিষ্ক্রিয়'];

    public function index(): View
    {
        $types = ActivityType::query()->withCount('activities')
            ->orderBy('sort_order')->orderBy('name')->paginate(15);

        return view('admin.activities.types.index', [
            'title' => __('admin.nav.activity_types'),
            'breadcrumbs' => [['label' => __('admin.nav.activities')], ['label' => __('admin.nav.activity_types')]],
            'types' => $types,
        ]);
    }

    public function create(): View
    {
        return view('admin.activities.types.form', [
            'title' => __('admin.fields.new_activity_type'),
            'breadcrumbs' => [['label' => __('admin.nav.activity_types'), 'route' => 'admin.activities.types.index'], ['label' => __('admin.actions.create')]],
            'type' => new ActivityType(['status' => 'active', 'sort_order' => 0]),
            'statuses' => status_options(self::STATUSES),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate($this->rules(), [], $this->attributes());
        $data['slug'] = Str::slug($data['name']).'-'.Str::lower(Str::random(4));

        $type = ActivityType::query()->create($data);

        return redirect()->route('admin.activities.types.index')->with('success', __('admin.flash.activity_type_created', ['name' => $type->name]));
    }

    public function edit(ActivityType $activityType): View
    {
        return view('admin.activities.types.form', [
            'title' => __('admin.fields.edit_prefix').' — '.$activityType->name,
            'breadcrumbs' => [['label' => __('admin.nav.activity_types'), 'route' => 'admin.activities.types.index'], ['label' => $activityType->name]],
            'type' => $activityType,
            'statuses' => status_options(self::STATUSES),
        ]);
    }

    public function update(Request $request, ActivityType $activityType): RedirectResponse
    {
        $activityType->update($request->validate($this->rules(), [], $this->attributes()));

        return redirect()->route('admin.activities.types.index')->with('success', __('admin.flash.activity_type_updated', ['name' => $activityType->name]));
    }

    public function destroy(ActivityType $activityType): RedirectResponse
    {
        if ($activityType->activities()->exists()) {
            return back()->with('error', __('admin.fields.activity_type_in_use_simple'));
        }

        $name = $activityType->name;
        $activityType->delete();

        return redirect()->route('admin.activities.types.index')->with('success', __('admin.flash.activity_type_deleted', ['name' => $name]));
    }

    /** @return array<string, mixed> */
    private function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'icon' => ['nullable', 'string', 'max:60'],
            'status' => ['required', Rule::in(array_keys(self::STATUSES))],
            'sort_order' => ['required', 'integer', 'min:0', 'max:65535'],
        ];
    }

    /** @return array<string, string> */
    private function attributes(): array
    {
        return ['name' => __('admin.common.name'), 'status' => __('admin.common.status'), 'sort_order' => __('admin.common.order')];
    }
}
