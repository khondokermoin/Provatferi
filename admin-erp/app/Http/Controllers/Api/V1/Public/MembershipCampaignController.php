<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Controller;
use App\Models\MembershipSeason;
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
    public function current(): JsonResponse
    {
        $seasons = MembershipSeason::query()
            ->where('status', 'open')
            ->with(['membershipTypes' => fn ($q) => $q->where('status', 'active')->where('is_public_self_apply', true)->orderBy('sort_order')])
            ->orderBy('display_order')->orderBy('opens_at')
            ->get()
            ->filter(fn (MembershipSeason $season) => $season->acceptsApplicationsNow())
            ->values();

        return response()->json(['data' => $seasons->map(fn (MembershipSeason $season) => $this->publicPayload($season))]);
    }

    /** @return array<string, mixed> */
    private function publicPayload(MembershipSeason $season): array
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
            'membership_types' => $season->membershipTypes->map(fn ($type) => [
                'id' => $type->id,
                'name' => $type->name,
                'slug' => $type->slug,
                'description' => $type->description,
                'duration_months' => $type->duration_months,
                'fee' => $type->fee,
                'is_student' => $type->is_student,
            ])->values(),
        ];
    }
}
