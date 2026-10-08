<?php

namespace App\Services;

use App\Models\ApprovalHistory;
use App\Models\Payment;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Verifying the money received for a membership APPLICATION's registration fee (the cash payment an admin recorded on
 * the application page). Moved out of MembershipController (Membership task 5) so that the registration fee and the
 * monthly contributions (MembershipDueLedger::verifyPayment) verify the same way and both issue the official receipt in
 * the verifying transaction — and so the race between two admins can be tested with real concurrent processes.
 *
 * Recording and verifying stay two separate actions: the person who records a cash drop never self-certifies it.
 */
final class ApplicationPaymentService
{
    public function __construct(private readonly PaymentReceiptService $receipts)
    {
    }

    /**
     * Verifies a recorded payment — once. The payment's row is locked first; a repeat, a double click or a second admin
     * at the same moment finds it verified and changes nothing (false). A waiver, a cancelled or an unrecorded payment
     * is not verifiable (false) either. On success the receipt is issued in the same transaction: the number is taken
     * only if the verification commits.
     */
    public function verify(Payment $payment, User $by): bool
    {
        return DB::transaction(function () use ($payment, $by): bool {
            $locked = Payment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();
            if ($locked->verified_at !== null || $locked->status !== 'paid') {
                return false;
            }

            $locked->update(['verified_at' => now(), 'verified_by' => $by->id]);
            ApprovalHistory::record($locked->payable, 'payment_verified', $by, self::summary($locked));
            $this->receipts->issueFor($locked, $by);

            return true;
        }, 3);
    }

    /**
     * What a payment history entry records — amounts and the reference as data (JSON), so the history shows them with
     * the viewing admin's digits and words (App\Support\MembershipHistory), not the recording admin's.
     */
    public static function summary(Payment $payment): string
    {
        return (string) json_encode(['payment' => [
            'received' => Money::parse((string) $payment->amount_received),
            'expected' => Money::parse((string) $payment->amount_expected),
            'reference' => $payment->reference ?: null,
        ]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
