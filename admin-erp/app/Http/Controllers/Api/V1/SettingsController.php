<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AboutPage;
use App\Models\ContentBlock;
use App\Models\Objective;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;

/**
 * Public read-only content endpoints. Response shapes here changed on
 * 2026-09-08 (Phase 1B) from a flat {key: value} / array-of-blocks shape to
 * grouped, semantically-named objects — no tested consumer existed yet
 * (production Next.js is not connected), so this was a clean break rather
 * than a versioned migration. Objectives are always returned active-only, in
 * display order.
 */
class SettingsController extends Controller
{
    /** GET /api/v1/settings */
    public function index(): JsonResponse
    {
        $values = Setting::query()->where('is_public', true)->pluck('value', 'key');
        $get = fn (string $key) => $values[$key] ?? null;

        return response()->json(['data' => [
            'organization' => [
                'name_bn' => $get('site.name_bn'),
                'name_en' => $get('site.name_en'),
                'short_name' => $get('site.short_name'),
                'acronym' => $get('site.acronym'),
                'tagline' => $get('site.tagline'),
            ],
            'contact' => [
                'email' => $get('site.email'),
                'phone' => $get('site.phone'),
                'address' => $get('site.address'),
                'facebook_url' => $get('site.facebook_url'),
            ],
            'seo' => [
                'title' => $get('site.seo_title'),
                'description' => $get('site.seo_description'),
                'alternate_names' => json_decode((string) $get('site.alternate_names'), true) ?? [],
                'canonical_url' => $get('site.website_url'),
            ],
            'links' => [
                'website_url' => $get('site.website_url'),
                'literature_url' => $get('site.literature_url'),
            ],
        ]]);
    }

    /** GET /api/v1/about */
    public function about(): JsonResponse
    {
        $about = AboutPage::current();
        $mission = ContentBlock::query()->where('key', 'about.mission')->first();
        $vision = ContentBlock::query()->where('key', 'about.vision')->first();

        return response()->json(['data' => [
            'about' => $about->is_published ? [
                'introduction' => $about->introduction,
                'description' => $about->description,
                'history' => $about->history,
                'why_exists' => $about->why_exists,
                'identity_explanation' => $about->identity_explanation,
                'registration_status' => $about->registration_status,
            ] : null,
            'mission' => ($mission && $mission->is_public) ? [
                'body' => $mission->body,
                'updated_at' => $mission->updated_at?->toIso8601String(),
            ] : null,
            'vision' => ($vision && $vision->is_public) ? [
                'body' => $vision->body,
                'updated_at' => $vision->updated_at?->toIso8601String(),
            ] : null,
            'objectives' => Objective::query()
                ->where('active', true)
                ->orderBy('sort_order')->orderBy('id')
                ->get(['id', 'title', 'body', 'sort_order'])
                ->values(),
        ]]);
    }
}
