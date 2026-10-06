<?php

namespace App\Support;

use App\Models\MembershipApplication;
use App\Models\Payment;

/**
 * Where an application's (and so a member's) REGISTRATION fee stands, read from what already exists: the fee the
 * application was quoted when it was submitted (its own snapshot) and the Payment records against it. Nothing here
 * creates, changes or assumes a payment — it only names the state, so the registry, the member detail page, the review
 * page and the member portal all say the same thing.
 *
 *   not_required           the quoted registration fee is zero (e.g. Student): no payment exists and none is owed —
 *                          never shown as "unpaid"
 *   paid                   fee-bearing; every recorded payment is paid AND verified by an admin
 *   waived                 fee-bearing; settled, and at least one record is an explicit, reasoned waiver
 *   awaiting_verification  a payment has been recorded but not yet verified (approval stays blocked)
 *   unpaid                 fee-bearing and nothing recorded yet (approval stays blocked)
 *   no_quote               no fee was recorded on the application (a type with no policy that day, or no application
 *                          at all) — never assumed to be free
 *
 * "Settled" (paid / waived / not_required) is exactly MembershipApprovalService's approval rule.
 */
final class MembershipPaymentState
{
    public const NOT_REQUIRED = 'not_required';

    public const PAID = 'paid';

    public const WAIVED = 'waived';

    public const AWAITING_VERIFICATION = 'awaiting_verification';

    public const UNPAID = 'unpaid';

    public const NO_QUOTE = 'no_quote';

    public const ALL = [self::NOT_REQUIRED, self::PAID, self::WAIVED, self::AWAITING_VERIFICATION, self::UNPAID, self::NO_QUOTE];

    public static function of(?MembershipApplication $application): string
    {
        if ($application === null) {
            return self::NO_QUOTE;
        }

        $quoted = $application->quotedRegistrationFee();
        if ($quoted !== null && ! Money::isPositive($quoted)) {
            return self::NOT_REQUIRED;
        }

        $payments = $application->payments;
        if ($payments->isEmpty()) {
            return $quoted === null ? self::NO_QUOTE : self::UNPAID;
        }

        if (! $payments->every(fn (Payment $payment) => $payment->isSatisfied())) {
            return self::AWAITING_VERIFICATION;
        }

        return $payments->contains(fn (Payment $payment) => $payment->status === 'waived') ? self::WAIVED : self::PAID;
    }

    public static function isSettled(string $state): bool
    {
        return in_array($state, [self::NOT_REQUIRED, self::PAID, self::WAIVED], true);
    }

    /**
     * Total actually received AND verified (waivers count nothing), as a two-decimal string. Summed in whole paisa
     * (integers), never floats.
     */
    public static function verifiedReceived(MembershipApplication $application): string
    {
        $paisa = 0;
        foreach ($application->payments as $payment) {
            $amount = $payment->amount_received === null ? null : Money::parse((string) $payment->amount_received);
            if ($payment->status === 'paid' && $payment->verified_at !== null && $amount !== null) {
                $paisa += self::paisa($amount);
            }
        }

        return intdiv($paisa, 100).'.'.str_pad((string) ($paisa % 100), 2, '0', STR_PAD_LEFT);
    }

    /**
     * True when the verified amount received is less than the quoted registration fee. Approval does not depend on it
     * (the existing rule is "paid and verified, or waived"); the review page shows it so a short payment is never missed.
     */
    public static function isShortPaid(MembershipApplication $application): bool
    {
        $quoted = $application->quotedRegistrationFee();
        if ($quoted === null || ! Money::isPositive($quoted) || self::of($application) !== self::PAID) {
            return false;
        }

        return self::paisa(self::verifiedReceived($application)) < self::paisa($quoted);
    }

    /** "500.00" -> 50000. Only ever given an amount Money::parse() produced. */
    private static function paisa(string $amount): int
    {
        [$whole, $fraction] = explode('.', $amount) + [1 => '00'];

        return ((int) $whole) * 100 + (int) str_pad(substr($fraction, 0, 2), 2, '0');
    }
}
