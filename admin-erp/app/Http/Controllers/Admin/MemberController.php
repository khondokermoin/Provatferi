<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ApprovalHistory;
use App\Models\Member;
use App\Models\Membership;
use App\Models\MembershipType;
use App\Services\ApplicationDocumentService;
use App\Services\PhotoUploadService;
use App\Support\MembershipHistory;
use App\Support\MembershipPaymentState;
use App\Support\PhoneNumber;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The Admin Member Registry (Membership Registry task 2): every membership, the person behind it, and the actions an
 * admin may take on it.
 *
 * Members are created only by approving a MembershipApplication (App\Services\MembershipApprovalService) — there is
 * deliberately no manual "create member" form, so a member is never disconnected from the application that justified
 * it. A row here is a Membership (number, type, joining date, registry status); the person — contact details, portal
 * account, public profile — is its Member.
 *
 * Nothing here deletes a member. Status changes only through the explicit actions (activate / suspend / reactivate /
 * archive, Membership::STATUS_ACTIONS), each recorded with who, when and why; an edit records what changed, old and new.
 */
class MemberController extends Controller
{
    private const PER_PAGE = [15, 30, 50];

    public function index(Request $request): View
    {
        $filters = [
            'search' => trim((string) $request->query('search', '')),
            'status' => (string) $request->query('status', ''),
            'type' => (string) $request->query('type', ''),
            'joined_from' => $this->date($request->query('joined_from')),
            'joined_to' => $this->date($request->query('joined_to')),
            'payment' => in_array($request->query('payment'), MembershipPaymentState::ALL, true) ? (string) $request->query('payment') : '',
            'profile' => in_array($request->query('profile'), ['hidden', 'awaiting', 'public'], true) ? (string) $request->query('profile') : '',
        ];
        $perPage = in_array((int) $request->query('per_page'), self::PER_PAGE, true) ? (int) $request->query('per_page') : self::PER_PAGE[0];

        $query = Membership::query()
            ->with(['user', 'member', 'membershipType', 'application.payments', 'member.liveProfileVersion'])
            ->when($filters['search'] !== '', fn (Builder $q) => $this->search($q, $filters['search']))
            ->when($filters['status'] !== '', fn (Builder $q) => $q->where('status', $filters['status']))
            ->when($filters['type'] !== '', fn (Builder $q) => $q->where('membership_type_id', $filters['type']))
            ->when($filters['joined_from'] !== '', fn (Builder $q) => $q->whereDate('start_date', '>=', $filters['joined_from']))
            ->when($filters['joined_to'] !== '', fn (Builder $q) => $q->whereDate('start_date', '<=', $filters['joined_to']))
            ->when($filters['payment'] !== '', fn (Builder $q) => $this->paymentFilter($q, $filters['payment']))
            ->when($filters['profile'] !== '', fn (Builder $q) => $q->whereHas('member', fn (Builder $m) => match ($filters['profile']) {
                'hidden' => $m->where('public_profile_enabled', false),
                'awaiting' => $m->where('public_profile_enabled', true)->where('public_profile_approved', false),
                default => $m->where('public_profile_enabled', true)->where('public_profile_approved', true),
            }));

        $members = $query->orderByDesc('start_date')->orderByDesc('id')->paginate($perPage)->withQueryString();

        return view('admin.membership.members.index', [
            'title' => __('admin.registry.title'),
            'breadcrumbs' => [['label' => __('admin.nav.membership')], ['label' => __('admin.registry.title')]],
            'members' => $members,
            'filters' => $filters,
            'perPage' => $perPage,
            'perPageOptions' => self::PER_PAGE,
            'statuses' => status_options(Membership::STATUSES),
            'types' => MembershipType::query()->orderBy('sort_order')->orderBy('name')->pluck('name', 'id'),
            'paymentStates' => collect(MembershipPaymentState::ALL)->mapWithKeys(fn ($s) => [$s => __("admin.registry.payment.{$s}")])->all(),
            'totals' => [
                'all' => Membership::query()->count(),
                'active' => Membership::query()->where('status', 'active')->count(),
                'suspended' => Membership::query()->where('status', 'suspended')->count(),
                'archived' => Membership::query()->where('status', 'archived')->count(),
            ],
        ]);
    }

