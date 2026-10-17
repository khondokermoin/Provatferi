import { apiGet, isNumberOrNull, isRecord, isStringOrNull } from "./client";
import type { ApiResult, LeadershipDetail, LeadershipPhoto, LeadershipSummary } from "./types";

/**
 * Leadership / Key Persons (2026-10-15): the institution's permanent, core people — a different thing from a committee,
 * whose members belong to a term. The ERP's public API returns ACTIVE profiles only, in the admin-defined order (ties
 * broken by id), and nothing else.
 *
 * The cache tag Laravel invalidates (a signed POST /api/revalidate, see lib/revalidate-request.ts) the moment an admin
 * creates, edits, changes the photo of, activates, deactivates, features, reorders or archives a profile — so a change
 * shows within seconds. Every fetch below carries it: the index, the homepage section and each profile page.
 */
export const LEADERSHIP_CACHE_TAG = "leadership";

/** Safety net only: on-demand invalidation is the normal path; this bounds how stale a page can be if a call is lost. */
const REVALIDATE_SECONDS = 300;

function isPhoto(v: unknown): v is LeadershipPhoto {
  return isRecord(v) && typeof v.url === "string" && typeof v.sm_url === "string" && typeof v.width === "number" && typeof v.height === "number";
}

function isSocialList(v: unknown): v is Array<{ platform: string; url: string }> {
  return Array.isArray(v) && v.every((entry) => isRecord(entry) && typeof entry.platform === "string" && typeof entry.url === "string");
}

function isLeadershipSummary(v: unknown): v is LeadershipSummary {
  return (
    isRecord(v) &&
    typeof v.slug === "string" &&
    typeof v.name_bn === "string" &&
    isStringOrNull(v.name_en) &&
    typeof v.designation_bn === "string" &&
    isStringOrNull(v.designation_en) &&
    isStringOrNull(v.short_bio_bn) &&
    isStringOrNull(v.short_bio_en) &&
    isStringOrNull(v.expertise_bn) &&
    isStringOrNull(v.expertise_en) &&
    typeof v.featured === "boolean" &&
    (v.photo === null || isPhoto(v.photo)) &&
    isSocialList(v.social) &&
    isStringOrNull(v.website_url)
  );
}

function isLeadershipDetail(v: unknown): v is LeadershipDetail {
  return (
    isLeadershipSummary(v) &&
    isRecord(v) &&
    isStringOrNull(v.full_bio_bn) &&
    isStringOrNull(v.full_bio_en) &&
    isStringOrNull(v.quote_bn) &&
    isStringOrNull(v.quote_en) &&
    isNumberOrNull(v.associated_since_year) &&
    isStringOrNull(v.public_email) &&
    isStringOrNull(v.public_phone)
  );
}

function isListResponse(json: unknown): json is { data: LeadershipSummary[] } {
  return isRecord(json) && Array.isArray(json.data) && json.data.every(isLeadershipSummary);
}

function isDetailResponse(json: unknown): json is { data: LeadershipDetail } {
  return isRecord(json) && isLeadershipDetail(json.data);
}

/** The active profiles in display order. `featuredOnly` and `limit` are for the homepage section. */
export async function getLeadershipProfiles(options: { featuredOnly?: boolean; limit?: number } = {}): Promise<ApiResult<LeadershipSummary[]>> {
  const query = new URLSearchParams();
  if (options.featuredOnly) query.set("featured", "1");
  if (options.limit !== undefined) query.set("limit", String(options.limit));
  const suffix = query.size > 0 ? `?${query.toString()}` : "";

  const result = await apiGet<{ data: LeadershipSummary[] }>(`/api/v1/public/leadership${suffix}`, {
    validate: isListResponse,
    revalidateSeconds: REVALIDATE_SECONDS,
    tags: [LEADERSHIP_CACHE_TAG],
  });

  return result.ok ? { ok: true, data: result.data.data } : result;
}

/**
 * One active profile by slug. The slug may be one the profile used to have: the response carries the CURRENT slug, so a
 * page that asked for `old-address` and got `slug: "new-address"` knows to redirect (permanently) to the new address.
 * An inactive, archived or unknown profile is a failed result — the page answers it with a 404.
 */
export async function getLeadershipProfile(slug: string): Promise<ApiResult<LeadershipDetail>> {
  const result = await apiGet<{ data: LeadershipDetail }>(`/api/v1/public/leadership/${encodeURIComponent(slug)}`, {
    validate: isDetailResponse,
    revalidateSeconds: REVALIDATE_SECONDS,
    tags: [LEADERSHIP_CACHE_TAG],
  });

  return result.ok ? { ok: true, data: result.data.data } : result;
}
