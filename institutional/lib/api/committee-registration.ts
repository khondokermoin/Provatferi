import { apiGet, apiPostForm, isRecord, isStringOrNull, type ApiSubmitResult } from "./client";
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

function isSubmissionIdResponse(v: unknown): v is { data: { id: number } } {
  return isRecord(v) && isRecord(v.data) && typeof v.data.id === "number";
}

/**
 * §22-25/§41: a nominee's registration. The token is the one in the URL the nominee was sent — set here, from the
 * caller's argument, over whatever the browser's form may have carried: a submission can only ever be filed
 * against the link that was actually opened. Shared by the form's route handler and its no-JavaScript Server Action.
 */
export async function postCommitteeRegistration(token: string, formData: FormData): Promise<ApiSubmitResult<{ data: { id: number } }>> {
  formData.set("registration_token", token);
  return apiPostForm("/api/v1/public/committee-submissions", formData, { validate: isSubmissionIdResponse });
}

/**
 * §27/§41: the single-use correction. Laravel refuses a spent or expired token (404), so a resubmission can only
 * ever happen once per issued link — that is the idempotency here, not anything this layer adds.
 */
export async function postCommitteeCorrection(token: string, formData: FormData): Promise<ApiSubmitResult<{ data: { id: number } }>> {
  return apiPostForm(`/api/v1/public/committee-submissions/correction/${encodeURIComponent(token)}`, formData, { validate: isSubmissionIdResponse });
}

export async function getCorrectionSubmission(token: string): Promise<ApiResult<CommitteeCorrectionInfo>> {
  const result = await apiGet<{ data: CommitteeCorrectionInfo }>(
    `/api/v1/public/committee-submissions/correction/${encodeURIComponent(token)}`,
    { validate: isCorrectionResponse, revalidateSeconds: NO_CACHE_SECONDS },
  );

  return result.ok ? { ok: true, data: result.data.data } : result;
}