    public function show(Membership $membership): View
    {
        $membership->load([
            'user', 'member.seasonHistory.season', 'membershipType', 'approver',
            'application.payments.receivedBy', 'application.payments.verifiedBy', 'application.payments.waivedBy',
            'application.feePolicy', 'application.season',
        ]);

        $pendingProfileVersion = $membership->member
            ?->profileVersions()->where('status', 'pending')->latest('submitted_at')->first();
        $liveProfileVersion = $membership->member?->liveProfileVersion()->first();
        $statusReason = in_array($membership->status, ['suspended', 'archived'], true)
            ? $membership->history()->where('action', $membership->status)->latest('id')->first()
            : null;

        return view('admin.membership.members.show', [
            'title' => $membership->holderName() ?: $membership->member_code,
            'breadcrumbs' => [['label' => __('admin.registry.title'), 'route' => 'admin.membership.members.index'], ['label' => $membership->member_code]],
            'member' => $membership,
            'person' => $membership->member,
            'pendingProfileVersion' => $pendingProfileVersion,
            'liveProfileVersion' => $liveProfileVersion,
            'paymentState' => MembershipPaymentState::of($membership->application),
            'statusReason' => $statusReason,
            'availableActions' => $membership->availableStatusActions(),
            'history' => MembershipHistory::forMembership($membership),
            'otherMemberships' => $membership->member_id
                ? Membership::query()->where('member_id', $membership->member_id)->whereKeyNot($membership->id)->with('membershipType')->orderByDesc('start_date')->get()
                : collect(),
        ]);
    }

    public function edit(Membership $membership): View
    {
        $membership->load(['member', 'user', 'membershipType']);

        return view('admin.membership.members.form', [
            'title' => __('admin.fields.edit_prefix').' — '.($membership->holderName() ?: $membership->member_code),
            'breadcrumbs' => [
                ['label' => __('admin.registry.title'), 'route' => 'admin.membership.members.index'],
                ['label' => $membership->member_code, 'route' => 'admin.membership.members.show', 'params' => $membership],
                ['label' => __('admin.actions.edit')],
            ],
            'member' => $membership,
            'person' => $membership->member,
        ]);
    }

    /**
     * Corrects the person's details (Member) and the registry row's internal notes and term end (Membership). The
     * status is not edited here — only through the audited actions. Every changed field is recorded with its old and
     * new value; nothing is silently overwritten. E-mail and mobile stay unique across member accounts (the e-mail is
     * the portal login).
     */
    public function update(Request $request, Membership $membership): RedirectResponse
    {
        $person = $membership->member;
        $rules = [
            'notes' => ['nullable', 'string', 'max:2000'],
            'expiry_date' => ['nullable', 'date'],
        ];
        if ($person !== null) {
            $rules += [
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'email', 'max:255', Rule::unique('members', 'email')->ignore($person->id)],
                'phone' => ['required', 'string', 'max:30'],
                'address' => ['nullable', 'string', 'max:500'],
                'profession' => ['nullable', 'string', 'max:255'],
                'institution' => ['nullable', 'string', 'max:255'],
            ];
        }
        $data = $request->validate($rules, [], [
            'name' => __('admin.common.name'), 'email' => __('admin.common.email'), 'phone' => __('admin.fields.mobile'),
            'address' => __('admin.registry.fields.address'), 'profession' => __('admin.registry.fields.profession'),
            'institution' => __('admin.registry.fields.institution'), 'notes' => __('admin.common.notes'), 'expiry_date' => __('admin.fields.term_end'),
        ]);

