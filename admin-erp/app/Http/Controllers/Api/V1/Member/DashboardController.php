<?php

namespace App\Http\Controllers\Api\V1\Member;

use App\Http\Controllers\Controller;
use App\Models\Member;
use App\Models\Membership;
use App\Models\MembershipDue;
use App\Models\PaymentReceipt;
use App\Services\MembershipDueLedger;
use App\Services\MembershipDueSchedule;
use App\Services\PaymentReceiptService;
use App\Support\MembershipPaymentState;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * §11: profile/membership/payment/library sections — explicitly NOT the
 * admin dashboard, and the library section is an honest empty placeholder
 * rather than fabricated data, since no library/borrowing system exists
 * anywhere in this app yet. Every field below is picked explicitly (§39),
 * never a raw model/relation serialize.
 */
class DashboardController extends Controller
{
    public function me(Request $request, MembershipDueLedger $ledger, PaymentReceiptService $receipts): JsonResponse
    {
        /** @var Member $member */
        $member = $request->user();

        // Any monthly due owed and not created yet (the daily run has not reached it) is created first — idempotent.
        foreach ($member->memberships()->pluck('id') as $membershipId) {
            $ledger->generateFor((int) $membershipId);
        }

        $member->load([
            'memberships' => fn ($q) => $q->latest('start_date'),
            'memberships.membershipType:id,name,slug',
            'memberships.application.payments',
            'seasonHistory.season:id,name,slug',
        ]);

        return response()->json(['data' => [
            'profile' => [
                'member_code' => $member->member_code,
                'name' => $member->name,
                'email' => $member->email,
                'phone' => $member->phone,
                'status' => $member->status,
                'public_profile_enabled' => $member->public_profile_enabled,
                'public_profile_approved' => $member->public_profile_approved,
                'public_slug' => $member->public_slug,
            ],
            'memberships' => $member->memberships->map(fn ($m) => [
                'member_code' => $m->member_code,
                'status' => $m->status,
                'start_date' => $m->start_date,
                'expiry_date' => $m->expiry_date,
                'membership_type' => $m->membershipType?->name,
                // The registration fee this membership's application was quoted, and where it stands
                // (App\Support\MembershipPaymentState): a zero fee reads "not_required" — never an unpaid debt.
                'registration_fee' => $m->application?->quotedRegistrationFee(),
                'payment_state' => MembershipPaymentState::of($m->application),
                'monthly' => $this->monthly($ledger, $m),
            ])->values(),
            'season_history' => $member->seasonHistory->map(fn ($h) => [
                'season' => $h->season?->name,
                'joined_at' => $h->joined_at,
            ])->values(),
            'payments' => $member->memberships
                ->flatMap(fn ($m) => $m->application?->payments ?? collect())
                ->map(fn ($p) => [
                    'amount_received' => $p->amount_received,
                    'method' => $p->method,
                    'status' => $p->status,
                    'received_at' => $p->received_at,
                ])
                ->values(),
            // The member's official receipts (Membership task 5), newest first. Only what the member should see: the
            // number, what it was for, the amount, the day, the months — never who received or verified it. The PDF
            // itself is fetched through GET /member/receipts/{number}/pdf, found only among the member's own.
            'receipts' => $receipts->forMember($member)->orderByDesc('issued_at')->orderByDesc('id')->limit(100)->get()
                ->map(fn (PaymentReceipt $r) => [
                    'receipt_no' => $r->receipt_no,
                    'purpose' => $r->purpose,
                    'amount' => Money::parse((string) $r->amount),
                    'credit' => Money::parse((string) $r->credit_amount),
                    'payment_date' => $r->payment_date->toDateString(),
                    'periods' => $r->periods(),
                ])->values(),
            'library' => ['transactions' => []],
        ]]);
    }

    /**
     * The member's own view of their monthly contribution (Membership task 4): amounts as decimal strings, months as
     * "2026-10", states as words the site translates. Never an internal note, a verifier or a reference.
     *
     * required        a monthly contribution is charged now (the policy's amount is above zero and the membership active)
     * month_state     this month: paid | partially_paid | due | waived | not_required (no due this month)
     * recent          the last 12 months owed, newest first; `state` adds "overdue" for a past month still owed
     *
     * @return array<string, mixed>
     */
    private function monthly(MembershipDueLedger $ledger, Membership $membership): array
    {
        $summary = $ledger->summary($membership);

        return [
            'current_period' => MembershipDueSchedule::key(...$summary['current_period']),
            'current_amount' => $summary['current_amount'],
            'required' => $summary['accruing'] && $summary['current_amount'] !== null && Money::isPositive($summary['current_amount']),
            'month_state' => $summary['month_state'],
            'outstanding' => $summary['outstanding'],
            'overdue_count' => $summary['overdue_count'],
            'credit' => $summary['credit'],
            'recent' => $summary['dues']->take(12)->map(fn (MembershipDue $due) => [
                'period' => $due->period(),
                'amount' => Money::parse((string) $due->amount),
                'paid' => Money::parse((string) $due->paid_amount),
                'waived' => Money::parse((string) $due->waived_amount),
                'outstanding' => $due->outstanding(),
                'state' => $due->displayState($summary['today']),
            ])->values(),
        ];
    }
}
