import { apiGet, isRecord, isNumberOrNull, isStringOrNull } from "./client";
import type { Activity, ActivityListResponse, ApiResult } from "./types";
import { recentActivities } from "../content";

/**
 * Wired into app/(site)/activities/page.tsx and .../activities/[slug]/page.tsx
 * as of the 2026-09-10 cutover, once production GET /api/v1/activities
 * reached 3/3 — the same three real, dated activities that were previously
 * only in lib/content.ts `recentActivities`. That file stays as the
 * fallback: if the ERP times out, errors, or unexpectedly returns fewer
 * than 3, the pages fall back to it rather than losing indexed content.
 */
const REVALIDATE_SECONDS = 120;

function isActivity(v: unknown): v is Activity {
  if (!isRecord(v)) return false;
  const type = v.type;
  // Snake_case on the wire (Eloquent's relationsToArray() runs Str::snake()
  // regardless of the camelCase relation method used server-side) — verified
  // against the live response, not assumed.
  const unit = v.organization_unit;

  return (
    typeof v.id === "number" &&
    isNumberOrNull(v.activity_type_id) &&
    isNumberOrNull(v.organization_unit_id) &&
    typeof v.title === "string" &&
    typeof v.slug === "string" &&
    isStringOrNull(v.summary) &&
    isStringOrNull(v.description) &&
    isStringOrNull(v.objective) &&
    isStringOrNull(v.venue) &&
    isStringOrNull(v.address) &&
    isStringOrNull(v.hero_image_path) &&
    isStringOrNull(v.what_happened) &&
    isStringOrNull(v.outcomes) &&
    Array.isArray(v.gallery) &&
    Array.isArray(v.related_links) &&
    isStringOrNull(v.facebook_post_url) &&
    isStringOrNull(v.start_datetime) &&
    isStringOrNull(v.end_datetime) &&
    typeof v.featured === "boolean" &&
    isNumberOrNull(v.participant_count) &&
    isStringOrNull(v.published_at) &&
    (type === null || (isRecord(type) && typeof type.id === "number" && typeof type.name === "string" && typeof type.slug === "string")) &&
    (unit === null || (isRecord(unit) && typeof unit.id === "number" && typeof unit.name === "string" && typeof unit.slug === "string"))
  );
}

function isActivityListResponse(json: unknown): json is ActivityListResponse {
  return (
    isRecord(json) &&
    Array.isArray(json.data) &&
    json.data.every(isActivity) &&
    typeof json.total === "number" &&
    typeof json.current_page === "number" &&
    typeof json.last_page === "number"
  );
}

function isActivityResponse(json: unknown): json is { data: Activity } {
  return isRecord(json) && isActivity(json.data);
}

export async function getActivities(): Promise<ApiResult<ActivityListResponse>> {
  return apiGet<ActivityListResponse>("/api/v1/activities", {
    validate: isActivityListResponse,
    revalidateSeconds: REVALIDATE_SECONDS,
  });
}

export async function getActivity(slug: string): Promise<ApiResult<Activity>> {
  const result = await apiGet<{ data: Activity }>(`/api/v1/activities/${encodeURIComponent(slug)}`, {
    validate: isActivityResponse,
    revalidateSeconds: REVALIDATE_SECONDS,
  });

  return result.ok ? { ok: true, data: result.data.data } : result;
}

// ---------------------------------------------------------------------------
// The shape ActivityFilter (components/ActivityFilter.tsx) and the detail
// page already render — unchanged from before this cutover, since the brief
// is "wire real data in", not "redesign the frontend". Both the API branch
// and the fallback branch below normalize into exactly this.
// ---------------------------------------------------------------------------

export interface DisplayActivity {
  slug: string;
  date: string;
  title: string;
  place: string;
  category: string;
  photos: string[];
  outcomes: string | null;
}

const BANGLA_DIGITS = ["০", "১", "২", "৩", "৪", "৫", "৬", "৭", "৮", "৯"];

/** "2026-09-05T00:00:00.000000Z" -> "২০২৬-০৯-০৫", matching the digit style
 *  lib/content.ts's own dates were already written in. */
function toDisplayDate(isoDatetime: string | null): string {
  if (!isoDatetime) return "";
  return isoDatetime.slice(0, 10).replace(/[0-9]/g, (d) => BANGLA_DIGITS[Number(d)]);
}

function activityToDisplay(a: Activity): DisplayActivity {
  return {
    slug: a.slug,
    date: toDisplayDate(a.start_datetime),
    title: a.title,
    place: a.venue ?? "",
    category: a.type?.name ?? "",
    photos: a.gallery,
    outcomes: a.outcomes,
  };
}

function fallbackToDisplay(): DisplayActivity[] {
  return recentActivities.map((a) => ({
    slug: a.slug,
    date: a.date,
    title: a.title,
    place: a.place,
    category: a.category,
    photos: a.photos,
    outcomes: a.outcomes,
  }));
}

/**
 * THE single source every activity-related surface reads from — the
 * listing, the detail page (including generateStaticParams and
 * generateMetadata), and the sitemap all call this one function, so they
 * cannot drift from each other the way switching them independently would
 * risk. Falls back to the complete approved lib/content.ts list on any
 * failure (timeout, non-200, malformed shape) AND on an empty API result —
 * an ERP outage must never make indexed activity pages disappear.
 */
export async function getActivitiesWithFallback(): Promise<{ activities: DisplayActivity[]; source: "api" | "fallback" }> {
  const result = await getActivities();
  if (result.ok && result.data.data.length > 0) {
    return { activities: result.data.data.map(activityToDisplay), source: "api" };
  }
  return { activities: fallbackToDisplay(), source: "fallback" };
}
