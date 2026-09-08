<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\MembershipType;
use Illuminate\Http\JsonResponse;

/**
 * Public read-only contract. 2026-09-08: now ordered by the new sort_order
 * column and restricted to a public-safe column list — applications,
 * approval workflow, and any internal notes live entirely on
 * MembershipApplication, which has no public route at all.
 */
class MembershipTypeController extends Controller
{
    public function index(): JsonResponse
    {
        $types = MembershipType::query()
            ->where('status', 'active')
            ->orderBy('sort_order')->orderBy('name')
            ->get(['id', 'name', 'slug', 'description', 'duration_months', 'fee', 'is_student']);

        return response()->json(['data' => $types]);
    }
}
