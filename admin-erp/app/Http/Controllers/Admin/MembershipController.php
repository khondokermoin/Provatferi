<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\MembershipApprovalBlocked;
use App\Http\Controllers\Controller;
use App\Jobs\SendMembershipDecisionNotifications;
use App\Models\ApprovalHistory;
use App\Models\MembershipApplication;
use App\Models\MembershipType;
use App\Models\Payment;
use App\Services\ApplicationDocumentService;
use App\Services\MembershipApprovalService;
use App\Services\MembershipNumbering;
use App\Support\AdminTime;
use App\Support\MembershipHistory;
use App\Support\MembershipPaymentState;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Membership applications: the review queue, one application's review page, every decision on it, and the cash
 * payment that a fee-bearing application needs before it can be approved.
 *
 * Every meaningful step is written to approval_history (admin-only, never public): each status change, internal notes,
 * and each payment recorded / verified / waived. Approving is MembershipApprovalService's job — it also creates or
 * links the member and the registry row, idempotently. Text written FOR the applicant (a rejection reason, what
 * information is needed) is kept apart from internal notes, and only the former is ever e-mailed.
 */
class MembershipController extends Controller
{
    public function __construct(
        private readonly MembershipApprovalService $approvals,
        private readonly ApplicationDocumentService $documents,
        private readonly MembershipNumbering $numbering,
    ) {
    }

    public function index(Request $request): View
    {
        $filters = [
            'search' => trim((string) $request->query('search', '')),
            'status' => (string) $request->query('status', ''),
            'type' => (string) $request->query('type', ''),
            'date' => (string) $request->query('date', ''),
        ];

        $applications = MembershipApplication::query()
            ->with(['user', 'membershipType', 'season', 'payments', 'membership'])
            ->when($filters['search'] !== '', function ($q) use ($filters) {
                $term = '%'.$filters['search'].'%';
                // An application number also matches written without its leading zeros: "2026-7" finds APP-2026-0007.
                $numbers = $this->numbering->searchVariants($filters['search']);
                $q->where(fn ($w) => $w->where('application_no', 'like', $term)
                    ->when($numbers !== [], fn ($n) => $n->orWhereIn('application_no', $numbers))
                    ->orWhere('applicant_name', 'like', $term)
                    ->orWhere('applicant_email', 'like', $term)
                    ->orWhere('applicant_phone', 'like', $term)
                    ->orWhereHas('user', fn ($u) => $u->where('name', 'like', $term)->orWhere('email', 'like', $term)));
            })
            ->when($filters['status'] !== '', fn ($q) => $q->where('status', $filters['status']))
            ->when($filters['type'] !== '', fn ($q) => $q->where('membership_type_id', $filters['type']))
            // The day is the organisation's (Dhaka): an application sent at 01:00 on 8 October in Dhaka is stored as
            // 19:00 UTC on 7 October, and belongs to 8 October.
            ->when(AdminTime::day($filters['date']) !== null, fn ($q) => $q
                ->where('created_at', '>=', AdminTime::startOfDayUtc($filters['date']))
                ->where('created_at', '<', AdminTime::endOfDayUtc($filters['date'])))
            ->latest()
            ->paginate(15)
            ->withQueryString();

        return view('admin.membership.index', [
            'title' => __('admin.nav.membership_applications'),
            'breadcrumbs' => [['label' => __('admin.nav.membership')], ['label' => __('admin.nav.membership_applications')]],
            'applications' => $applications,
            'filters' => $filters,
            'statuses' => status_options(MembershipApplication::STATUSES),
            'types' => MembershipType::query()->orderBy('sort_order')->orderBy('name')->pluck('name', 'id'),
        ]);
    }

    public function show(MembershipApplication $membershipApplication): View
    {
        $application = $membershipApplication->load(['user', 'membershipType', 'organizationUnit', 'reviewer', 'season', 'payments.receivedBy', 'payments.verifiedBy', 'payments.waivedBy', 'feePolicy', 'membership.member']);
        $allowed = MembershipApplication::TRANSITIONS[$application->status] ?? [];

        return view('admin.membership.show', [
            'title' => $application->application_no,
            'breadcrumbs' => [['label' => __('admin.nav.membership_applications'), 'route' => 'admin.membership.index'], ['label' => $application->application_no]],
            'application' => $application,
            'allowedTransitions' => $allowed,
            'statuses' => status_options(MembershipApplication::STATUSES),
            'paymentState' => MembershipPaymentState::of($application),
            'preview' => in_array('approved', $allowed, true) ? $this->approvals->preview($application) : null,
            'history' => MembershipHistory::forApplication($application),
            'latestRequest' => $application->status === 'need_information'
                ? $application->history()->where('action', 'need_information')->latest('id')->first()
                : null,
        ]);
    }

