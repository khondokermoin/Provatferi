<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\MembershipType;
use App\Services\MembershipFeePolicyService;
use Illuminate\Http\JsonResponse;

/**
 * Public read-only contract. 2026-09-08: ordered by sort_order and restricted to a public-safe column list —
 * applications, approval workflow, and any internal notes live entirely on MembershipApplication, which has no public
 * route at all.
 *
 * 2026-10-05 (membership fee policies): the fees are the policy IN FORCE TODAY (organisation calendar), never a stored
 * flat column:
 *   registration_fee / monthly_contribution  decimal STRINGS, e.g. "500.00" (never floats)
 *   fee_effective_from                       the day the quoted policy took effect ('Y-m-d')
 *   fee                                      DEPRECATED alias of registration_fee, kept so a build of the public site from
 *                                            before this change (whose response check requires `fee`) keeps rendering
 * A type is listed only if it is active, public_visible AND has a policy in force: a price that does not exist cannot
 * be shown, so such a type is simply not offered until its policy begins.
 */
class MembershipTypeController extends Controller
{
    public function index(MembershipFeePolicyService $fees): JsonResponse
    {
        $types = MembershipType::query()
            ->where('status', 'active')
            ->where('is_public_visible', true)
            ->orderBy('sort_order')->orderBy('name')
            ->get(['id', 'name', 'name_en', 'slug', 'code', 'description', 'description_en', 'duration_months', 'is_student', 'is_public_self_apply']);

        $policies = $fees->effectiveForMany($types);

        $data = $types
            ->filter(fn (MembershipType $type) => isset($policies[$type->id]))
            ->map(fn (MembershipType $type) => [
                'id' => $type->id,
                'name' => $type->name,
                'name_en' => $type->name_en,
                'slug' => $type->slug,
                'code' => $type->code,
                'description' => $type->description,
                'description_en' => $type->description_en,
                'duration_months' => $type->duration_months,
                'fee' => $policies[$type->id]->registration_fee,
                'registration_fee' => $policies[$type->id]->registration_fee,
                'monthly_contribution' => $policies[$type->id]->monthly_contribution,
                'fee_effective_from' => $policies[$type->id]->fromDate(),
                'is_student' => $type->is_student,
                'is_public_self_apply' => $type->is_public_self_apply,
            ])
            ->values();

        return response()->json(['data' => $data]);
    }
}
