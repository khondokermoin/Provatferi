<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Controller;
use App\Models\LeadershipProfile;
use App\Models\LeadershipProfileSlug;
use App\Support\PublicLeadershipPayload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The public, read-only contract of Leadership / Key Persons (docs/LEADERSHIP_SYSTEM.md). Only ACTIVE profiles exist here:
 * an inactive or archived profile is a 404, exactly like one that was never created, so nothing about a hidden profile
 * (not even that it exists) leaves this API. Every response is built by PublicLeadershipPayload, an explicit list of public
 * fields.
 *
 * Order is the admin-defined display_order, ties broken by id, so the same profiles always come out in the same order;
 * `featured` only filters, it never reorders.
 */
class LeadershipController extends Controller
{
    /** The most profiles one call may return (the list is small by nature; this is a bound, not a page size). */
    private const MAX_LIMIT = 50;

    /** GET /api/v1/public/leadership[?featured=1][&limit=N] */
    public function index(Request $request): JsonResponse
    {
        $query = LeadershipProfile::query()->publiclyVisible()->inDisplayOrder();

        if ($request->boolean('featured')) {
            $query->where('featured', true);
        }

        $limit = (int) $request->query('limit', 0);
        if ($limit > 0) {
            $query->limit(min($limit, self::MAX_LIMIT));
        }

        return response()->json([
            'data' => $query->get()->map(fn (LeadershipProfile $profile) => PublicLeadershipPayload::summary($profile))->values(),
        ]);
    }

    /**
     * GET /api/v1/public/leadership/{slug}. The slug may be one the profile used to have (leadership_profile_slugs): the
     * response carries the CURRENT slug, so the site can tell a lookup through history from a direct one by comparing the
     * segment it asked for with the `slug` it got back, and answer the old address with a permanent redirect.
     */
    public function show(string $slug): JsonResponse
    {
        $profile = LeadershipProfile::query()->publiclyVisible()->where('slug', $slug)->first();

        if ($profile === null) {
            $retired = LeadershipProfileSlug::query()->where('slug', $slug)->first();
            if ($retired !== null) {
                $profile = LeadershipProfile::query()->publiclyVisible()->whereKey($retired->leadership_profile_id)->first();
            }
        }

        abort_if($profile === null, 404);

        return response()->json(['data' => PublicLeadershipPayload::detail($profile)]);
    }
}
