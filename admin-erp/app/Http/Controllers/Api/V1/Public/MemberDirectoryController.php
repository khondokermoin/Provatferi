<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Controller;
use App\Models\Member;
use App\Models\PublicMemberProfileVersion;
use App\Services\PhotoUploadService;
use Illuminate\Http\JsonResponse;

/**
 * §13/§14/§16: the public member directory — visible only when BOTH gates
 * are open (Member::isPubliclyVisible()) AND a live, admin-approved
 * PublicMemberProfileVersion actually exists; a member who enabled
 * visibility but never had a version approved has nothing to show yet.
 * member_code is deliberately never exposed here — an internal ERP
 * identifier, not directory content.
 */
class MemberDirectoryController extends Controller
{
    public function index(): JsonResponse
    {
        $photos = app(PhotoUploadService::class);

        $members = Member::query()
            ->where('status', 'active')
            ->where('public_profile_enabled', true)
            ->where('public_profile_approved', true)
            ->whereHas('profileVersions', fn ($q) => $q->where('is_current_live', true))
            ->with(['liveProfileVersion' => fn ($q) => $q->limit(1)])
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $members
            ->map(fn (Member $m) => $this->summaryPayload($m, $m->liveProfileVersion->first(), $photos))
            ->values(),
        ]);
    }

    public function show(string $member): JsonResponse
    {
        $model = Member::query()
            ->where('status', 'active')
            ->where('public_profile_enabled', true)
            ->where('public_profile_approved', true)
            ->where(fn ($q) => $q->where('public_slug', $member)->orWhere('id', $member))
            ->with(['liveProfileVersion' => fn ($q) => $q->limit(1)])
            ->firstOrFail();

        $live = $model->liveProfileVersion->first();
        abort_unless($live, 404);

        return response()->json(['data' => $this->detailPayload($model, $live, app(PhotoUploadService::class))]);
    }

    /** @return array<string, mixed> */
    private function summaryPayload(Member $member, ?PublicMemberProfileVersion $live, PhotoUploadService $photos): array
    {
        return [
            'public_slug' => $member->public_slug,
            'name' => $member->name,
            'profession' => $live?->profession,
            'photo_url' => $photos->publicUrl($live?->photo_approved_path),
        ];
    }

    /** @return array<string, mixed> */
    private function detailPayload(Member $member, PublicMemberProfileVersion $live, PhotoUploadService $photos): array
    {
        return [
            'public_slug' => $member->public_slug,
            'name' => $member->name,
            'profession' => $live->profession,
            'bio' => $live->bio,
            'photo_url' => $photos->publicUrl($live->photo_approved_path),
            'facebook_url' => $live->facebook_url,
            'linkedin_url' => $live->linkedin_url,
            'website_url' => $live->website_url,
        ];
    }
}
