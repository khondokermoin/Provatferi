<?php

namespace App\Services;

use App\Models\Member;
use App\Models\Membership;

/** The outcome of MembershipApprovalService::approve(). `alreadyApproved`: a retry — nothing was written. */
final class MembershipApprovalResult
{
    public function __construct(
        public readonly ?Membership $membership,
        public readonly ?Member $member,
        public readonly bool $memberCreated,
        public readonly bool $alreadyApproved = false,
    ) {
    }
}
