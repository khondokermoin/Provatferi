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
    public const TYPES = [
        'executive' => 'নির্বাহী কমিটি',
        'advisory' => 'উপদেষ্টা পরিষদ',
        'sub' => 'উপ-কমিটি',
        'ad_hoc' => 'আহ্বায়ক কমিটি',
    ];

    /**
     * §19: Draft -> Upcoming -> Active -> Completed -> Archived. 'expired' is
     * a legacy Phase-1 terminal status kept for backward compatibility, not
     * part of the new lifecycle — nothing transitions into or out of it here.
     *
     * @var array<string, array<int, string>>
     */
    private const TRANSITIONS = [
        'draft' => ['upcoming', 'active'],
        'upcoming' => ['active', 'draft'],
        'active' => ['completed'],
        'completed' => ['archived'],
        'archived' => [],
        'expired' => [],
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
            'title' => 'কমিটি',
            'breadcrumbs' => [['label' => 'সংগঠন'], ['label' => 'কমিটি']],
            'committees' => $committees,
            'filters' => $filters,
            'statuses' => Committee::STATUSES,
            'types' => self::TYPES,
            'units' => $this->unitOptions(),
        ]);
    }

    public function create(): View
    {
        return view('admin.committees.form', [
            'title' => 'নতুন কমিটি',
            'breadcrumbs' => [['label' => 'কমিটি', 'route' => 'admin.committees.index'], ['label' => 'তৈরি করুন']],
            'committee' => new Committee(['status' => 'draft']),
            'units' => $this->unitOptions(),
            'types' => self::TYPES,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate($this->rules(), [], $this->attributes());
        $data['status'] = 'draft';
        $committee = Committee::query()->create($data);

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
            'members.committeePosition',
            'members.submission',
            'positions' => fn ($q) => $q->orderBy('display_order')->orderBy('name'),
            'registrationLinks' => fn ($q) => $q->latest(),
        ]);

        return view('admin.committees.show', [
            'title' => $committee->name,
            'breadcrumbs' => [['label' => 'কমিটি', 'route' => 'admin.committees.index'], ['label' => $committee->name]],
            'committee' => $committee,
            'types' => self::TYPES,
            'statuses' => Committee::STATUSES,
            'allowedTransitions' => self::TRANSITIONS[$committee->status] ?? [],
            'pendingSubmissionsCount' => $committee->submissions()->whereIn('status', ['pending', 'correction_requested'])->count(),
        ]);
    }

    public function edit(Committee $committee): View
    {
        return view('admin.committees.form', [
            'title' => 'সম্পাদনা — '.$committee->name,
            'breadcrumbs' => [
                ['label' => 'কমিটি', 'route' => 'admin.committees.index'],
                ['label' => $committee->name, 'route' => 'admin.committees.show', 'params' => $committee],
                ['label' => 'সম্পাদনা'],
            ],
            'committee' => $committee,
            'units' => $this->unitOptions(),
            'types' => self::TYPES,
        ]);
    }

    public function update(Request $request, Committee $committee): RedirectResponse
    {
        // 'status' is deliberately excluded from these rules — every
        // lifecycle transition goes through updateStatus() below so
        // Committee::activate()'s single-Active invariant can never be
        // bypassed by a plain field edit.
        $committee->update($request->validate($this->rules(), [], $this->attributes()));

        return redirect()->route('admin.committees.show', $committee)
            ->with('success', "“{$committee->name}” হালনাগাদ হয়েছে।");
    }

    /**
     * §19/§20: explicit, admin-only lifecycle transitions. Activating goes
     * through Committee::activate() so "exactly one Active committee" stays
     * enforced transactionally; every other transition is a plain status
     * write — historical committees (completed/archived) are never deleted.
     */
    public function updateStatus(Request $request, Committee $committee): RedirectResponse
    {
        $allowed = self::TRANSITIONS[$committee->status] ?? [];

        // Rule::in([]) correctly rejects every value once a committee is
        // archived/expired — no fallback to the full status list, or an
        // empty $allowed would silently accept any transition.
        $data = $request->validate([
            'status' => ['required', Rule::in($allowed)],
        ]);

        if ($data['status'] === 'active') {
            $committee->activate();
        } else {
            $committee->update($data);
        }

        return back()->with('success', 'কমিটির স্ট্যাটাস হালনাগাদ হয়েছে।');
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
        ];
    }

    /** @return array<int, string> */
    private function unitOptions(): array
    {
        return OrganizationalUnit::query()->orderBy('name')->pluck('name', 'id')->all();
    }
}
