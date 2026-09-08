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
    public const STATUSES = ['active' => 'Active', 'inactive' => 'Inactive'];

    public function create(Committee $committee): View
    {
        return view('admin.committees.members.form', [
            'title' => 'Add Member — '.$committee->name,
            'breadcrumbs' => [
                ['label' => 'Committees', 'route' => 'admin.committees.index'],
                ['label' => $committee->name, 'route' => 'admin.committees.show', 'params' => $committee],
                ['label' => 'Add Member'],
            ],
            'committee' => $committee,
            'member' => new CommitteeMember(['status' => 'active']),
            'users' => $this->userOptions(),
            'positions' => $this->positionOptions(),
            'statuses' => self::STATUSES,
        ]);
    }

    public function store(Request $request, Committee $committee): RedirectResponse
    {
        $data = $request->validate($this->rules($committee), [], $this->attributes());

        $committee->members()->create($data);

        return redirect()->route('admin.committees.show', $committee)
            ->with('success', 'কমিটির সদস্য যোগ হয়েছে।');
    }

    public function edit(Committee $committee, CommitteeMember $member): View
    {
        abort_unless($member->committee_id === $committee->id, 404);

        return view('admin.committees.members.form', [
            'title' => 'Edit Member — '.$committee->name,
            'breadcrumbs' => [
                ['label' => 'Committees', 'route' => 'admin.committees.index'],
                ['label' => $committee->name, 'route' => 'admin.committees.show', 'params' => $committee],
                ['label' => 'Edit Member'],
            ],
            'committee' => $committee,
            'member' => $member,
            'users' => $this->userOptions(),
            'positions' => $this->positionOptions(),
            'statuses' => self::STATUSES,
        ]);
    }

    public function update(Request $request, Committee $committee, CommitteeMember $member): RedirectResponse
    {
        abort_unless($member->committee_id === $committee->id, 404);

        $member->update($request->validate($this->rules($committee, $member), [], $this->attributes()));

        return redirect()->route('admin.committees.show', $committee)
            ->with('success', 'সদস্যের তথ্য হালনাগাদ হয়েছে।');
    }

    public function destroy(Committee $committee, CommitteeMember $member): RedirectResponse
    {
        abort_unless($member->committee_id === $committee->id, 404);

        $member->delete();

        return redirect()->route('admin.committees.show', $committee)
            ->with('success', 'সদস্য সরানো হয়েছে।');
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
            'user_id' => 'ব্যক্তি',
            'position_id' => 'পদ',
            'serial_no' => 'ক্রম',
            'start_date' => 'শুরুর তারিখ',
            'end_date' => 'শেষ তারিখ',
            'status' => 'স্ট্যাটাস',
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
