import { apiGet, isOptionalString, isRecord, isNumberOrNull, isStringOrNull } from "./client";
import type { ApiResult, PublicCommitteeDetail, PublicCommitteeMember, PublicCommitteeSummary, PublicCommitteesIndexResponse } from "./types";

const REVALIDATE_SECONDS = 300;

function isCommitteeSummary(v: unknown): v is PublicCommitteeSummary {
  return (
    isRecord(v) &&
    typeof v.id === "number" &&
    typeof v.slug === "string" &&
    typeof v.name === "string" &&
    isOptionalString(v.name_en) &&
    isStringOrNull(v.committee_type) &&
    isStringOrNull(v.term_start) &&
    isStringOrNull(v.term_end) &&
    typeof v.status === "string" &&
    isStringOrNull(v.description) &&
    isOptionalString(v.description_en)
  );
}

function isCommitteesIndexResponse(json: unknown): json is { data: PublicCommitteesIndexResponse } {
  if (!isRecord(json) || !isRecord(json.data)) return false;
  const d = json.data;
  return (
    (d.current === null || isCommitteeSummary(d.current)) &&
    Array.isArray(d.upcoming) &&
    d.upcoming.every(isCommitteeSummary) &&
    Array.isArray(d.previous) &&
    d.previous.every(isCommitteeSummary)
  );
}

export async function getCommittees(): Promise<ApiResult<PublicCommitteesIndexResponse>> {
  const result = await apiGet<{ data: PublicCommitteesIndexResponse }>("/api/v1/public/committees", {
    validate: isCommitteesIndexResponse,
    revalidateSeconds: REVALIDATE_SECONDS,
  });

  return result.ok ? { ok: true, data: result.data.data } : result;
}

function isCommitteeMember(v: unknown): v is PublicCommitteeMember {
  return (
    isRecord(v) &&
    typeof v.name === "string" &&
    isOptionalString(v.name_en) &&
    typeof v.position === "string" &&
    isOptionalString(v.position_en) &&
    isNumberOrNull(v.serial_no) &&
    isStringOrNull(v.photo_url) &&
    isStringOrNull(v.bio) &&
    isStringOrNull(v.facebook_url) &&
    isStringOrNull(v.linkedin_url) &&
    isStringOrNull(v.website_url)
  );
}

function isCommitteeDetail(v: unknown): v is PublicCommitteeDetail {
  if (!isCommitteeSummary(v) || !isRecord(v)) return false;
  return Array.isArray(v.members) && v.members.every(isCommitteeMember);
}

function isCommitteeDetailResponse(json: unknown): json is { data: PublicCommitteeDetail } {
  return isRecord(json) && isCommitteeDetail(json.data);
}

export async function getCommittee(slugOrId: string): Promise<ApiResult<PublicCommitteeDetail>> {
  const result = await apiGet<{ data: PublicCommitteeDetail }>(`/api/v1/public/committees/${encodeURIComponent(slugOrId)}`, {
    validate: isCommitteeDetailResponse,
    revalidateSeconds: REVALIDATE_SECONDS,
  });

  return result.ok ? { ok: true, data: result.data.data } : result;
}
