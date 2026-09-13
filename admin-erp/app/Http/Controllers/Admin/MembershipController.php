<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ApprovalHistory;
use App\Models\Member;
use App\Models\MemberSeasonHistory;
use App\Models\Membership;
use App\Models\MembershipApplication;
use App\Models\MembershipType;
use App\Models\Payment;
use App\Notifications\MembershipApplicationStatusChangedNotification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Throwable;

class MembershipController extends Controller
{
    /**
     * Explicit transition map so an application can't jump straight from
     * pending to approved, or be revived after a terminal state.
     *
     * @var array<string, array<int, string>>
     */
    private const TRANSITIONS = [
        'pending' => ['under_review', 'cancelled'],
        'under_review' => ['need_information', 'approved', 'rejected', 'cancelled'],
        'need_information' => ['under_review', 'approved', 'rejected', 'cancelled'],
        'approved' => [],
        'rejected' => [],
        'cancelled' => [],
    ];

    public function index(Request $request): View
    {
        $filters = [
            'search' => trim((string) $request->query('search', '')),
            'status' => (string) $request->query('status', ''),
            'type' => (string) $request->query('type', ''),
            'date' => (string) $request->query('date', ''),
        ];

        $applications = MembershipApplication::query()
            ->with(['user', 'membershipType', 'season', 'payments'])
            ->when($filters['search'] !== '', function ($q) use ($filters) {
                $term = '%'.$filters['search'].'%';
                $q->where(fn ($w) => $w->where('application_no', 'like', $term)
                    ->orWhere('applicant_name', 'like', $term)
                    ->orWhere('applicant_email', 'like', $term)
                    ->orWhereHas('user', fn ($u) => $u->where('name', 'like', $term)->orWhere('email', 'like', $term)));
            })
            ->when($filters['status'] !== '', fn ($q) => $q->where('status', $filters['status']))
            ->when($filters['type'] !== '', fn ($q) => $q->where('membership_type_id', $filters['type']))
            ->when($filters['date'] !== '', fn ($q) => $q->whereDate('created_at', $filters['date']))
            ->latest()
            ->paginate(15)
            ->withQueryString();

        return view('admin.membership.index', [
            'title' => 'সদস্যপদ আবেদন',
            'breadcrumbs' => [['label' => 'সদস্যপদ'], ['label' => 'আবেদনসমূহ']],
            'applications' => $applications,
            'filters' => $filters,
            'statuses' => MembershipApplication::STATUSES,
            'types' => MembershipType::query()->orderBy('name')->pluck('name', 'id'),
        ]);
    }

    public function show(MembershipApplication $membershipApplication): View
    {
        $membershipApplication->load(['user', 'membershipType', 'organizationUnit', 'reviewer', 'season', 'payments', 'history.actor']);

        return view('admin.membership.show', [
            'title' => $membershipApplication->application_no,
            'breadcrumbs' => [['label' => 'আবেদনসমূহ', 'route' => 'admin.membership.index'], ['label' => $membershipApplication->application_no]],
            'application' => $membershipApplication,
            'allowedTransitions' => self::TRANSITIONS[$membershipApplication->status] ?? [],
            'statuses' => MembershipApplication::STATUSES,
            'paymentSatisfied' => $this->paymentSatisfied($membershipApplication),
        ]);
    }

    public function updateStatus(Request $request, MembershipApplication $membershipApplication): RedirectResponse
    {
        $allowed = self::TRANSITIONS[$membershipApplication->status] ?? [];

        // Rule::in([]) correctly rejects every value when the application is
        // in a terminal state — no fallback to the full status list here,
        // or an empty $allowed would silently accept anything.
        $data = $request->validate([
            'status' => ['required', Rule::in($allowed)],
            'review_notes' => ['nullable', 'string', 'max:2000'],
            'rejection_reason' => ['required_if:status,rejected', 'nullable', 'string', 'max:1000'],
        ]);

        // §9: approval is blocked until payment is satisfied (paid+verified,
        // or an explicit waiver) unless the type is genuinely free (fee-less
        // types, e.g. honorary, never had a payment record to satisfy).
        if ($data['status'] === 'approved' && !$this->paymentSatisfied($membershipApplication)) {
            return back()->with('error', 'পরিশোধ যাচাই না হওয়া পর্যন্ত অনুমোদন করা যাবে না — আগে পেমেন্ট যাচাই করুন অথবা মওকুফ রেকর্ড করুন।');
        }

        $membershipApplication->update([
            'status' => $data['status'],
            'review_notes' => $data['review_notes'] ?? $membershipApplication->review_notes,
            'rejection_reason' => $data['status'] === 'rejected' ? $data['rejection_reason'] : $membershipApplication->rejection_reason,
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
        ]);

        ApprovalHistory::record($membershipApplication, $data['status'], $request->user(), $data['review_notes'] ?? $data['rejection_reason'] ?? null);

        if ($data['status'] === 'approved') {
            $this->createMembership($membershipApplication, $request->user()->id);
        }

        if (in_array($data['status'], ['approved', 'rejected', 'need_information'], true)) {
            $this->notifyApplicant($membershipApplication, $data['status'], $data['review_notes'] ?? $data['rejection_reason'] ?? null);
        }

        return redirect()->route('admin.membership.show', $membershipApplication)
            ->with('success', 'আবেদনের স্ট্যাটাস হালনাগাদ হয়েছে।');
    }

