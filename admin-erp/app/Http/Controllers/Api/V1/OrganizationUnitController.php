<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\OrganizationalUnit;
use Illuminate\Http\JsonResponse;

class OrganizationUnitController extends Controller
{
    public function index(): JsonResponse
    {
        $units = OrganizationalUnit::query()
            ->where('status', 'active')
            ->orderBy('name')
            ->get(['id', 'parent_id', 'name', 'slug', 'unit_type', 'address', 'phone', 'email']);

        return response()->json(['data' => $units]);
    }

    public function show(OrganizationalUnit $organizationUnit): JsonResponse
    {
        return response()->json(['data' => $organizationUnit]);
    }
}
