<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Controller;
use App\Models\CommitteePosition;
use App\Models\CommitteeRegistrationLink;
use App\Models\CommitteeSubmission;
use App\Services\PhotoUploadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * §22-25/§41: the public committee-nomination intake. A link is shareable —
 * markUsed() only records a timestamp, it never revokes — so the same link
 * can legitimately produce many submissions (one per nominee), unlike the
 * single-use correction token below. `committee_position_id` is always the
 * applicant's own dropdown choice (§23), never bound to the link itself.
 */
class CommitteeRegistrationController extends Controller
{
    public function show(string $token): JsonResponse
    {
        $link = CommitteeRegistrationLink::findValidByRawToken($token);
        if (!$link || !in_array($link->committee->status, ['active', 'upcoming'], true)) {
            abort(404);
        }

        return response()->json(['data' => [
            'committee' => ['id' => $link->committee->id, 'name' => $link->committee->name, 'slug' => $link->committee->slug],
            'positions' => CommitteePosition::query()->where('committee_id', $link->committee_id)->where('status', 'active')
                ->orderBy('display_order')->orderBy('name')->get()
                ->map(fn (CommitteePosition $p) => ['id' => $p->id, 'name' => $p->name, 'occupied' => $p->isOccupied()])
                ->values(),
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'registration_token' => ['required', 'string'],
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
            'photo' => ['required', 'file', 'max:5120'],
            'website' => ['prohibited'],
        ], [], [
            'full_name' => 'নাম', 'email' => 'ই-মেইল', 'phone' => 'মোবাইল',
            'provatferi_comment' => 'মন্তব্য', 'committee_position_id' => 'পদ', 'photo' => 'ছবি',
            'publishing_consent' => 'প্রকাশনায় সম্মতি', 'accuracy_declaration' => 'তথ্যের সঠিকতা ঘোষণা',
        ]);

        $link = CommitteeRegistrationLink::findValidByRawToken($data['registration_token']);
        if (!$link || !in_array($link->committee->status, ['active', 'upcoming'], true)) {
            throw ValidationException::withMessages(['registration_token' => 'নিবন্ধন লিংকটি বৈধ নয়, মেয়াদোত্তীর্ণ, অথবা বাতিল করা হয়েছে।']);
        }

        $position = CommitteePosition::query()->where('id', $data['committee_position_id'])
            ->where('committee_id', $link->committee_id)->where('status', 'active')->first();
        if (!$position) {
            throw ValidationException::withMessages(['committee_position_id' => 'পদটি সঠিক নয়।']);
        }

        try {
            $photoPath = app(PhotoUploadService::class)->storePrivate($request->file('photo'), 'committee-submissions');
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['photo' => $e->getMessage()]);
        }

        $submission = CommitteeSubmission::query()->create([
            'committee_id' => $link->committee_id,
            'committee_registration_link_id' => $link->id,
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
            'submitted_at' => now(),
        ]);

        $link->markUsed();

        return response()->json(['data' => ['id' => $submission->id]], 201);
    }
}
