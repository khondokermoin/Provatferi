<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Membership;
use App\Models\MembershipType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Members are created only by approving a MembershipApplication (see
 * MembershipController::createMembership) — there is deliberately no manual
 * "create member" form here, so a member is never disconnected from the
 * application that justified it.
 */
class MemberController extends Controller
{
    public function index(Request $request): View
    {
        $filters = [
            'search' => trim((string) $request->query('search', '')),
            'status' => (string) $request->query('status', ''),
            'type' => (string) $request->query('type', ''),
        ];

        $members = Membership::query()
            ->with(['user', 'member', 'membershipType'])
            ->when($filters['search'] !== '', function ($q) use ($filters) {
                $term = '%'.$filters['search'].'%';
                $q->where('member_code', 'like', $term)
                    ->orWhereHas('user', fn ($u) => $u->where('name', 'like', $term)->orWhere('email', 'like', $term))
                    ->orWhereHas('member', fn ($m) => $m->where('name', 'like', $term)->orWhere('email', 'like', $term));
            })
            ->when($filters['status'] !== '', fn ($q) => $q->where('status', $filters['status']))
            ->when($filters['type'] !== '', fn ($q) => $q->where('membership_type_id', $filters['type']))
            ->latest('start_date')
            ->paginate(15)
            ->withQueryString();

        return view('admin.membership.members.index', [
            'title' => __('admin.nav.members'),
            'breadcrumbs' => [['label' => __('admin.nav.membership')], ['label' => __('admin.nav.members')]],
            'members' => $members,
            'filters' => $filters,
            'statuses' => status_options(Membership::STATUSES),
            'types' => MembershipType::query()->orderBy('name')->pluck('name', 'id'),
        ]);
    }

    public function show(Membership $membership): View
    {
        $membership->load(['user', 'member.seasonHistory.season', 'membershipType', 'application']);

        $pendingProfileVersion = $membership->member
            ?->profileVersions()->where('status', 'pending')->latest('submitted_at')->first();
        $liveProfileVersion = $membership->member?->liveProfileVersion()->first();

        return view('admin.membership.members.show', [
            'title' => $membership->member_code,
            'breadcrumbs' => [['label' => __('admin.nav.members'), 'route' => 'admin.membership.members.index'], ['label' => $membership->member_code]],
            'member' => $membership,
            'pendingProfileVersion' => $pendingProfileVersion,
            'liveProfileVersion' => $liveProfileVersion,
        ]);
    }

    public function edit(Membership $membership): View
    {
        return view('admin.membership.members.form', [
            'title' => __('admin.fields.edit_prefix').' — '.$membership->member_code,
            'breadcrumbs' => [
                ['label' => __('admin.nav.members'), 'route' => 'admin.membership.members.index'],
                ['label' => $membership->member_code],
            ],
            'member' => $membership,
            'statuses' => status_options(Membership::STATUSES),
        ]);
    }

    public function update(Request $request, Membership $membership): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(array_keys(Membership::STATUSES))],
            'expiry_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ], [], ['status' => __('admin.common.status')]);

        $membership->update($data);

        return redirect()->route('admin.membership.members.show', $membership)
            ->with('success', __('admin.flash.member_updated', ['code' => $membership->member_code]));
    }
}
