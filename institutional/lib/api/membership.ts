import { apiGet, isOptionalString, isRecord, isNumberOrNull, isStringOrNull } from "./client";
import type { ApiResult, MembershipCampaign, MembershipType } from "./types";

const REVALIDATE_SECONDS = 300;
// Campaign status can flip the moment a season opens/closes — much shorter
// than the membership-types list, which barely ever changes.
const CAMPAIGN_REVALIDATE_SECONDS = 60;

function isMembershipType(v: unknown): v is MembershipType {
  return (
    isRecord(v) &&
    typeof v.id === "number" &&
    typeof v.name === "string" &&
    isOptionalString(v.name_en) &&
    typeof v.slug === "string" &&
    isStringOrNull(v.description) &&
    isOptionalString(v.description_en) &&
    isNumberOrNull(v.duration_months) &&
    typeof v.fee === "string" &&
    typeof v.is_student === "boolean" &&
    typeof v.is_public_self_apply === "boolean"
  );
}

function isMembershipTypesResponse(json: unknown): json is { data: MembershipType[] } {
  return isRecord(json) && Array.isArray(json.data) && json.data.every(isMembershipType);
}

export async function getMembershipTypes(): Promise<ApiResult<MembershipType[]>> {
  const result = await apiGet<{ data: MembershipType[] }>("/api/v1/membership-types", {
    validate: isMembershipTypesResponse,
    revalidateSeconds: REVALIDATE_SECONDS,
  });

  return result.ok ? { ok: true, data: result.data.data } : result;
}

function isMembershipCampaign(v: unknown): v is MembershipCampaign {
  return (
    isRecord(v) &&
    typeof v.id === "number" &&
    typeof v.name === "string" &&
    isStringOrNull(v.name_en) &&
    typeof v.slug === "string" &&
    (v.campaign_type === "regular" || v.campaign_type === "special") &&
    isStringOrNull(v.opens_at) &&
    isStringOrNull(v.closes_at) &&
    isStringOrNull(v.description) &&
    isStringOrNull(v.cash_payment_instructions) &&
    typeof v.public_profile_opt_in === "boolean" &&
    Array.isArray(v.membership_types) &&
    v.membership_types.every(isMembershipType)
  );
}

function isCampaignsResponse(json: unknown): json is { data: MembershipCampaign[] } {
  return isRecord(json) && Array.isArray(json.data) && json.data.every(isMembershipCampaign);
}

/** Never a single nullable campaign — see MembershipCampaign's own comment. */
export async function getCurrentCampaigns(): Promise<ApiResult<MembershipCampaign[]>> {
  const result = await apiGet<{ data: MembershipCampaign[] }>("/api/v1/public/membership/campaigns/current", {
    validate: isCampaignsResponse,
    revalidateSeconds: CAMPAIGN_REVALIDATE_SECONDS,
  });

  return result.ok ? { ok: true, data: result.data.data } : result;
}
