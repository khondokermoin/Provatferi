<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Controller;
use App\Models\MembershipApplication;
use App\Models\MembershipSeason;
use App\Models\MembershipType;
use App\Services\MembershipFeePolicyService;
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
 *
 * 2026-10-05 (public cache): Laravel is the ONLY authority on whether anyone may apply. An application is accepted
 * into a season that is open right now (status `open` and inside its dates) and offers the chosen type — so a
 * `membership_season_id` is REQUIRED. It used to be optional, which meant a client that left it out was accepted at
 * any time, even while the public page said "no season is open". The public site only ever shows the form for a
 * season Laravel listed as open, so the page and this endpoint agree by construction; and when they disagree for a
 * moment (the season closed while a visitor was filling the form in) the answer here is the truth and the form says so.
 */
class MembershipApplicationController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'applicant_name' => ['required', 'string', 'max:255'],
            'applicant_email' => ['required', 'email', 'max:255'],
            'applicant_phone' => ['required', 'string', 'max:30'],
            'membership_type_id' => ['required', Rule::exists('membership_types', 'id')->where('status', 'active')->where('is_public_self_apply', true)->where('is_public_visible', true)],
            'membership_season_id' => ['required', Rule::exists('membership_seasons', 'id')],
            // Optional profile (Membership Registry task 2): carried onto the member at approval, correctable by an admin.
            'address' => ['nullable', 'string', 'max:500'],
            'profession' => ['nullable', 'string', 'max:255'],
            'institution' => ['nullable', 'string', 'max:255'],
            'photo' => ['nullable', 'file', 'max:5120'],
            'website' => ['prohibited'],
        ], [], [
            'applicant_name' => 'নাম', 'applicant_email' => 'ই-মেইল', 'applicant_phone' => 'মোবাইল',
            'membership_type_id' => 'সদস্যপদের ধরন', 'membership_season_id' => 'নিবন্ধন সিজন',
            'address' => 'ঠিকানা', 'profession' => 'পেশা / শিক্ষা', 'institution' => 'প্রতিষ্ঠান',
        ]);

        // First question: is registration open at all? (The same per-request clock check the public list is built from.)
        $season = MembershipSeason::query()->find($data['membership_season_id']);
        if (!$season || !$season->acceptsApplicationsNow()) {
            throw ValidationException::withMessages(['membership_season_id' => 'নির্বাচিত সিজনটি বর্তমানে আবেদন গ্রহণ করছে না।']);
        }
        $type = MembershipType::query()->find($data['membership_type_id']);
        if ($type && !$season->membershipTypes()->where('membership_types.id', $type->id)->exists()) {
            throw ValidationException::withMessages(['membership_type_id' => 'এই সিজনে এই সদস্যপদের ধরন অনুমোদিত নয়।']);
        }

        // Applying means accepting a quoted price, so a type with no fee policy in force today cannot be applied to (the
        // public lists never offer one either). The quote itself is recorded on the application by
        // MembershipApplication::booted() — the policy of the day it is submitted, copied into the row.
        if (app(MembershipFeePolicyService::class)->effectiveFor((int) $data['membership_type_id']) === null) {
            throw ValidationException::withMessages(['membership_type_id' => 'এই সদস্যপদের ধরনের জন্য বর্তমানে আবেদন গ্রহণ করা হচ্ছে না।']);
        }

        $applicationData = [];
        foreach (['address', 'profession', 'institution'] as $field) {
            $value = trim((string) ($data[$field] ?? ''));
            if ($value !== '') {
                $applicationData[$field] = $value;
            }
        }
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
            'membership_season_id' => $season->id,
            'applicant_name' => $data['applicant_name'],
            'applicant_email' => $data['applicant_email'],
            'applicant_phone' => $data['applicant_phone'],
            'application_data' => $applicationData !== [] ? $applicationData : null,
            'status' => 'pending',
        ]);

        return response()->json(['data' => ['application_no' => $application->application_no]], 201);
    }
}