        if ($person !== null) {
            $data['email'] = mb_strtolower(trim($data['email']));
            $data['phone'] = PhoneNumber::normalize($data['phone']) ?? trim($data['phone']);
            if (Member::withTrashed()->where('phone', $data['phone'])->whereKeyNot($person->id)->exists()) {
                return back()->withErrors(['phone' => __('admin.registry.errors.phone_taken')])->withInput();
            }
        }

        DB::transaction(function () use ($request, $membership, $person, $data) {
            if ($person !== null) {
                $personFields = ['name', 'email', 'phone', 'address', 'profession', 'institution'];
                $changes = $this->changes($person, array_intersect_key($data, array_flip($personFields)));
                if ($changes !== []) {
                    $person->forceFill(array_intersect_key($data, array_flip($personFields)))->save();
                    ApprovalHistory::record($person, 'updated', $request->user(), $this->describeChanges($changes));
                }
            }

            $rowData = ['notes' => $data['notes'] ?? null, 'expiry_date' => $data['expiry_date'] ?? null];
            $rowChanges = $this->changes($membership, $rowData);
            if ($rowChanges !== []) {
                $membership->forceFill($rowData)->save();
                ApprovalHistory::record($membership, 'updated', $request->user(), $this->describeChanges($rowChanges));
            }
        });

