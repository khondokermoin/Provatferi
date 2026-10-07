<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Membership;
use App\Models\MembershipDue;
use App\Models\Payment;
use App\Services\MembershipDueLedger;
use App\Services\MembershipFeePolicyService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The admin's monthly-contribution actions on a member (Membership task 4): generate / update the dues, record money
 * received, verify it (a separate action, `payments.approve`), cancel an unverified entry recorded in error, and waive
 * a month with a reason. The rules live in App\Services\MembershipDueLedger; every action there is idempotent and runs
 * under the membership's row lock.
 */
class MemberDuesController extends Controller
{
    public function __construct(
        private readonly MembershipDueLedger $ledger,
        private readonly MembershipFeePolicyService $fees,
    ) {
    }

    /** "Generate / update dues" for one member. Creates only what is owed and missing. */
    public function generate(Request $request, Membership $membership): RedirectResponse
    {
        $created = $this->ledger->generateFor($membership, $request->user());

        return back()->with($created === [] ? 'status' : 'success', $created === []
            ? __('admin.dues.flash.nothing_to_generate')
            : __('admin.dues.flash.generated', ['count' => bn_number(count($created))]));
    }

    /** "Generate / update dues" for every member, from the registry. */
    public function generateAll(Request $request): RedirectResponse
    {
        $report = $this->ledger->generateAll($request->user());

        return back()->with('success', __('admin.dues.flash.generated_all', [
            'count' => bn_number($report['created']), 'members' => bn_number($report['memberships']),
        ]));
    }

    public function storePayment(Request $request, Membership $membership): RedirectResponse
    {
        $data = $request->validate([
            'purpose' => ['required', Rule::in(['due', 'advance', 'voluntary'])],
            'due_id' => ['nullable', 'integer', 'required_if:purpose,due'],
            'amount' => ['required', 'string', 'max:20'],
            'received_at' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.$this->fees->today()],
            'method' => ['required', Rule::in(Payment::METHODS)],
            'reference' => ['nullable', 'string', 'max:255'],
            'keep_rest_as_credit' => ['nullable', 'boolean'],
        ], [], [
            'purpose' => __('admin.dues.fields.purpose'), 'due_id' => __('admin.dues.fields.month'), 'amount' => __('admin.dues.fields.amount'),
            'received_at' => __('admin.dues.fields.received_at'), 'method' => __('admin.dues.fields.method'), 'reference' => __('admin.dues.fields.reference'),
        ]);

        $this->ledger->recordPayment($membership, $request->user(), $data);

        return back()->with('success', __('admin.dues.flash.payment_recorded'));
    }

    public function verifyPayment(Request $request, Membership $membership, Payment $payment): RedirectResponse
    {
        abort_unless($payment->payable_type === Membership::class && (int) $payment->payable_id === $membership->id, 404);

        return match ($this->ledger->verifyPayment($payment, $request->user())) {
            'verified' => back()->with('success', __('admin.dues.flash.payment_verified')),
            'already' => back()->with('status', __('admin.dues.flash.payment_already_verified')),
            default => back()->with('error', __('admin.dues.errors.cannot_verify')),
        };
    }

    public function cancelPayment(Request $request, Membership $membership, Payment $payment): RedirectResponse
    {
        abort_unless($payment->payable_type === Membership::class && (int) $payment->payable_id === $membership->id, 404);
        $data = $request->validate(['cancellation_reason' => ['required', 'string', 'max:1000']], [], ['cancellation_reason' => __('admin.fields.reason')]);

        return match ($this->ledger->cancelPayment($payment, $request->user(), trim($data['cancellation_reason']))) {
            'cancelled' => back()->with('success', __('admin.dues.flash.payment_cancelled')),
            'already' => back()->with('status', __('admin.registry.flash.no_change')),
            default => back()->with('error', __('admin.dues.errors.cannot_cancel')),
        };
    }

    public function waive(Request $request, Membership $membership, MembershipDue $due): RedirectResponse
    {
        abort_unless($due->membership_id === $membership->id, 404);
        $data = $request->validate([
            'waive_amount' => ['nullable', 'string', 'max:20'],
            'waiver_reason' => ['required', 'string', 'max:1000'],
        ], [], ['waive_amount' => __('admin.dues.fields.waive_amount'), 'waiver_reason' => __('admin.fields.reason')]);

        $outcome = $this->ledger->waive($due, $request->user(), $data['waive_amount'] ?? null, trim($data['waiver_reason']));

        return $outcome === 'waived'
            ? back()->with('success', __('admin.dues.flash.waived'))
            : back()->with('status', __('admin.dues.flash.nothing_owed'));
    }
}
