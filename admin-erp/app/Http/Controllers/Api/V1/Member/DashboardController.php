<?php

namespace App\Http\Controllers\Api\V1\Member;

use App\Http\Controllers\Controller;
use App\Models\Member;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * §11: profile/membership/payment/library sections — explicitly NOT the
 * admin dashboard, and the library section is an honest empty placeholder
 * rather than fabricated data, since no library/borrowing system exists
 * anywhere in this app yet. Every field below is picked explicitly (§39),
 * never a raw model/relation serialize.
 */
class DashboardController extends Controller
{
    public function me(Request $request): JsonResponse
    {
        /** @var Member $member */
        $member = $request->user();

        $member->load([
            'memberships' => fn ($q) => $q->latest('start_date'),
            'memberships.membershipType:id,name,slug',
            'memberships.application.payments',
            'seasonHistory.season:id,name,slug',
        ]);

        return response()->json(['data' => [
            'profile' => [
                'member_code' => $member->member_code,
                'name' => $member->name,
                'email' => $member->email,
                'phone' => $member->phone,
                'status' => $member->status,
                'public_profile_enabled' => $member->public_profile_enabled,
                'public_profile_approved' => $member->public_profile_approved,
                'public_slug' => $member->public_slug,
            ],
            'memberships' => $member->memberships->map(fn ($m) => [
                'member_code' => $m->member_code,
                'status' => $m->status,
                'start_date' => $m->start_date,
                'expiry_date' => $m->expiry_date,
                'membership_type' => $m->membershipType?->name,
            ])->values(),
            'season_history' => $member->seasonHistory->map(fn ($h) => [
                'season' => $h->season?->name,
                'joined_at' => $h->joined_at,
            ])->values(),
            'payments' => $member->memberships
                ->flatMap(fn ($m) => $m->application?->payments ?? collect())
                ->map(fn ($p) => [
                    'amount_received' => $p->amount_received,
                    'method' => $p->method,
                    'status' => $p->status,
                    'received_at' => $p->received_at,
                ])
                ->values(),
            'library' => ['transactions' => []],
        ]]);
    }
}
