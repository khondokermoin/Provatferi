<?php

namespace App\Http\Controllers\Api\V1\Member;

use App\Http\Controllers\Controller;
use App\Models\Member;
use App\Models\PublicMemberProfileVersion;
use App\Services\PhotoUploadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * §13/§14: a member's own view of, and edits to, their public profile.
 * Editing an approved field never overwrites the live version directly —
 * every submission here inserts a new `pending` PublicMemberProfileVersion;
 * only PublicMemberProfileVersion::approveAndPublish() (admin-only, see
 * Admin\PublicMemberProfileController) ever promotes one to live. The
 * visibility toggle (public_profile_enabled) is the one exception — it's
 * the member's own switch, not content, so it changes immediately without
 * review.
 */
class ProfileController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        /** @var Member $member */
        $member = $request->user();
        $photos = app(PhotoUploadService::class);

        $live = $member->liveProfileVersion()->first();
        $pending = $member->profileVersions()->where('status', 'pending')->latest('submitted_at')->first();

        return response()->json(['data' => [
            'public_profile_enabled' => $member->public_profile_enabled,
            'public_profile_approved' => $member->public_profile_approved,
            'public_slug' => $member->public_slug,
            'live' => $live ? $this->versionPayload($live, $photos, includePhoto: true) : null,
            'pending' => $pending ? $this->versionPayload($pending, $photos, includePhoto: false) : null,
        ]]);
    }

    public function update(Request $request): JsonResponse
    {
        /** @var Member $member */
        $member = $request->user();

        $data = $request->validate([
            'public_profile_enabled' => ['required', 'boolean'],
            'bio' => ['nullable', 'string', 'max:2000'],
            'profession' => ['nullable', 'string', 'max:255'],
            'facebook_url' => ['nullable', 'url', 'max:255'],
            'linkedin_url' => ['nullable', 'url', 'max:255'],
            'website_url' => ['nullable', 'url', 'max:255'],
            'photo' => ['nullable', 'file', 'max:5120'],
        ]);

        $member->forceFill(['public_profile_enabled' => $data['public_profile_enabled']]);
        if ($data['public_profile_enabled'] && !$member->public_slug) {
            $member->public_slug = Str::slug($member->name).'-'.Str::random(6);
        }
        $member->save();

        $photoPath = null;
        if ($request->hasFile('photo')) {
            try {
                $photoPath = app(PhotoUploadService::class)->storePrivate($request->file('photo'), 'member-profiles');
            } catch (RuntimeException $e) {
                throw ValidationException::withMessages(['photo' => $e->getMessage()]);
            }
        }

        PublicMemberProfileVersion::query()->create([
            'member_id' => $member->id,
            'status' => 'pending',
            'photo_path' => $photoPath,
            'bio' => $data['bio'] ?? null,
            'profession' => $data['profession'] ?? null,
            'facebook_url' => $data['facebook_url'] ?? null,
            'linkedin_url' => $data['linkedin_url'] ?? null,
            'website_url' => $data['website_url'] ?? null,
            'submitted_at' => now(),
        ]);

        return response()->json(['message' => 'সংরক্ষণ করা হয়েছে।']);
    }

    /** @return array<string, mixed> */
    private function versionPayload(PublicMemberProfileVersion $version, PhotoUploadService $photos, bool $includePhoto): array
    {
        return [
            'bio' => $version->bio,
            'profession' => $version->profession,
            'facebook_url' => $version->facebook_url,
            'linkedin_url' => $version->linkedin_url,
            'website_url' => $version->website_url,
            'photo_url' => $includePhoto ? $photos->publicUrl($version->photo_approved_path) : null,
            'submitted_at' => $version->submitted_at,
        ];
    }
}