    public function updateStatus(Request $request, MembershipApplication $membershipApplication): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(array_keys(MembershipApplication::STATUSES))],
            'internal_note' => ['nullable', 'string', 'max:2000'],
            'applicant_message' => ['nullable', 'string', 'max:2000'],
            'rejection_reason' => ['nullable', 'string', 'max:1000'],
            'confirm_member_id' => ['nullable', 'integer'],
        ], [], [
            'internal_note' => __('admin.fields.internal_note'),
            'applicant_message' => __('admin.registry.review.applicant_message'),
            'rejection_reason' => __('admin.actions2.reject_reason'),
        ]);
        $target = $data['status'];
        $note = $this->clean($data['internal_note'] ?? null);
        $back = redirect()->route('admin.membership.show', $membershipApplication);

        // A repeated or retried request — the application is already where it was asked to go: nothing is written twice.
        if ($membershipApplication->status === $target) {
            return $target === 'approved'
                ? $back->with('status', __('admin.registry.flash.already_approved', ['code' => $membershipApplication->membership?->member_code ?? '—']))
                : $back->with('status', __('admin.registry.flash.no_change'));
        }

        if (! in_array($target, MembershipApplication::TRANSITIONS[$membershipApplication->status] ?? [], true)) {
            return back()->withErrors(['status' => __('admin.registry.errors.transition_not_allowed')]);
        }

        if ($target === 'approved') {
            return $this->approve($request, $membershipApplication, $note, isset($data['confirm_member_id']) ? (int) $data['confirm_member_id'] : null);
        }

        $applicantText = match ($target) {
            'need_information' => $this->clean($data['applicant_message'] ?? null),
            'rejected' => $this->clean($data['rejection_reason'] ?? null),
            default => null,
        };
        if ($target === 'need_information' && $applicantText === null) {
            return back()->withErrors(['applicant_message' => __('admin.registry.errors.applicant_message_required')])->withInput();
        }
        if ($target === 'rejected' && $applicantText === null) {
            return back()->withErrors(['rejection_reason' => __('admin.registry.errors.rejection_reason_required')])->withInput();
        }

        $changed = DB::transaction(function () use ($membershipApplication, $target, $note, $applicantText, $request): bool {
            $locked = MembershipApplication::query()->whereKey($membershipApplication->id)->lockForUpdate()->firstOrFail();
            if (! in_array($target, MembershipApplication::TRANSITIONS[$locked->status] ?? [], true)) {
                return false; // another admin moved it on in the meantime
            }

            $locked->forceFill([
                'status' => $target,
                'rejection_reason' => $target === 'rejected' ? $applicantText : $locked->rejection_reason,
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(),
            ])->save();

            // The status entry carries the text the APPLICANT was sent (for a request for information or a rejection);
            // an internal note is its own entry, so the two can never be confused when the history is read later.
            if ($applicantText !== null) {
                ApprovalHistory::record($locked, $target, $request->user(), $applicantText);
                if ($note !== null) {
                    ApprovalHistory::record($locked, 'note', $request->user(), $note);
                }
            } else {
                ApprovalHistory::record($locked, $target, $request->user(), $note);
            }

            return true;
        });

        if (! $changed) {
            return $back->with('error', __('admin.registry.errors.changed_meanwhile'));
        }

        if (in_array($target, ['rejected', 'need_information'], true)) {
            dispatch(new SendMembershipDecisionNotifications($membershipApplication->id, $target, $applicantText, adminId: $request->user()->id))->afterResponse();
        }

        return $back->with('success', __('admin.flash.application_status_updated_generic'));
    }

    private function approve(Request $request, MembershipApplication $application, ?string $note, ?int $confirmedMemberId): RedirectResponse
    {
        try {
            $result = $this->approvals->approve($application, $request->user(), $note, $confirmedMemberId);
        } catch (MembershipApprovalBlocked $blocked) {
            return back()->with('error', $blocked->adminMessage());
        }

        $back = redirect()->route('admin.membership.show', $application);
        if ($result->alreadyApproved) {
            return $back->with('status', __('admin.registry.flash.already_approved', ['code' => $result->membership?->member_code ?? '—']));
        }

        // A new account gets the password-setup link; so does a linked one that has never signed in (it may never have
        // received or used its first link). Someone who already uses the portal simply keeps signing in.
        $invite = $result->member !== null && ($result->memberCreated || $result->member->last_login_at === null) ? $result->member->id : null;
        dispatch(new SendMembershipDecisionNotifications($application->id, 'approved', null, $invite, $request->user()->id))->afterResponse();

        return $back->with('success', __('admin.registry.flash.approved', ['code' => $result->membership?->member_code ?? '—']));
    }

    /** An internal note on its own, without changing the status. Admin-only; never e-mailed, never public. */
    public function addNote(Request $request, MembershipApplication $membershipApplication): RedirectResponse
    {
        $data = $request->validate(['note' => ['required', 'string', 'max:2000']], [], ['note' => __('admin.fields.internal_note')]);

        ApprovalHistory::record($membershipApplication, 'note', $request->user(), trim($data['note']));

        return back()->with('success', __('admin.registry.flash.note_added'));
    }

    /**
     * The applicant's photo, streamed from the PRIVATE disk to a signed-in admin holding membership.view (enforced on
     * the route) — never a public URL, never cached by a shared cache.
     */
    public function photo(MembershipApplication $membershipApplication): StreamedResponse
    {
        $path = $membershipApplication->photoPath();
        abort_unless($path !== null, 404);
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $mime = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'][$extension] ?? 'application/octet-stream';

        return $this->documents->response($path, "{$membershipApplication->application_no}.{$extension}", $mime, true);
    }

    /**
     * §8: cash-only for now — records what was actually received, never a
     * free-text confirmation. Recording is separate from verification (§9)
     * so the same person recording a cash drop doesn't also self-certify it.
     */
    public function recordPayment(Request $request, MembershipApplication $membershipApplication): RedirectResponse
    {
        $data = $request->validate([
            'amount_expected' => ['required', 'numeric', 'min:0'],
            'amount_received' => ['required', 'numeric', 'min:0'],
            'received_at' => ['required', 'date'],
            'reference' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $payment = $membershipApplication->payments()->create([
            ...$data,
            'membership_type_id' => $membershipApplication->membership_type_id,
            'method' => 'cash',
            'received_by' => $request->user()->id,
            'status' => 'paid',
        ]);

        ApprovalHistory::record($membershipApplication, 'payment_recorded', $request->user(), $this->paymentSummary($payment));

        return back()->with('success', __('admin.flash.cash_payment_recorded'));
    }

    /** §9: a second, explicit action — the recorder and the verifier are never forced to be the same click. */
    public function verifyPayment(Request $request, Payment $payment): RedirectResponse
    {
        abort_unless($payment->payable_type === MembershipApplication::class, 404);

        $verified = DB::transaction(function () use ($payment, $request): bool {
            $locked = Payment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();
            if ($locked->verified_at !== null || $locked->status !== 'paid') {
                return false; // already verified (a double click, a retried request) — verified once, recorded once
            }
            $locked->update(['verified_at' => now(), 'verified_by' => $request->user()->id]);
            ApprovalHistory::record($locked->payable, 'payment_verified', $request->user(), $this->paymentSummary($locked));

            return true;
        });

        return back()->with($verified ? 'success' : 'status', $verified ? __('admin.flash.payment_verified') : __('admin.registry.flash.payment_already_verified'));
    }

    /**
     * §9: honorary/waived — a single explicit action (not a create-then-
     * waive dance), always requires a reason, and is fully attributed. Only
     * for an application with no payment recorded yet; an already-recorded
     * payment is corrected by re-recording, not retroactively waived.
     */
    public function waiveApplicationPayment(Request $request, MembershipApplication $membershipApplication): RedirectResponse
    {
        if ($membershipApplication->payments()->exists()) {
            return back()->with('error', __('admin.fields.payment_already_recorded_cannot_waive'));
        }

        $data = $request->validate(['waiver_reason' => ['required', 'string', 'max:1000']]);

        $membershipApplication->payments()->create([
            'membership_type_id' => $membershipApplication->membership_type_id,
            // The fee this application was QUOTED when it was submitted — never the type's current policy, which may
            // have changed since (historical fee safety; see MembershipApplication::booted()).
            'amount_expected' => $membershipApplication->quotedRegistrationFee() ?? 0,
            'method' => 'cash',
            'status' => 'waived',
            'waiver_reason' => $data['waiver_reason'],
            'waived_by' => $request->user()->id,
            'verified_at' => now(),
            'verified_by' => $request->user()->id,
        ]);

        ApprovalHistory::record($membershipApplication, 'payment_waived', $request->user(), $data['waiver_reason']);

        return back()->with('success', __('admin.flash.payment_waived'));
    }

    /**
     * What a payment history entry records — amounts and the reference as data (JSON), so the history shows them with
     * the viewing admin's digits and words (App\Support\MembershipHistory), not the recording admin's.
     */
    private function paymentSummary(Payment $payment): string
    {
        return (string) json_encode(['payment' => [
            'received' => Money::parse((string) $payment->amount_received),
            'expected' => Money::parse((string) $payment->amount_expected),
            'reference' => $payment->reference ?: null,
        ]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function clean(?string $text): ?string
    {
        $text = $text === null ? null : trim($text);

        return $text === '' ? null : $text;
    }
}
