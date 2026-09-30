<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Committee;
use App\Models\CommitteeMember;
use App\Models\OrganizationalPosition;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Committee members are managed inside their committee — the pairing only has
 * meaning in that context, so there is no standalone global list.
 */
class CommitteeMemberController extends Controller
{
    public const STATUSES = ['active' => 'সক্রিয়', 'inactive' => 'নিষ্ক্রিয়'];

    public function create(Committee $committee): View
    {
        return view('admin.committees.members.form', [
            'title' => __('admin.fields.add_member').' — '.$committee->name,
            'breadcrumbs' => [
                ['label' => __('admin.nav.committees'), 'route' => 'admin.committees.index'],
                ['label' => $committee->name, 'route' => 'admin.committees.show', 'params' => $committee],
                ['label' => __('admin.fields.add_member')],
            ],
            'committee' => $committee,
            'member' => new CommitteeMember(['status' => 'active']),
            'users' => $this->userOptions(),
            'positions' => $this->positionOptions(),
            'statuses' => status_options(self::STATUSES),
        ]);
    }

    public function store(Request $request, Committee $committee): RedirectResponse
    {
        $data = $request->validate($this->rules($committee), [], $this->attributes());

        $committee->members()->create($data);

        return redirect()->route('admin.committees.show', $committee)
            ->with('success', __('admin.flash.committee_member_added'));
    }

    public function edit(Committee $committee, CommitteeMember $member): View
    {
        abort_unless($member->committee_id === $committee->id, 404);

        return view('admin.committees.members.form', [
            'title' => __('admin.fields.edit_member_title').' — '.$committee->name,
            'breadcrumbs' => [
                ['label' => __('admin.nav.committees'), 'route' => 'admin.committees.index'],
                ['label' => $committee->name, 'route' => 'admin.committees.show', 'params' => $committee],
                ['label' => __('admin.fields.edit_member_title')],
            ],
            'committee' => $committee,
            'member' => $member,
            'users' => $this->userOptions(),
            'positions' => $this->positionOptions(),
            'statuses' => status_options(self::STATUSES),
        ]);
    }

    public function update(Request $request, Committee $committee, CommitteeMember $member): RedirectResponse
    {
        abort_unless($member->committee_id === $committee->id, 404);

        $member->update($request->validate($this->rules($committee, $member), [], $this->attributes()));

        return redirect()->route('admin.committees.show', $committee)
            ->with('success', __('admin.flash.committee_member_updated'));
    }

    public function destroy(Committee $committee, CommitteeMember $member): RedirectResponse
    {
        abort_unless($member->committee_id === $committee->id, 404);

        $member->delete();

        return redirect()->route('admin.committees.show', $committee)
            ->with('success', __('admin.flash.committee_member_removed'));
    }

    /** @return array<string, mixed> */
    private function rules(Committee $committee, ?CommitteeMember $member = null): array
    {
        return [
            // One person holds at most one seat per committee.
            'user_id' => [
                'required',
                Rule::exists('users', 'id')->whereNull('deleted_at'),
                Rule::unique('committee_members', 'user_id')
                    ->where(fn ($q) => $q->where('committee_id', $committee->id))
                    ->ignore($member?->id),
            ],
            'position_id' => ['required', Rule::exists('organizational_positions', 'id')],
            'serial_no' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'status' => ['required', Rule::in(array_keys(self::STATUSES))],
        ];
    }

    /** @return array<string, string> */
    private function attributes(): array
    {
        return [
            'user_id' => __('admin.fields.person'),
            'position_id' => __('admin.fields.position'),
            'serial_no' => __('admin.common.order'),
            'start_date' => __('admin.fields.start_date'),
            'end_date' => __('admin.fields.end_date'),
            'status' => __('admin.common.status'),
        ];
    }

    /** @return array<int, string> */
    private function userOptions(): array
    {
        return User::query()->orderBy('name')->get()
            ->mapWithKeys(fn (User $u) => [$u->id => $u->name.' — '.$u->email])->all();
    }

    /** @return array<int, string> */
    private function positionOptions(): array
    {
        return OrganizationalPosition::query()->where('status', 'active')
            ->orderBy('level')->orderBy('name')->pluck('name', 'id')->all();
    }
}
