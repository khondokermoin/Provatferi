<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Activity;
use Illuminate\Http\JsonResponse;

/**
 * Public read-only contract. Response shape changed 2026-09-08 (Phase 1C):
 * the publish gate moved from `whereNotNull('published_at')` to an explicit
 * `status = published` check (published_at is now preserved through
 * archiving as a historical record, so it's no longer a safe gate on its
 * own); the field list grew to match the new evidence-card model; and
 * GET /activities/{id-or-slug} now accepts either, not id only.
 * `gallery`/`related_links` are always arrays (never null).
 */
class ActivityController extends Controller
{
    public function index(): JsonResponse
    {
        $activities = Activity::query()
            ->where('status', 'published')
            ->with(['type:id,name,slug', 'organizationUnit:id,name,slug'])
            ->orderByDesc('start_datetime')
            ->paginate(20, $this->publicColumns());

        return response()->json($activities);
    }

    public function show(string $activity): JsonResponse
    {
        $model = Activity::query()
            ->select($this->publicColumns())
            ->where('status', 'published')
            ->where(fn ($q) => $q->where('id', $activity)->orWhere('slug', $activity))
            ->with(['type:id,name,slug', 'organizationUnit:id,name,slug'])
            ->firstOrFail();

        return response()->json(['data' => $model]);
    }

    /** @return array<int, string> */
    private function publicColumns(): array
    {
        return [
            'id', 'activity_type_id', 'organization_unit_id', 'title', 'slug', 'summary', 'description',
            'objective', 'venue', 'address', 'hero_image_path', 'what_happened', 'outcomes', 'gallery',
            'related_links', 'facebook_post_url', 'start_datetime', 'end_datetime', 'featured',
            'participant_count', 'published_at',
        ];
    }
}
