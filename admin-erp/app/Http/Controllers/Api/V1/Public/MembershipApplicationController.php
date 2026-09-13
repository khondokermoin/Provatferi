<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Controller;
use App\Models\MembershipApplication;
use App\Models\MembershipSeason;
use App\Models\MembershipType;
use App\Services\PhotoUploadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * §7/§41: the public application intake. No admin-side "create application"
 * form has ever existed in this app — this endpoint is the first real
 * producer of membership_applications rows outside a test or tinker. A
 * photo is optional here (unlike a committee submission, where §24 makes it
 * required) since no membership field-catalogue mandating one exists yet.
 * `website` is a honeypot — real browsers never populate it (hidden by the
 * Next.js form's own CSS), so any value there is treated as spam (§40).
 */
class MembershipApplicationController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'applicant_name' => ['required', 'string', 'max:255'],
            'applicant_email' => ['required', 'email', 'max:255'],
            'applicant_phone' => ['required', 'string', 'max:30'],
            'membership_type_id' => ['required', Rule::exists('membership_types', 'id')->where('status', 'active')->where('is_public_self_apply', true)],
            'membership_season_id' => ['nullable', Rule::exists('membership_seasons', 'id')],
            'photo' => ['nullable', 'file', 'max:5120'],
            'website' => ['prohibited'],
        ], [], [
            'applicant_name' => 'নাম', 'applicant_email' => 'ই-মেইল', 'applicant_phone' => 'মোবাইল',
            'membership_type_id' => 'সদস্যপদের ধরন', 'membership_season_id' => 'নিবন্ধন সিজন',
        ]);

        if (!empty($data['membership_season_id'])) {
            $season = MembershipSeason::query()->find($data['membership_season_id']);
            if (!$season || !$season->acceptsApplicationsNow()) {
                throw ValidationException::withMessages(['membership_season_id' => 'নির্বাচিত সিজনটি বর্তমানে আবেদন গ্রহণ করছে না।']);
            }
            $type = MembershipType::query()->find($data['membership_type_id']);
            if ($type && !$season->membershipTypes()->where('membership_types.id', $type->id)->exists()) {
                throw ValidationException::withMessages(['membership_type_id' => 'এই সিজনে এই সদস্যপদের ধরন অনুমোদিত নয়।']);
            }
        }

        $applicationData = [];
        if ($request->hasFile('photo')) {
            try {
                $applicationData['photo_path'] = app(PhotoUploadService::class)
                    ->storePrivate($request->file('photo'), 'membership-applications');
            } catch (RuntimeException $e) {
                throw ValidationException::withMessages(['photo' => $e->getMessage()]);
            }
        }

        $application = MembershipApplication::query()->create([
            'application_no' => MembershipApplication::generateApplicationNo(),
            'membership_type_id' => $data['membership_type_id'],
            'membership_season_id' => $data['membership_season_id'] ?? null,
            'applicant_name' => $data['applicant_name'],
            'applicant_email' => $data['applicant_email'],
            'applicant_phone' => $data['applicant_phone'],
            'application_data' => $applicationData !== [] ? $applicationData : null,
            'status' => 'pending',
        ]);

        return response()->json(['data' => ['application_no' => $application->application_no]], 201);
    }
}