    /** §40: an email-transport failure must never turn an already-persisted status change into a 500. */
    private function notifyApplicant(MembershipApplication $application, string $status, ?string $note): void
    {
        $email = $application->applicantDisplayEmail();
        if ($email === '') {
            return;
        }

        try {
            Notification::route('mail', $email)
                ->notify(new MembershipApplicationStatusChangedNotification($application->application_no, $status, $note));
        } catch (Throwable $e) {
            Log::warning('Membership application status notification failed to send.', [
                'application_id' => $application->id, 'status' => $status, 'error' => $e->getMessage(),
            ]);
        }
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

        $membershipApplication->payments()->create([
            ...$data,
            'membership_type_id' => $membershipApplication->membership_type_id,
            'method' => 'cash',
            'received_by' => $request->user()->id,
            'status' => 'paid',
        ]);

        return back()->with('success', 'নগদ পরিশোধ রেকর্ড করা হয়েছে — এখন যাচাই করুন।');
    }

    /** §9: a second, explicit action — the recorder and the verifier are never forced to be the same click. */
    public function verifyPayment(Request $request, Payment $payment): RedirectResponse
    {
        abort_unless($payment->payable_type === MembershipApplication::class, 404);

        $payment->update(['verified_at' => now(), 'verified_by' => $request->user()->id]);

        return back()->with('success', 'পরিশোধ যাচাই করা হয়েছে।');
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
            return back()->with('error', 'ইতিমধ্যে একটি পরিশোধ রেকর্ড আছে — নতুন করে মওকুফ করা যাবে না।');
        }

        $data = $request->validate(['waiver_reason' => ['required', 'string', 'max:1000']]);

        $membershipApplication->payments()->create([
            'membership_type_id' => $membershipApplication->membership_type_id,
            'amount_expected' => $membershipApplication->membershipType?->fee ?? 0,
            'method' => 'cash',
            'status' => 'waived',
            'waiver_reason' => $data['waiver_reason'],
            'waived_by' => $request->user()->id,
            'verified_at' => now(),
            'verified_by' => $request->user()->id,
        ]);

        return back()->with('success', 'পরিশোধ মওকুফ রেকর্ড করা হয়েছে।');
    }

    /**
     * §9: a free membership type (fee = 0, e.g. honorary) never required a
     * payment in the first place — satisfied trivially. A fee-bearing type
     * requires at least one recorded payment, and every recorded payment
     * must be paid+verified or explicitly waived; a fee-bearing application
     * with zero payment records yet is NOT satisfied (approval stays
     * blocked until one exists), which an empty-collection check alone
     * would have missed.
     */
    private function paymentSatisfied(MembershipApplication $application): bool
    {
        if ((float) ($application->membershipType?->fee ?? 0) <= 0) {
            return true;
        }

        $payments = $application->payments;
        if ($payments->isEmpty()) {
            return false;
        }

        return $payments->every(fn ($payment) => $payment->isSatisfied());
    }

    /**
     * Safe creation of the Member/Membership record on approval — one
     * membership per application, guarded by a DB transaction against a
     * double-submit. Reuses the SAME "PF-{year}-{4-digit}" member_code
     * format this method already generated before the public-application
     * path existed (§10: "if a generator already exists, reuse it") for
     * BOTH identity paths (§0): an internal admin-created `users` account
     * (`user_id`, unchanged) or a public applicant with no ERP account
     * (`member_id`, new — creates or reuses a Member row by email).
     */
    private function createMembership(MembershipApplication $application, int $approvedBy): void
    {
        DB::transaction(function () use ($application, $approvedBy) {
            if (Membership::query()->where('membership_application_id', $application->id)->exists()) {
                return;
            }

            $memberId = null;
            if ($application->isPublicApplicant()) {
                $member = $this->findOrCreateMember($application);
                $memberId = $member->id;

                if ($application->membership_season_id) {
                    MemberSeasonHistory::query()->firstOrCreate(
                        ['member_id' => $member->id, 'membership_season_id' => $application->membership_season_id],
                        ['membership_application_id' => $application->id, 'joined_at' => now()->toDateString()],
                    );
                }
            }

            $nextNumber = (Membership::query()->max('id') ?? 0) + 1;

            Membership::query()->create([
                'membership_application_id' => $application->id,
                'user_id' => $application->user_id,
                'member_id' => $memberId,
                'membership_type_id' => $application->membership_type_id,
                'member_code' => 'PF-'.now()->format('Y').'-'.str_pad((string) $nextNumber, 4, '0', STR_PAD_LEFT),
                'start_date' => now()->toDateString(),
                'status' => 'active',
                'approved_by' => $approvedBy,
                'approved_at' => now(),
            ]);
        });
    }

    /**
     * A public applicant becomes a portal Member only on approval — there is
     * no "member" before that, only an applicant (§7/§11). Reuses an
     * existing Member by email if this is a renewal/repeat application
     * rather than creating a duplicate. The random password is never
     * emailed in plaintext; the member sets their own via the normal
     * password-reset flow (Phase 8 wires the actual email send).
     */
    private function findOrCreateMember(MembershipApplication $application): Member
    {
        $existing = Member::query()->where('email', $application->applicant_email)->first();
        if ($existing) {
            return $existing;
        }

        return Member::query()->create([
            'name' => $application->applicant_name,
            'email' => $application->applicant_email,
            'phone' => $application->applicant_phone,
            'password' => Hash::make(Str::random(40)),
            'status' => 'active',
        ]);
    }
}
