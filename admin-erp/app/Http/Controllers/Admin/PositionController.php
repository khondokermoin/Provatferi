<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\OrganizationalPosition;
use App\Models\OrganizationalUnit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class PositionController extends Controller
{
    public const STATUSES = ['active' => 'সক্রিয়', 'inactive' => 'নিষ্ক্রিয়'];

    public function index(Request $request): View
    {
        $filters = [
            'search' => trim((string) $request->query('search', '')),
            'status' => (string) $request->query('status', ''),
            'unit' => (string) $request->query('unit', ''),
        ];

        $positions = OrganizationalPosition::query()
            ->with('organizationUnit')
            ->withCount('committeeMembers')
            ->when($filters['search'] !== '', fn ($q) => $q->where('name', 'like', '%'.$filters['search'].'%'))
            ->when($filters['status'] !== '', fn ($q) => $q->where('status', $filters['status']))
            ->when($filters['unit'] !== '', fn ($q) => $q->where('organization_unit_id', $filters['unit']))
            ->orderBy('level')->orderBy('name')
            ->paginate(15)
            ->withQueryString();

        return view('admin.positions.index', [
            'title' => __('admin.nav.positions'),
            'breadcrumbs' => [['label' => __('admin.nav.groups.organization')], ['label' => __('admin.nav.positions')]],
            'positions' => $positions,
            'filters' => $filters,
            'statuses' => status_options(self::STATUSES),
            'units' => $this->unitOptions(),
        ]);
    }

    public function create(): View
    {
        return view('admin.positions.form', [
            'title' => __('admin.fields.new_position'),
            'breadcrumbs' => [['label' => __('admin.nav.positions'), 'route' => 'admin.positions.index'], ['label' => __('admin.actions.create')]],
            'position' => new OrganizationalPosition(['status' => 'active', 'level' => 0, 'is_public' => true]),
            'units' => $this->unitOptions(),
            'statuses' => status_options(self::STATUSES),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate($this->rules(), [], $this->attributes());
        $data['slug'] = Str::slug($data['name']).'-'.Str::lower(Str::random(4));
        $data['is_public'] = $request->boolean('is_public');

        $position = OrganizationalPosition::query()->create($data);

        return redirect()->route('admin.positions.index')
            ->with('success', __('admin.flash.organizational_position_created', ['name' => $position->name]));
    }

    public function edit(OrganizationalPosition $position): View
    {
        return view('admin.positions.form', [
            'title' => __('admin.fields.edit_prefix').' — '.$position->name,
            'breadcrumbs' => [['label' => __('admin.nav.positions'), 'route' => 'admin.positions.index'], ['label' => $position->name]],
            'position' => $position,
            'units' => $this->unitOptions(),
            'statuses' => status_options(self::STATUSES),
        ]);
    }

    public function update(Request $request, OrganizationalPosition $position): RedirectResponse
    {
        $data = $request->validate($this->rules(), [], $this->attributes());
        $data['is_public'] = $request->boolean('is_public');

        $position->update($data);

        return redirect()->route('admin.positions.index')
            ->with('success', __('admin.flash.organizational_position_updated', ['name' => $position->name]));
    }

    public function destroy(OrganizationalPosition $position): RedirectResponse
    {
        if ($position->committeeMembers()->exists()) {
            return back()->with('error', __('admin.fields.position_has_members_cannot_delete'));
        }

        $name = $position->name;
        $position->delete();

        return redirect()->route('admin.positions.index')->with('success', __('admin.flash.organizational_position_deleted', ['name' => $name]));
    }

    /** @return array<string, mixed> */
    private function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],
            'organization_unit_id' => ['nullable', Rule::exists('organizational_units', 'id')],
            'level' => ['required', 'integer', 'min:0', 'max:255'],
            'status' => ['required', Rule::in(array_keys(self::STATUSES))],
            'description' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /** @return array<string, string> */
    private function attributes(): array
    {
        return [
            'name' => __('admin.fields.position_name'),
            'organization_unit_id' => __('admin.fields.unit'),
            'level' => __('admin.fields.level_order'),
            'status' => __('admin.common.status'),
        ];
    }

    /** @return array<int, string> */
    private function unitOptions(): array
    {
        return OrganizationalUnit::query()->orderBy('name')->pluck('name', 'id')->all();
    }
}
