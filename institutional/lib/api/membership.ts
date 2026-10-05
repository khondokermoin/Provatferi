import { apiGet, apiPostForm, isOptionalString, isRecord, isNumberOrNull, isStringOrNull, type ApiSubmitResult } from "./client";
import type { ApiResult, CampaignMembershipType, MembershipCampaign, MembershipType } from "./types";

const REVALIDATE_SECONDS = 300;
// Campaign status can flip the moment a season opens/closes — much shorter
// than the membership-types list, which barely ever changes.
const CAMPAIGN_REVALIDATE_SECONDS = 60;

/**
 * The fee-policy fields (2026-10-05). Optional on purpose: a response cached from before the change has none of them and
 * must still be accepted (it carries the deprecated `fee`). When one IS present it must be well formed — a malformed
 * amount is rejected like any other malformed field rather than being rendered.
 */
function hasValidFeeFields(v: Record<string, unknown>): boolean {
  const amount = (x: unknown) => x === undefined || (typeof x === "string" && /^\d{1,8}(\.\d{1,2})?$/.test(x));
  return amount(v.registration_fee) && amount(v.monthly_contribution) && (v.fee_effective_from === undefined || typeof v.fee_effective_from === "string") && isOptionalString(v.code);
}

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
    hasValidFeeFields(v) &&
    typeof v.is_student === "boolean" &&
    typeof v.is_public_self_apply === "boolean"
  );
}

/** The same type as it appears inside a campaign: the flag is implied there (see CampaignMembershipType), so it is optional. */
function isCampaignMembershipType(v: unknown): v is CampaignMembershipType {
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
    hasValidFeeFields(v) &&
    typeof v.is_student === "boolean" &&
    (v.is_public_self_apply === undefined || typeof v.is_public_self_apply === "boolean")
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
    v.membership_types.every(isCampaignMembershipType)
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

function isApplicationCreatedResponse(v: unknown): v is { data: { application_no: string } } {
  return isRecord(v) && isRecord(v.data) && typeof v.data.application_no === "string";
}

/**
 * §7/§41: the browser's own FormData goes straight to admin-erp's public intake endpoint — the field names already
 * match what MembershipApplicationController::store() validates. A 422 (honeypot tripped, closed season,
 * non-self-apply type, bad photo) surfaces as field-level messages, never a generic failure. Shared by the form's
 * route handler and its no-JavaScript Server Action.
 */
export async function postMembershipApplication(formData: FormData): Promise<ApiSubmitResult<{ data: { application_no: string } }>> {
  return apiPostForm("/api/v1/public/membership/applications", formData, { validate: isApplicationCreatedResponse });
}
