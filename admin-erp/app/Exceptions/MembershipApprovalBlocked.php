<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Approval refused for a reason the admin can act on — never a server error. `reason` is one of:
 *   status                 the application is not under review / waiting for information (or is already final)
 *   payment                the registration fee is not settled (recorded AND verified, or waived), and is not zero
 *   conflict               the applicant matches existing member records in a way that cannot be resolved by linking
 *   confirmation_required  the applicant matches ONE existing member on a single identifier: an admin must confirm it
 *                          is the same person before the application is linked to that account
 * `detail` narrows it (the payment state, or the conflict kind) for the message shown.
 */
class MembershipApprovalBlocked extends RuntimeException
{
    public function __construct(public readonly string $reason, public readonly ?string $detail = null)
    {
        parent::__construct("Membership approval blocked: {$reason}".($detail !== null ? " ({$detail})" : ''));
    }

    /** The admin-facing explanation, in the admin's language. */
    public function adminMessage(): string
    {
        return match ($this->reason) {
            'payment' => __('admin.registry.blocked.payment'),
            'conflict' => __('admin.registry.blocked.conflict'),
            'confirmation_required' => __('admin.registry.blocked.confirmation_required'),
            default => __('admin.registry.blocked.status'),
        };
    }
}
