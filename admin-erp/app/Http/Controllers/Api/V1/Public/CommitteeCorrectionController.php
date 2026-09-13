<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Controller;
use App\Models\ApprovalHistory;
use App\Models\CommitteePosition;
use App\Models\CommitteeSubmission;
use App\Services\PhotoUploadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * §27/§41: an applicant editing their own already-submitted data through a
 * single-use correction link — findByValidCorrectionToken() already refuses
 * a spent or expired token, so a resubmission can only ever happen once per
 * issued link. Unlike the public committee-history payloads, this response
 * legitimately includes the applicant's own email/phone/bio — it's their
 * own data, gated by possession of the secure token, not a public listing.
 */
class CommitteeCorrectionController extends Controller
{
    public function show(string $token): JsonResponse
    {
        $submission = CommitteeSubmission::findByValidCorrectionToken($token);
        abort_unless($submission, 404);

        return response()->json(['data' => [
            'committee' => ['id' => $submission->committee_id, 'name' => $submission->committee->name],
            'admin_note' => $submission->admin_note,
            'full_name' => $submission->full_name,
            'name_en' => $submission->name_en,
            'email' => $submission->email,
            'phone' => $submission->phone,
            'bio' => $submission->bio,
            'provatferi_comment' => $submission->provatferi_comment,
            'facebook_url' => $submission->facebook_url,
            'linkedin_url' => $submission->linkedin_url,
            'website_url' => $submission->website_url,
            'committee_position_id' => $submission->committee_position_id,
            'positions' => CommitteePosition::query()->where('committee_id', $submission->committee_id)->where('status', 'active')
                ->orderBy('display_order')->orderBy('name')->get(['id', 'name'])->values(),
        ]]);
    }

    public function update(Request $request, string $token): JsonResponse
    {
        $submission = CommitteeSubmission::findByValidCorrectionToken($token);
        abort_unless($submission, 404);

        $data = $request->validate([
            'committee_position_id' => ['required', 'integer'],
            'full_name' => ['required', 'string', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['required', 'string', 'max:30'],
            'provatferi_comment' => ['required', 'string', 'max:2000'],
            'bio' => ['nullable', 'string', 'max:2000'],
            'facebook_url' => ['nullable', 'url', 'max:255'],
            'linkedin_url' => ['nullable', 'url', 'max:255'],
            'website_url' => ['nullable', 'url', 'max:255'],
            'publishing_consent' => ['required', 'accepted'],
            'accuracy_declaration' => ['required', 'accepted'],
            'photo' => ['nullable', 'file', 'max:5120'],
            'website' => ['prohibited'],
        ], [], [
            'full_name' => 'নাম', 'email' => 'ই-মেইল', 'phone' => 'মোবাইল',
            'provatferi_comment' => 'মন্তব্য', 'committee_position_id' => 'পদ',
            'publishing_consent' => 'প্রকাশনায় সম্মতি', 'accuracy_declaration' => 'তথ্যের সঠিকতা ঘোষণা',
        ]);

        $position = CommitteePosition::query()->where('id', $data['committee_position_id'])
            ->where('committee_id', $submission->committee_id)->where('status', 'active')->first();
        if (!$position) {
            throw ValidationException::withMessages(['committee_position_id' => 'পদটি সঠিক নয়।']);
        }

        $photos = app(PhotoUploadService::class);
        $photoPath = $submission->photo_path;
        if ($request->hasFile('photo')) {
            try {
                $photoPath = $photos->storePrivate($request->file('photo'), 'committee-submissions');
            } catch (RuntimeException $e) {
                throw ValidationException::withMessages(['photo' => $e->getMessage()]);
            }
            if ($submission->photo_path) {
                $photos->deletePrivate($submission->photo_path);
            }
        }

        $submission->forceFill([
            'committee_position_id' => $position->id,
            'full_name' => $data['full_name'],
            'name_en' => $data['name_en'] ?? null,
            'email' => $data['email'],
            'phone' => $data['phone'],
            'photo_path' => $photoPath,
            'bio' => $data['bio'] ?? null,
            'provatferi_comment' => $data['provatferi_comment'],
            'facebook_url' => $data['facebook_url'] ?? null,
            'linkedin_url' => $data['linkedin_url'] ?? null,
            'website_url' => $data['website_url'] ?? null,
            'publishing_consent' => true,
            'accuracy_declaration' => true,
            'status' => 'pending',
            'admin_note' => null,
            'correction_used_at' => now(),
            'submitted_at' => now(),
        ])->save();

        ApprovalHistory::record($submission, 'resubmitted', null, 'আবেদনকারী কর্তৃক সংশোধিত ও পুনরায় জমাকৃত।', 'applicant');

        return response()->json(['data' => ['id' => $submission->id]]);
    }
}
