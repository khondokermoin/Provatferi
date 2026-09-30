<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Controller;
use App\Models\HomepageCarouselSlide;
use App\Services\PhotoUploadService;
use Illuminate\Http\JsonResponse;

/**
 * Phase 4: the homepage carousel's public contract. Unlike Notices/Activities,
 * a slide has no individual public detail page — the whole ordered, active
 * set is always fetched together as one small list, so there is only
 * index(), no show(). image_url is a plain Storage::disk('public')->url()
 * (via PhotoUploadService::publicUrl(), same as already-approved member/
 * committee photos) rather than a dedicated streaming route, because a
 * slide's image is unconditionally public the moment it exists — there is no
 * draft state to gate the way a Notice's cover image has to be.
 */
class HomepageCarouselController extends Controller
{
    public function __construct(
        private readonly PhotoUploadService $photos,
    ) {
    }

    public function index(): JsonResponse
    {
        $slides = HomepageCarouselSlide::query()
            ->publiclyVisible()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn (HomepageCarouselSlide $slide) => $this->present($slide))
            ->values();

        return response()->json(['data' => $slides]);
    }

    /** @return array<string, mixed> */
    private function present(HomepageCarouselSlide $slide): array
    {
        return [
            'id' => $slide->id,
            'image_url' => $this->photos->publicUrl($slide->image_path),
            'title' => $slide->title,
            'title_en' => $slide->title_en,
            'alt_text' => $slide->alt_text,
            'alt_text_en' => $slide->alt_text_en,
            'link_url' => $slide->link_url,
            'link_label' => $slide->link_label,
            'link_label_en' => $slide->link_label_en,
        ];
    }
}