        return redirect()->route('admin.membership.members.show', $membership)
            ->with('success', __('admin.flash.member_updated', ['code' => $membership->member_code]));
    }

    /** activate / suspend / reactivate / archive — the only way a membership's status changes. */
    public function changeStatus(Request $request, Membership $membership): RedirectResponse
    {
        $data = $request->validate([
            'action' => ['required', Rule::in(array_keys(Membership::STATUS_ACTIONS))],
            'reason' => ['nullable', 'string', 'max:1000'],
        ], [], ['reason' => __('admin.fields.reason')]);

        $rule = Membership::STATUS_ACTIONS[$data['action']];
        $reason = trim((string) ($data['reason'] ?? ''));
        if ($rule['reason'] && $reason === '') {
            return back()->withErrors(['reason' => __('admin.registry.errors.reason_required')])->withInput();
        }

        $outcome = DB::transaction(function () use ($membership, $rule, $reason, $request): string {
            $locked = Membership::query()->whereKey($membership->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === $rule['to']) {
                return 'unchanged'; // a repeated click or a retried request: done once, recorded once
            }
            if (! in_array($locked->status, $rule['from'], true)) {
                return 'not_allowed';
            }

            $locked->forceFill(['status' => $rule['to']])->save();
            ApprovalHistory::record($locked, $rule['event'], $request->user(), $reason !== '' ? $reason : null);
            // The person's portal account follows (and is signed out everywhere when no longer active).
            $locked->member?->syncStatusFromMemberships();

            return 'changed';
        });

        return match ($outcome) {
            'changed' => back()->with('success', __("admin.registry.flash.{$data['action']}", ['code' => $membership->member_code])),
            'unchanged' => back()->with('status', __('admin.registry.flash.no_change')),
            default => back()->with('error', __('admin.registry.errors.status_action_not_allowed')),
        };
    }

    /**
     * The member's photo from the PRIVATE disk (a resized copy made at approval), for signed-in admins holding
     * membership.view only. Cached by the admin's own browser for a few minutes (the registry list shows many at once),
     * never by a shared cache; the URL carries the account's updated_at, so a new photo is never served stale.
     */
    public function photo(Membership $membership, ApplicationDocumentService $documents): StreamedResponse
    {
        $path = $membership->member?->photo_path;
        abort_unless($path !== null, 404);

        $response = $documents->response($path, "{$membership->member_code}.jpg", 'image/jpeg', true);
        $response->headers->set('Cache-Control', 'private, max-age=600');

        return $response;
    }

    private function search(Builder $query, string $search): void
    {
        $term = '%'.$search.'%';
        $digits = preg_replace('/\D+/', '', $search) ?? '';
        $phone = PhoneNumber::normalize($search);

        $query->where(function (Builder $w) use ($term, $digits, $phone) {
            $w->where('member_code', 'like', $term)
                ->orWhereHas('member', function (Builder $m) use ($term, $digits, $phone) {
                    $m->where('name', 'like', $term)->orWhere('email', 'like', $term)->orWhere('phone', 'like', $term);
                    if (strlen($digits) >= 4) {
                        $m->orWhere('phone', 'like', '%'.$digits.'%');
                    }
                    if ($phone !== null && strlen($phone) >= 4) {
                        $m->orWhere('phone', 'like', '%'.$phone.'%');
                    }
                })
                ->orWhereHas('user', fn (Builder $u) => $u->where('name', 'like', $term)->orWhere('email', 'like', $term));
        });
    }

    /** The same states as App\Support\MembershipPaymentState::of(), expressed as SQL over the source application. */
    private function paymentFilter(Builder $query, string $state): void
    {
        $satisfied = fn (Builder $p) => $p->where(fn (Builder $s) => $s->where('status', 'waived')->orWhere(fn (Builder $x) => $x->where('status', 'paid')->whereNotNull('verified_at')));
        $unsatisfied = fn (Builder $p) => $p->where(fn (Builder $s) => $s->where('status', 'pending')->orWhere(fn (Builder $x) => $x->where('status', 'paid')->whereNull('verified_at')));
        $feeBearing = fn (Builder $a) => $a->where(fn (Builder $f) => $f->whereNull('registration_fee_amount')->orWhere('registration_fee_amount', '>', 0));

        match ($state) {
            MembershipPaymentState::NOT_REQUIRED => $query->whereHas('application', fn (Builder $a) => $a->whereNotNull('registration_fee_amount')->where('registration_fee_amount', '<=', 0)),
            MembershipPaymentState::NO_QUOTE => $query->where(fn (Builder $q) => $q->whereDoesntHave('application')
                ->orWhereHas('application', fn (Builder $a) => $a->whereNull('registration_fee_amount')->whereDoesntHave('payments'))),
            MembershipPaymentState::UNPAID => $query->whereHas('application', fn (Builder $a) => $a->where('registration_fee_amount', '>', 0)->whereDoesntHave('payments')),
            MembershipPaymentState::AWAITING_VERIFICATION => $query->whereHas('application', fn (Builder $a) => $feeBearing($a)->whereHas('payments', $unsatisfied)),
            MembershipPaymentState::WAIVED => $query->whereHas('application', fn (Builder $a) => $feeBearing($a)->whereHas('payments', fn (Builder $p) => $p->where('status', 'waived'))->whereDoesntHave('payments', $unsatisfied)),
            default => $query->whereHas('application', fn (Builder $a) => $feeBearing($a)->whereHas('payments', $satisfied)
                ->whereDoesntHave('payments', $unsatisfied)->whereDoesntHave('payments', fn (Builder $p) => $p->where('status', 'waived'))),
        };
    }

    /**
     * @param  array<string, mixed>  $new
     * @return array<string, array{0: mixed, 1: mixed}>
     */
    private function changes(object $model, array $new): array
    {
        $changes = [];
        foreach ($new as $field => $value) {
            $old = $model->{$field};
            $oldComparable = $old instanceof \DateTimeInterface ? $old->format('Y-m-d') : ($old === null ? '' : trim((string) $old));
            $newComparable = $value === null ? '' : trim((string) $value);
            if ($oldComparable !== $newComparable) {
                $changes[$field] = [$oldComparable === '' ? null : $oldComparable, $newComparable === '' ? null : $newComparable];
            }
        }

        return $changes;
    }

    /**
     * Stored as JSON — field => [old, new] — not as a sentence, so the history reads in whichever language the admin
     * viewing it uses (App\Support\MembershipHistory labels the fields when it is shown).
     *
     * @param  array<string, array{0: mixed, 1: mixed}>  $changes
     */
    private function describeChanges(array $changes): string
    {
        return (string) json_encode(['changes' => $changes], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function date(mixed $value): string
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : '';
    }
}
