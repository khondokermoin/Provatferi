import { apiGet, isOptionalString, isRecord, isNumberOrNull, isStringOrNull } from "./client";
import type { Activity, ActivityListResponse, ApiResult } from "./types";
import { recentActivities } from "../content";
import { recentActivities as recentActivitiesEn } from "../content.en";

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
    isOptionalString(v.title_en) &&
    typeof v.slug === "string" &&
    isStringOrNull(v.summary) &&
    isOptionalString(v.summary_en) &&
    isStringOrNull(v.description) &&
    isOptionalString(v.description_en) &&
    isStringOrNull(v.objective) &&
    isOptionalString(v.objective_en) &&
    isStringOrNull(v.venue) &&
    isStringOrNull(v.address) &&
    isStringOrNull(v.hero_image_path) &&
    isStringOrNull(v.what_happened) &&
    isOptionalString(v.what_happened_en) &&
    isStringOrNull(v.outcomes) &&
    isOptionalString(v.outcomes_en) &&
    Array.isArray(v.gallery) &&
    Array.isArray(v.related_links) &&
    isStringOrNull(v.facebook_post_url) &&
    isStringOrNull(v.start_datetime) &&
    isStringOrNull(v.end_datetime) &&
    typeof v.featured === "boolean" &&
    isNumberOrNull(v.participant_count) &&
    isStringOrNull(v.published_at) &&
    (type === null ||
      (isRecord(type) && typeof type.id === "number" && typeof type.name === "string" && isOptionalString(type.name_en) && typeof type.slug === "string")) &&
    (unit === null ||
      (isRecord(unit) && typeof unit.id === "number" && typeof unit.name === "string" && isOptionalString(unit.name_en) && typeof unit.slug === "string"))
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
  titleEn: string | null;
  place: string;
  /** Filter/comparison key — always the bn category name; see ActivityFilter.tsx for how the label is localized separately. */
  category: string;
  categoryEn: string | null;
  photos: string[];
  outcomes: string | null;
  outcomesEn: string | null;
  summary: string | null;
  summaryEn: string | null;
  descriptionEn: string | null;
  objectiveEn: string | null;
  whatHappenedEn: string | null;
  participantCount: number | null;
}

const BANGLA_DIGITS = ["০", "১", "২", "৩", "৪", "৫", "৬", "৭", "৮", "৯"];
const BANGLA_MONTHS = [
  "জানুয়ারি", "ফেব্রুয়ারি", "মার্চ", "এপ্রিল", "মে", "জুন",
  "জুলাই", "আগস্ট", "সেপ্টেম্বর", "অক্টোবর", "নভেম্বর", "ডিসেম্বর",
];

function toBnDigits(value: string | number): string {
  return String(value).replace(/[0-9]/g, (d) => BANGLA_DIGITS[Number(d)]);
}

/** "2026-09-05T00:00:00.000000Z" -> "৫ সেপ্টেম্বর, ২০২৬" — human-readable Bengali,
 *  matching the format lib/content.ts's own `timeline` array already uses on
 *  the About page, and the same date philosophy admin's bn_date() uses (see
 *  DESIGN_SYSTEM.md's date policy). Splits the ISO string directly rather
 *  than parsing a Date object, so there's no timezone-shift risk.
 *  lib/content.ts's recentActivities dates are hand-written in this same
 *  format — see the note there if either changes. */
function toDisplayDate(isoDatetime: string | null): string {
  if (!isoDatetime) return "";
  const [year, month, day] = isoDatetime.slice(0, 10).split("-").map(Number);
  const monthName = BANGLA_MONTHS[month - 1] ?? "";
  return `${toBnDigits(day)} ${monthName}, ${toBnDigits(year)}`;
}

function activityToDisplay(a: Activity): DisplayActivity {
  return {
    slug: a.slug,
    date: toDisplayDate(a.start_datetime),
    title: a.title,
    titleEn: a.title_en,
    place: a.venue ?? "",
    category: a.type?.name ?? "",
    categoryEn: a.type?.name_en ?? null,
    photos: a.gallery,
    outcomes: a.outcomes,
    outcomesEn: a.outcomes_en,
    // Prefer the curated summary; fall back to the freeform "what happened"
    // narrative if only that was filled in. Never fabricated — both are
    // simply unused API fields until an admin actually writes them.
    summary: a.summary ?? a.what_happened,
    summaryEn: a.summary_en ?? a.what_happened_en,
    descriptionEn: a.description_en,
    objectiveEn: a.objective_en,
    whatHappenedEn: a.what_happened_en,
    // A recorded 0 is indistinguishable from "field never filled in" on this
    // form, so only a genuinely positive count is treated as a real fact —
    // never render an unset default as if it were a fabricated headcount.
    participantCount: a.participant_count && a.participant_count > 0 ? a.participant_count : null,
  };
}

/**
 * lib/content.en.ts's `recentActivities` mirrors lib/content.ts's own —
 * indices line up 1:1 (same three real records), so the English fallback
 * copy is looked up by index rather than duplicating slugs/dates/places here.
 */
function fallbackToDisplay(): DisplayActivity[] {
  return recentActivities.map((a, i) => {
    const en = recentActivitiesEn[i];
    return {
      slug: a.slug,
      date: a.date,
      title: a.title,
      titleEn: en?.title ?? null,
      place: a.place,
      category: a.category,
      categoryEn: en?.category ?? null,
      photos: a.photos,
      outcomes: a.outcomes,
      outcomesEn: en?.outcomes ?? null,
      summary: a.summary,
      summaryEn: en?.summary ?? null,
      descriptionEn: null,
      objectiveEn: null,
      whatHappenedEn: null,
      participantCount: a.participantCount,
    };
  });
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
