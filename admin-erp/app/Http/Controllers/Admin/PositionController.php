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
    public const STATUSES = ['active' => 'Active', 'inactive' => 'Inactive'];

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
            'title' => 'Positions',
            'breadcrumbs' => [['label' => 'Organization'], ['label' => 'Positions']],
            'positions' => $positions,
            'filters' => $filters,
            'statuses' => self::STATUSES,
            'units' => $this->unitOptions(),
        ]);
    }

    public function create(): View
    {
        return view('admin.positions.form', [
            'title' => 'Create Position',
            'breadcrumbs' => [['label' => 'Positions', 'route' => 'admin.positions.index'], ['label' => 'Create']],
            'position' => new OrganizationalPosition(['status' => 'active', 'level' => 0, 'is_public' => true]),
            'units' => $this->unitOptions(),
            'statuses' => self::STATUSES,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate($this->rules(), [], $this->attributes());
        $data['slug'] = Str::slug($data['name']).'-'.Str::lower(Str::random(4));
        $data['is_public'] = $request->boolean('is_public');

        $position = OrganizationalPosition::query()->create($data);

        return redirect()->route('admin.positions.index')
            ->with('success', "“{$position->name}” তৈরি হয়েছে।");
    }

    public function edit(OrganizationalPosition $position): View
    {
        return view('admin.positions.form', [
            'title' => 'Edit — '.$position->name,
            'breadcrumbs' => [['label' => 'Positions', 'route' => 'admin.positions.index'], ['label' => $position->name]],
            'position' => $position,
            'units' => $this->unitOptions(),
            'statuses' => self::STATUSES,
        ]);
    }

    public function update(Request $request, OrganizationalPosition $position): RedirectResponse
    {
        $data = $request->validate($this->rules(), [], $this->attributes());
        $data['is_public'] = $request->boolean('is_public');

        $position->update($data);

        return redirect()->route('admin.positions.index')
            ->with('success', "“{$position->name}” হালনাগাদ হয়েছে।");
    }

    public function destroy(OrganizationalPosition $position): RedirectResponse
    {
        if ($position->committeeMembers()->exists()) {
            return back()->with('error', 'এই পদটি কমিটির সদস্যদের সঙ্গে যুক্ত — আগে সেই রেকর্ডগুলো সরান।');
        }

        $name = $position->name;
        $position->delete();

        return redirect()->route('admin.positions.index')->with('success', "“{$name}” মুছে ফেলা হয়েছে।");
    }

    /** @return array<string, mixed> */
    private function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
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
            'name' => 'পদের নাম',
            'organization_unit_id' => 'সাংগঠনিক ইউনিট',
            'level' => 'স্তর/ক্রম',
            'status' => 'স্ট্যাটাস',
        ];
    }

    /** @return array<int, string> */
    private function unitOptions(): array
    {
        return OrganizationalUnit::query()->orderBy('name')->pluck('name', 'id')->all();
    }
}
