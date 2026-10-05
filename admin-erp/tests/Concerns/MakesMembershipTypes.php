<?php

namespace Tests\Concerns;

use App\Models\MembershipFeePolicy;
use App\Models\MembershipType;

/**
 * Test fixtures for membership types under the fee-policy model (2026-10-05).
 *
 * A type is only offered publicly — and an application only gets a fee quote — if it has a fee policy in force. So a
 * fixture type gets one, written straight into the table (not through MembershipFeePolicyService, whose rules forbid a
 * past start date) with a start date long before any date a test uses. Pass $fees = null for a type that has none.
 */
trait MakesMembershipTypes
{
    /**
     * @param  array<string, mixed>  $attributes  overrides for the type's columns
     * @param  array{registration: string, monthly: string}|null  $fees  the policy in force; null = no policy at all
     */
    protected function makeMembershipType(array $attributes = [], ?array $fees = ['registration' => '0', 'monthly' => '0']): MembershipType
    {
        $type = MembershipType::query()->create($attributes + [
            'name' => 'সাধারণ সদস্য',
            'slug' => 'type-'.uniqid(),
            'status' => 'active',
            'sort_order' => 1,
        ]);

        if ($fees !== null) {
            $this->makeFeePolicy($type, $fees['registration'], $fees['monthly']);
        }

        return $type;
    }

    /** An open-ended active policy for a type, in force from long ago unless a start date is given. */
    protected function makeFeePolicy(MembershipType $type, string $registration, string $monthly, string $from = '2000-01-01', ?string $until = null, bool $active = true): MembershipFeePolicy
    {
        return MembershipFeePolicy::query()->create([
            'membership_type_id' => $type->id,
            'registration_fee' => $registration,
            'monthly_contribution' => $monthly,
            'effective_from' => $from,
            'effective_until' => $until,
            'active' => $active,
            'note' => 'test fixture',
        ]);
    }
}
