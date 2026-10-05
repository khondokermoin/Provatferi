<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Controller;
use App\Models\MembershipFeePolicy;
use App\Models\MembershipSeason;
use App\Services\MembershipFeePolicyService;
use Illuminate\Http\JsonResponse;

/**
 * §41: public read contract for the membership registration page. A season's
 * own `status` column is authoritative (MembershipSeason::acceptsApplicationsNow()),
 * never derived purely from dates — so "current" here can legitimately be
 * more than one season at once (a regular season and a special one-off
 * drive running in parallel, §2-4), hence `data` is always an array, never
 * a single nullable object.
 */
class MembershipCampaignController extends Controller
{
    public function current(MembershipFeePolicyService $fees): JsonResponse
    {
        $seasons = MembershipSeason::query()
            ->where('status', 'open')
            ->with(['membershipTypes' => fn ($q) => $q->where('status', 'active')->where('is_public_self_apply', true)->where('is_public_visible', true)->orderBy('sort_order')])
            ->orderBy('display_order')->orderBy('opens_at')
            ->get()
            ->filter(fn (MembershipSeason $season) => $season->acceptsApplicationsNow())
            ->values();

        // One query for the fee policy in force today of every type offered in any open season. A type with none in
        // force is left out of the form: a price that does not exist cannot be quoted.
        $policies = $fees->effectiveForMany($seasons->flatMap(fn (MembershipSeason $season) => $season->membershipTypes));

        return response()->json(['data' => $seasons->map(fn (MembershipSeason $season) => $this->publicPayload($season, $policies))]);
    }

    /**
     * @param  array<int, MembershipFeePolicy>  $policies  fee policy in force today, keyed by membership type id
     * @return array<string, mixed>
     */
    private function publicPayload(MembershipSeason $season, array $policies): array
    {
        return [
            'id' => $season->id,
            'name' => $season->name,
            'name_en' => $season->name_en,
            'slug' => $season->slug,
            'campaign_type' => $season->campaign_type,
            'opens_at' => $season->opens_at,
            'closes_at' => $season->closes_at,
            'description' => $season->description,
            'cash_payment_instructions' => $season->cash_payment_instructions,
            'public_profile_opt_in' => $season->public_profile_opt_in,
            // `fee` is the DEPRECATED alias of registration_fee (see Api\V1\MembershipTypeController) kept for pre-2026-10-05 builds of the public site.
            'membership_types' => $season->membershipTypes
                ->filter(fn ($type) => isset($policies[$type->id]))
                ->map(fn ($type) => [
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
                ])->values(),
        ];
    }
}
