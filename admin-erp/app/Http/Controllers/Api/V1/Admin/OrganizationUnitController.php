<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\OrganizationalUnit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Reference admin CRUD implementation. The same request/response/permission
 * shape applies to Activities, Membership, Recruitment and Settings —
 * see the API contract in the readiness report for their route lists.
 */
class OrganizationUnitController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(OrganizationalUnit::query()->with('parent')->orderBy('name')->paginate(20));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'parent_id' => ['nullable', 'exists:organizational_units,id'],
            'name' => ['required', 'string', 'max:255'],
            'unit_type' => ['required', 'in:central,division,district,upazila,union,branch,unit'],
            'code' => ['nullable', 'string', 'max:50'],
            'description' => ['nullable', 'string'],
            'address' => ['nullable', 'string'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email'],
        ]);

        $data['slug'] = Str::slug($data['name']).'-'.Str::random(4);
        $unit = OrganizationalUnit::query()->create($data);

        return response()->json(['data' => $unit], 201);
    }

    public function update(Request $request, OrganizationalUnit $organizationUnit): JsonResponse
    {
        $data = $request->validate([
            'parent_id' => ['nullable', 'exists:organizational_units,id'],
            'name' => ['sometimes', 'string', 'max:255'],
            'unit_type' => ['sometimes', 'in:central,division,district,upazila,union,branch,unit'],
            'code' => ['nullable', 'string', 'max:50'],
            'description' => ['nullable', 'string'],
            'address' => ['nullable', 'string'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email'],
            'status' => ['sometimes', 'in:active,inactive'],
        ]);

        $organizationUnit->update($data);

        return response()->json(['data' => $organizationUnit]);
    }

    public function destroy(OrganizationalUnit $organizationUnit): JsonResponse
    {
        $organizationUnit->delete();

        return response()->json(status: 204);
    }
}
