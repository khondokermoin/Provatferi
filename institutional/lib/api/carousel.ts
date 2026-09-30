import { apiGet, isOptionalString, isRecord, isStringOrNull } from "./client";
import type { ApiResult, CarouselSlide } from "./types";

/** A small, admin-managed list — cheap to refetch, no reason to cache longer than other content lists. */
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
  });

  return result.ok ? { ok: true, data: result.data.data } : result;
}
