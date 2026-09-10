<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\OrganizationalUnit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class OrganizationUnitController extends Controller
{
    public const UNIT_TYPES = [
        'central' => 'কেন্দ্রীয় (Central)',
        'division' => 'বিভাগ (Division)',
        'district' => 'জেলা (District)',
        'upazila' => 'উপজেলা (Upazila)',
        'union' => 'ইউনিয়ন (Union)',
        'branch' => 'শাখা (Branch)',
        'unit' => 'ইউনিট (Unit)',
    ];

    public const STATUSES = [
        'active' => 'সক্রিয়',
        'inactive' => 'নিষ্ক্রিয়',
    ];

    public function index(Request $request): View
    {
        $filters = [
            'search' => trim((string) $request->query('search', '')),
            'unit_type' => (string) $request->query('unit_type', ''),
            'status' => (string) $request->query('status', ''),
        ];

        $units = OrganizationalUnit::query()
            ->with('parent')
            ->withCount('children')
            ->when($filters['search'] !== '', function ($query) use ($filters) {
                $term = '%'.$filters['search'].'%';
                $query->where(fn ($q) => $q->where('name', 'like', $term)
                    ->orWhere('code', 'like', $term)
                    ->orWhere('email', 'like', $term));
            })
            ->when($filters['unit_type'] !== '', fn ($q) => $q->where('unit_type', $filters['unit_type']))
            ->when($filters['status'] !== '', fn ($q) => $q->where('status', $filters['status']))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString();

        return view('admin.organization.units.index', [
            'title' => 'সাংগঠনিক ইউনিট',
            'breadcrumbs' => [['label' => 'সংগঠন'], ['label' => 'সাংগঠনিক ইউনিট']],
            'units' => $units,
            'filters' => $filters,
            'unitTypes' => self::UNIT_TYPES,
            'statuses' => self::STATUSES,
            'hasAnyUnit' => OrganizationalUnit::query()->exists(),
        ]);
    }

    public function create(): View
    {
        return view('admin.organization.units.form', [
            'title' => 'নতুন সাংগঠনিক ইউনিট',
            'breadcrumbs' => [
                ['label' => 'সাংগঠনিক ইউনিট', 'route' => 'admin.organization.units.index'],
                ['label' => 'তৈরি করুন'],
            ],
            'unit' => new OrganizationalUnit(['status' => 'active', 'sort_order' => 0]),
            'parentOptions' => $this->parentOptions(),
            'unitTypes' => self::UNIT_TYPES,
            'statuses' => self::STATUSES,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate($this->rules(), [], $this->attributeNames());
        $data['slug'] = Str::slug($data['name']).'-'.Str::lower(Str::random(4));

        $unit = OrganizationalUnit::query()->create($data);

        return redirect()
            ->route('admin.organization.units.show', $unit)
            ->with('success', "“{$unit->name}” তৈরি হয়েছে।");
    }

    public function show(OrganizationalUnit $unit): View
    {
        $unit->load(['parent', 'children' => fn ($q) => $q->orderBy('sort_order')->orderBy('name')]);

        return view('admin.organization.units.show', [
            'title' => $unit->name,
            'breadcrumbs' => [
                ['label' => 'সাংগঠনিক ইউনিট', 'route' => 'admin.organization.units.index'],
                ['label' => $unit->name],
            ],
            'unit' => $unit,
            'unitTypes' => self::UNIT_TYPES,
        ]);
    }

    public function edit(OrganizationalUnit $unit): View
    {
        return view('admin.organization.units.form', [
            'title' => 'সম্পাদনা — '.$unit->name,
            'breadcrumbs' => [
                ['label' => 'সাংগঠনিক ইউনিট', 'route' => 'admin.organization.units.index'],
                ['label' => $unit->name, 'route' => 'admin.organization.units.show', 'params' => $unit],
                ['label' => 'সম্পাদনা'],
            ],
            'unit' => $unit,
            'parentOptions' => $this->parentOptions($unit),
            'unitTypes' => self::UNIT_TYPES,
            'statuses' => self::STATUSES,
        ]);
    }

    public function update(Request $request, OrganizationalUnit $unit): RedirectResponse
    {
        $data = $request->validate($this->rules($unit), [], $this->attributeNames());

        $unit->update($data);

        return redirect()
            ->route('admin.organization.units.show', $unit)
            ->with('success', "“{$unit->name}” হালনাগাদ হয়েছে।");
    }

    public function destroy(OrganizationalUnit $unit): RedirectResponse
    {
        // Children would be orphaned (parent_id nulls out on delete), so require
        // the tree to be reorganised first rather than silently detaching them.
        if ($unit->children()->exists()) {
            return redirect()
                ->route('admin.organization.units.show', $unit)
                ->with('error', 'এই ইউনিটের অধীনে সাব-ইউনিট আছে। আগে সেগুলো সরান বা অন্য প্যারেন্টে নিন।');
        }

        $name = $unit->name;
        $unit->delete();

        return redirect()
            ->route('admin.organization.units.index')
            ->with('success', "“{$name}” মুছে ফেলা হয়েছে।");
    }

    /** @return array<string, mixed> */
    private function rules(?OrganizationalUnit $unit = null): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'unit_type' => ['required', Rule::in(array_keys(self::UNIT_TYPES))],
            // A unit cannot be its own parent; deeper cycles are prevented by
            // excluding the unit's own descendants from parentOptions().
            'parent_id' => array_values(array_filter([
                'nullable',
                Rule::exists('organizational_units', 'id'),
                $unit ? Rule::notIn([$unit->id]) : null,
            ])),
            'code' => ['nullable', 'string', 'max:50'],
            'status' => ['required', Rule::in(array_keys(self::STATUSES))],
            'sort_order' => ['required', 'integer', 'min:0', 'max:65535'],
            'description' => ['nullable', 'string', 'max:5000'],
            'address' => ['nullable', 'string', 'max:1000'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'established_date' => ['nullable', 'date'],
        ];
    }

    /** @return array<string, string> */
    private function attributeNames(): array
    {
        return [
            'name' => 'নাম',
            'unit_type' => 'ইউনিটের ধরন',
            'parent_id' => 'প্যারেন্ট ইউনিট',
            'status' => 'স্ট্যাটাস',
            'sort_order' => 'ক্রম',
        ];
    }

    /**
     * Parent choices as an indented tree. The unit being edited and all of its
     * descendants are excluded, so a cycle can't be created through the form.
     *
     * @return array<int, string>
     */
    private function parentOptions(?OrganizationalUnit $exclude = null): array
    {
        $all = OrganizationalUnit::query()->orderBy('sort_order')->orderBy('name')->get();
        $excludedIds = $exclude ? $this->descendantIds($all, $exclude->id)->push($exclude->id) : collect();

        $options = [];
        $walk = function ($parentId, $depth) use (&$walk, $all, $excludedIds, &$options) {
            foreach ($all->where('parent_id', $parentId) as $node) {
                if ($excludedIds->contains($node->id)) {
                    continue;
                }
                $options[$node->id] = str_repeat('— ', $depth).$node->name;
                $walk($node->id, $depth + 1);
            }
        };
        $walk(null, 0);

        return $options;
    }

    /** @return \Illuminate\Support\Collection<int, int> */
    private function descendantIds($all, int $parentId): \Illuminate\Support\Collection
    {
        $ids = collect();
        foreach ($all->where('parent_id', $parentId) as $child) {
            $ids->push($child->id);
            $ids = $ids->merge($this->descendantIds($all, $child->id));
        }

        return $ids;
    }
}
