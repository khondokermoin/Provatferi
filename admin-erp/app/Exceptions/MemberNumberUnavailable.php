<?php

namespace App\Exceptions;

use App\Models\MembershipType;
use RuntimeException;

/**
 * A member number cannot be issued for this membership type: it has no code (or not a valid one), and the code is part
 * of every member number. Approval checks this first and refuses with an explanation (MembershipApprovalBlocked
 * 'numbering'); this exception is the last guard, should anything else ever ask for a number.
 */
class MemberNumberUnavailable extends RuntimeException
{
    public function __construct(public readonly ?MembershipType $type)
    {
        parent::__construct('No member number can be issued for membership type '.($type?->id ?? 'none').': it has no valid code.');
    }
}
