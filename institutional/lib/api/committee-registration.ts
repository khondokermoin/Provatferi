import { apiGet, isRecord, isStringOrNull } from "./client";
import type { ApiResult, CommitteeCorrectionInfo, CommitteePositionOption, CommitteeRegistrationLinkInfo } from "./types";

// Never cached — a token's validity (unused, unexpired, unrevoked) can
// change between two requests seconds apart, and serving a stale "valid"
// verdict for a now-revoked link would be a real security lapse, not just
// stale content.
const NO_CACHE_SECONDS = 0;

function isPositionOption(v: unknown): v is CommitteePositionOption {
  return isRecord(v) && typeof v.id === "number" && typeof v.name === "string" && (v.occupied === undefined || typeof v.occupied === "boolean");
}

function isRegistrationLinkResponse(json: unknown): json is { data: CommitteeRegistrationLinkInfo } {
  if (!isRecord(json) || !isRecord(json.data)) return false;
  const d = json.data;
  return (
    isRecord(d.committee) &&
    typeof d.committee.id === "number" &&
    typeof d.committee.name === "string" &&
    typeof d.committee.slug === "string" &&
    Array.isArray(d.positions) &&
    d.positions.every(isPositionOption)
  );
}

export async function getRegistrationLink(token: string): Promise<ApiResult<CommitteeRegistrationLinkInfo>> {
  const result = await apiGet<{ data: CommitteeRegistrationLinkInfo }>(
    `/api/v1/public/committees/registration-links/${encodeURIComponent(token)}`,
    { validate: isRegistrationLinkResponse, revalidateSeconds: NO_CACHE_SECONDS },
  );

  return result.ok ? { ok: true, data: result.data.data } : result;
}

function isCorrectionResponse(json: unknown): json is { data: CommitteeCorrectionInfo } {
  if (!isRecord(json) || !isRecord(json.data)) return false;
  const d = json.data;
  return (
    isRecord(d.committee) &&
    typeof d.committee.id === "number" &&
    typeof d.committee.name === "string" &&
    isStringOrNull(d.admin_note) &&
    typeof d.full_name === "string" &&
    isStringOrNull(d.name_en) &&
    typeof d.email === "string" &&
    typeof d.phone === "string" &&
    isStringOrNull(d.bio) &&
    typeof d.provatferi_comment === "string" &&
    isStringOrNull(d.facebook_url) &&
    isStringOrNull(d.linkedin_url) &&
    isStringOrNull(d.website_url) &&
    typeof d.committee_position_id === "number" &&
    Array.isArray(d.positions) &&
    d.positions.every(isPositionOption)
  );
}

export async function getCorrectionSubmission(token: string): Promise<ApiResult<CommitteeCorrectionInfo>> {
  const result = await apiGet<{ data: CommitteeCorrectionInfo }>(
    `/api/v1/public/committee-submissions/correction/${encodeURIComponent(token)}`,
    { validate: isCorrectionResponse, revalidateSeconds: NO_CACHE_SECONDS },
  );

  return result.ok ? { ok: true, data: result.data.data } : result;
}
