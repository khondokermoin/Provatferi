<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Committee;
use App\Models\OrganizationalUnit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
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
            'title' => __('admin.nav.committees'),
            'breadcrumbs' => [['label' => __('admin.nav.groups.organization')], ['label' => __('admin.nav.committees')]],
            'committees' => $committees,
            'filters' => $filters,
            'statuses' => status_options(Committee::STATUSES),
            'types' => option_options('committee_types', self::TYPES),
            'units' => $this->unitOptions(),
        ]);
    }

    public function create(): View
    {
        return view('admin.committees.form', [
            'title' => __('admin.fields.new_committee'),
            'breadcrumbs' => [['label' => __('admin.nav.committees'), 'route' => 'admin.committees.index'], ['label' => __('admin.actions.create')]],
            'committee' => new Committee(['status' => 'draft']),
            'units' => $this->unitOptions(),
            'types' => option_options('committee_types', self::TYPES),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate($this->rules(), [], $this->attributes());
        $data['status'] = 'draft';
        $data['slug'] = $this->uniqueSlug($data['name']);
        $committee = Committee::query()->create($data);

        return redirect()->route('admin.committees.show', $committee)
            ->with('success', __('admin.flash.committee_created', ['name' => $committee->name]));
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
            'breadcrumbs' => [['label' => __('admin.nav.committees'), 'route' => 'admin.committees.index'], ['label' => $committee->name]],
            'committee' => $committee,
            'types' => option_options('committee_types', self::TYPES),
            'statuses' => status_options(Committee::STATUSES),
            'allowedTransitions' => self::TRANSITIONS[$committee->status] ?? [],
            'pendingSubmissionsCount' => $committee->submissions()->whereIn('status', ['pending', 'correction_requested'])->count(),
        ]);
    }

    public function edit(Committee $committee): View
    {
        return view('admin.committees.form', [
            'title' => __('admin.fields.edit_prefix').' — '.$committee->name,
            'breadcrumbs' => [
                ['label' => __('admin.nav.committees'), 'route' => 'admin.committees.index'],
                ['label' => $committee->name, 'route' => 'admin.committees.show', 'params' => $committee],
                ['label' => __('admin.actions.edit')],
            ],
            'committee' => $committee,
            'units' => $this->unitOptions(),
            'types' => option_options('committee_types', self::TYPES),
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
            ->with('success', __('admin.flash.committee_updated', ['name' => $committee->name]));
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

        return back()->with('success', __('admin.flash.committee_status_updated'));
    }

    public function destroy(Committee $committee): RedirectResponse
    {
        // committee_members cascades on delete, so refuse while members exist
        // rather than silently destroying the membership record too.
        if ($committee->members()->exists()) {
            return back()->with('error', __('admin.flash.committee_has_members_cannot_delete'));
        }

        $name = $committee->name;
        $committee->delete();

        return redirect()->route('admin.committees.index')->with('success', __('admin.flash.committee_deleted', ['name' => $name]));
    }

    /** @return array<string, mixed> */
    private function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],
            'organization_unit_id' => ['required', Rule::exists('organizational_units', 'id')],
            'committee_type' => ['nullable', Rule::in(array_keys(self::TYPES))],
            'term_start' => ['nullable', 'date'],
            'term_end' => ['nullable', 'date', 'after_or_equal:term_start'],
            'description' => ['nullable', 'string', 'max:5000'],
            'description_en' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /** @return array<string, string> */
    private function attributes(): array
    {
        return [
            'name' => __('admin.fields.committee_name'),
            'organization_unit_id' => __('admin.fields.unit'),
            'committee_type' => __('admin.common.type'),
            'term_start' => __('admin.fields.term_starts'),
            'term_end' => __('admin.fields.term_end'),
        ];
    }

    /** @return array<int, string> */
    private function unitOptions(): array
    {
        return OrganizationalUnit::query()->orderBy('name')->pluck('name', 'id')->all();
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name);
        $slug = $base;
        $suffix = 1;
        while (Committee::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.(++$suffix);
        }

        return $slug;
    }
}
