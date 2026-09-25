<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Controller;
use App\Models\Committee;
use App\Models\CommitteeMember;
use App\Services\PhotoUploadService;
use Illuminate\Http\JsonResponse;

/**
 * §29-32: public committee-history pages. A 'draft' committee is never
 * publicly reachable (not yet announced) — every query here excludes it.
 * Member payloads only ever surface photo_approved_path (never the private
 * original) and never email/phone/provatferi_comment, which are internal-
 * review-only fields regardless of the member's approval status.
 */
class CommitteeController extends Controller
{
    public function index(): JsonResponse
    {
        $columns = $this->publicColumns();

        $current = Committee::query()->select($columns)->where('status', 'active')->first();
        $upcoming = Committee::query()->select($columns)->where('status', 'upcoming')
            ->orderBy('term_start')->get();
        $previous = Committee::query()->select($columns)->whereIn('status', ['completed', 'archived', 'expired'])
            ->orderByDesc('term_end')->orderByDesc('term_start')->get();

        return response()->json(['data' => [
            'current' => $current,
            'upcoming' => $upcoming,
            'previous' => $previous,
        ]]);
    }

    public function show(string $committee): JsonResponse
    {
        $model = Committee::query()
            ->select($this->publicColumns())
            ->where('status', '!=', 'draft')
            ->where(fn ($q) => $q->where('id', $committee)->orWhere('slug', $committee))
            ->with([
                'publishedMembers' => fn ($q) => $q->orderBy('serial_no')->orderBy('id'),
                'publishedMembers.submission', 'publishedMembers.user', 'publishedMembers.committeePosition', 'publishedMembers.position',
            ])
            ->firstOrFail();

        $photos = app(PhotoUploadService::class);

        // Built field-by-field rather than $model->toArray() — that would
        // also serialize the eager-loaded publishedMembers relation raw,
        // including the private photo_path/email/phone the map() below is
        // specifically here to strip.
        return response()->json(['data' => [
            'id' => $model->id,
            'slug' => $model->slug,
            'name' => $model->name,
            'name_en' => $model->name_en,
            'committee_type' => $model->committee_type,
            'term_start' => $model->term_start,
            'term_end' => $model->term_end,
            'status' => $model->status,
            'description' => $model->description,
            'description_en' => $model->description_en,
            'members' => $model->publishedMembers->map(fn (CommitteeMember $member) => $this->publicMemberPayload($member, $photos))->values(),
        ]]);
    }

    /** @return array<int, string> */
    private function publicColumns(): array
    {
        return ['id', 'slug', 'name', 'name_en', 'committee_type', 'term_start', 'term_end', 'status', 'description', 'description_en', 'organization_unit_id'];
    }

    /** @return array<string, mixed> */
    private function publicMemberPayload(CommitteeMember $member, PhotoUploadService $photos): array
    {
        $submission = $member->submission;

        return [
            'name' => $member->displayName(),
            'name_en' => $member->displayNameEn(),
            'position' => $member->positionTitle(),
            'position_en' => $member->positionTitleEn(),
            'serial_no' => $member->serial_no,
            'photo_url' => $submission ? $photos->publicUrl($submission->photo_approved_path) : null,
            'bio' => $submission?->bio,
            'facebook_url' => $submission?->facebook_url,
            'linkedin_url' => $submission?->linkedin_url,
            'website_url' => $submission?->website_url,
        ];
    }
}
