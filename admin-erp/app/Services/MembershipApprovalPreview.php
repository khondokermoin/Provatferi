<?php

namespace App\Services;

use App\Models\Member;
use App\Models\Membership;

/**
 * What approving an application WOULD do, worked out before anything is written — shown on the review page, and
 * re-computed inside the approval transaction so the two can never disagree.
 *
 * identity:
 *   new            no existing member account matches: a new one is created (and a password-setup link sent)
 *   link           one existing account matches on BOTH e-mail and mobile: the membership is linked to it
 *   confirm        one existing account matches on ONE identifier only: linking needs an admin's explicit confirmation
 *   conflict       cannot be resolved by approving (see `conflict`); approval is blocked
 *   staff_account  the application came from an internal ERP user account (the original admin-side path): no portal
 *                  member is involved
 *
 * conflict:
 *   two_members     the e-mail belongs to one member account and the mobile to another
 *   removed_account the matching account was removed (soft-deleted)
 *   already_member  the person already holds an active or suspended membership
 */
final class MembershipApprovalPreview
{
    /**
     * @param  array<int, string>  $matchedBy  which identifiers matched the existing account: 'email', 'phone'
     */
    public function __construct(
        public readonly bool $statusAllowsApproval,
        public readonly string $paymentState,
        public readonly bool $paymentSettled,
        public readonly bool $shortPaid,
        public readonly string $identity,
        public readonly ?Member $member = null,
        public readonly array $matchedBy = [],
        public readonly ?string $conflict = null,
        public readonly ?Member $otherMember = null,
        public readonly ?Membership $existingMembership = null,
        public readonly bool $nameDiffers = false,
    ) {
    }

    public function canApprove(): bool
    {
        return $this->statusAllowsApproval && $this->paymentSettled && $this->identity !== 'conflict';
    }

    public function needsConfirmation(): bool
    {
        return $this->identity === 'confirm';
    }
}
