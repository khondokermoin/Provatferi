import { apiGet, isOptionalString, isRecord, isStringOrNull } from "./client";
import type { ApiResult, CarouselSlide } from "./types";

/**
 * The cache tag Laravel invalidates (via the signed POST /api/revalidate)
 * whenever an admin creates, edits, replaces, reorders, toggles or deletes a
 * slide, so a change shows within seconds instead of waiting out the window.
 */
export const CAROUSEL_CACHE_TAG = "homepage-carousel";

/**
 * Safety net only. On-demand invalidation is the normal path; this bounds how
 * stale the homepage can be if a revalidation call is ever lost (Laravel
 * swallows a failed call rather than blocking the admin's save).
 */
const REVALIDATE_SECONDS = 120;

function isCarouselSlide(v: unknown): v is CarouselSlide {
  return (
    isRecord(v) &&
    typeof v.id === "number" &&
    isStringOrNull(v.image_url) &&
    isStringOrNull(v.title) &&
    isOptionalString(v.title_en) &&
    isStringOrNull(v.alt_text) &&
    isOptionalString(v.alt_text_en) &&
    isStringOrNull(v.link_url) &&
    isStringOrNull(v.link_label) &&
    isOptionalString(v.link_label_en)
  );
}

function isCarouselResponse(json: unknown): json is { data: CarouselSlide[] } {
  return isRecord(json) && Array.isArray(json.data) && json.data.every(isCarouselSlide);
}

export async function getCarouselSlides(): Promise<ApiResult<CarouselSlide[]>> {
  const result = await apiGet<{ data: CarouselSlide[] }>("/api/v1/public/homepage-carousel", {
    validate: isCarouselResponse,
    revalidateSeconds: REVALIDATE_SECONDS,
    tags: [CAROUSEL_CACHE_TAG],
  });

  return result.ok ? { ok: true, data: result.data.data } : result;
}
