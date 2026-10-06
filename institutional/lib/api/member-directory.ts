import { apiGet, isRecord, isStringOrNull } from "./client";
import type { ApiResult, PublicMemberDetail, PublicMemberSummary } from "./types";

const REVALIDATE_SECONDS = 300;

/**
 * The cache tag of the public member directory. admin-erp sends it (signed, see lib/revalidate-request.ts) the moment
 * something the directory shows changes — a member suspended or archived in the Member Registry, a public profile
 * approved, a member turning their profile off — so they appear or disappear at once, not after REVALIDATE_SECONDS
 * (which stays only as a safety net). Membership Registry task 2, 2026-10-06.
 */
export const MEMBERS_CACHE_TAG = "members";

function isMemberSummary(v: unknown): v is PublicMemberSummary {
  return (
    isRecord(v) &&
    isStringOrNull(v.public_slug) &&
    typeof v.name === "string" &&
    isStringOrNull(v.profession) &&
    isStringOrNull(v.photo_url)
  );
}

function isDirectoryResponse(json: unknown): json is { data: PublicMemberSummary[] } {
  return isRecord(json) && Array.isArray(json.data) && json.data.every(isMemberSummary);
}

export async function getMemberDirectory(): Promise<ApiResult<PublicMemberSummary[]>> {
  const result = await apiGet<{ data: PublicMemberSummary[] }>("/api/v1/public/members", {
    validate: isDirectoryResponse,
    revalidateSeconds: REVALIDATE_SECONDS,
    tags: [MEMBERS_CACHE_TAG],
  });

  return result.ok ? { ok: true, data: result.data.data } : result;
}

function isMemberDetail(v: unknown): v is PublicMemberDetail {
  return (
    isMemberSummary(v) &&
    isRecord(v) &&
    isStringOrNull(v.bio) &&
    isStringOrNull(v.facebook_url) &&
    isStringOrNull(v.linkedin_url) &&
    isStringOrNull(v.website_url)
  );
}

function isMemberDetailResponse(json: unknown): json is { data: PublicMemberDetail } {
  return isRecord(json) && isMemberDetail(json.data);
}

export async function getMemberProfile(slug: string): Promise<ApiResult<PublicMemberDetail>> {
  const result = await apiGet<{ data: PublicMemberDetail }>(`/api/v1/public/members/${encodeURIComponent(slug)}`, {
    validate: isMemberDetailResponse,
    revalidateSeconds: REVALIDATE_SECONDS,
    tags: [MEMBERS_CACHE_TAG],
  });

  return result.ok ? { ok: true, data: result.data.data } : result;
}
