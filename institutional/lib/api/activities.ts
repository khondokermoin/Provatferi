import { apiGet, isRecord, isNumberOrNull, isStringOrNull } from "./client";
import type { Activity, ActivityListResponse, ApiResult } from "./types";

/**
 * NOT WIRED INTO ANY PAGE. Built now so the layer covers all seven domains
 * from the integration plan, but the activities cutover is explicitly
 * stopped: production GET /api/v1/activities returns zero records as of
 * 2026-09-10, while the site has three real, dated activities
 * (lib/content.ts `recentActivities`, backing both the /activities listing
 * and generateStaticParams for /activities/[slug]). Per the integration
 * order, activities move last, and only once the API has all three — moving
 * early would make the public site lose real activity pages it has today.
 *
 * When all three exist in the ERP, the listing, detail route,
 * generateStaticParams, sitemap entries and metadata must switch together
 * (see app/(site)/activities/page.tsx and app/(site)/activities/[slug]/page.tsx)
 * — this file is what that cutover would call.
 */
const REVALIDATE_SECONDS = 120;

function isActivity(v: unknown): v is Activity {
  if (!isRecord(v)) return false;
  const type = v.type;
  const unit = v.organizationUnit;

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
