<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Committee;
use App\Models\OrganizationalUnit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class CommitteeController extends Controller
{
    public const STATUSES = ['draft' => 'Draft', 'active' => 'Active', 'expired' => 'Expired'];

    public const TYPES = [
        'executive' => 'নির্বাহী কমিটি',
        'advisory' => 'উপদেষ্টা পরিষদ',
        'sub' => 'উপ-কমিটি',
        'ad_hoc' => 'আহ্বায়ক কমিটি',
    ];

    public function index(Request $request): View
    {
        $filters = [
            'search' => trim((string) $request->query('search', '')),
            'status' => (string) $request->query('status', ''),
            'unit' => (string) $request->query('unit', ''),
        ];

        $committees = Committee::query()
            ->with('organizationUnit')
            ->withCount('members')
            ->when($filters['search'] !== '', fn ($q) => $q->where('name', 'like', '%'.$filters['search'].'%'))
            ->when($filters['status'] !== '', fn ($q) => $q->where('status', $filters['status']))
            ->when($filters['unit'] !== '', fn ($q) => $q->where('organization_unit_id', $filters['unit']))
            ->orderByDesc('term_start')->orderBy('name')
            ->paginate(15)
            ->withQueryString();

        return view('admin.committees.index', [
            'title' => 'Committees',
            'breadcrumbs' => [['label' => 'Organization'], ['label' => 'Committees']],
            'committees' => $committees,
            'filters' => $filters,
            'statuses' => self::STATUSES,
            'types' => self::TYPES,
            'units' => $this->unitOptions(),
        ]);
    }

    public function create(): View
    {
        return view('admin.committees.form', [
            'title' => 'Create Committee',
            'breadcrumbs' => [['label' => 'Committees', 'route' => 'admin.committees.index'], ['label' => 'Create']],
            'committee' => new Committee(['status' => 'draft']),
            'units' => $this->unitOptions(),
            'statuses' => self::STATUSES,
            'types' => self::TYPES,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $committee = Committee::query()->create($request->validate($this->rules(), [], $this->attributes()));

        return redirect()->route('admin.committees.show', $committee)
            ->with('success', "“{$committee->name}” তৈরি হয়েছে।");
    }

    public function show(Committee $committee): View
    {
        $committee->load([
            'organizationUnit',
            'members' => fn ($q) => $q->orderBy('serial_no')->orderBy('id'),
            'members.user',
            'members.position',
        ]);

        return view('admin.committees.show', [
            'title' => $committee->name,
            'breadcrumbs' => [['label' => 'Committees', 'route' => 'admin.committees.index'], ['label' => $committee->name]],
            'committee' => $committee,
            'types' => self::TYPES,
        ]);
    }

    public function edit(Committee $committee): View
    {
        return view('admin.committees.form', [
            'title' => 'Edit — '.$committee->name,
            'breadcrumbs' => [
                ['label' => 'Committees', 'route' => 'admin.committees.index'],
                ['label' => $committee->name, 'route' => 'admin.committees.show', 'params' => $committee],
                ['label' => 'Edit'],
            ],
            'committee' => $committee,
            'units' => $this->unitOptions(),
            'statuses' => self::STATUSES,
            'types' => self::TYPES,
        ]);
    }

    public function update(Request $request, Committee $committee): RedirectResponse
    {
        $committee->update($request->validate($this->rules(), [], $this->attributes()));

        return redirect()->route('admin.committees.show', $committee)
            ->with('success', "“{$committee->name}” হালনাগাদ হয়েছে।");
    }

    public function destroy(Committee $committee): RedirectResponse
    {
        // committee_members cascades on delete, so refuse while members exist
        // rather than silently destroying the membership record too.
        if ($committee->members()->exists()) {
            return back()->with('error', 'এই কমিটিতে সদস্য আছে — আগে সদস্যদের সরান।');
        }

        $name = $committee->name;
        $committee->delete();

        return redirect()->route('admin.committees.index')->with('success', "“{$name}” মুছে ফেলা হয়েছে।");
    }

    /** @return array<string, mixed> */
    private function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'organization_unit_id' => ['required', Rule::exists('organizational_units', 'id')],
            'committee_type' => ['nullable', Rule::in(array_keys(self::TYPES))],
            'term_start' => ['nullable', 'date'],
            'term_end' => ['nullable', 'date', 'after_or_equal:term_start'],
            'status' => ['required', Rule::in(array_keys(self::STATUSES))],
            'description' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /** @return array<string, string> */
    private function attributes(): array
    {
        return [
            'name' => 'কমিটির নাম',
            'organization_unit_id' => 'সাংগঠনিক ইউনিট',
            'committee_type' => 'ধরন',
            'term_start' => 'মেয়াদ শুরু',
            'term_end' => 'মেয়াদ শেষ',
            'status' => 'স্ট্যাটাস',
        ];
    }

    /** @return array<int, string> */
    private function unitOptions(): array
    {
        return OrganizationalUnit::query()->orderBy('name')->pluck('name', 'id')->all();
    }
}
