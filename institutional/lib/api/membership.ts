import { apiGet, apiPostForm, isOptionalString, isRecord, isNumberOrNull, isStringOrNull, type ApiSubmitResult } from "./client";
import type { ApiResult, CampaignMembershipType, MembershipCampaign, MembershipType } from "./types";

/**
 * The cache tags the membership lookups carry. admin-erp invalidates them through the signed POST /api/revalidate
 * (App\Services\PublicSiteRevalidator) the moment an admin changes anything the membership page is built from:
 *
 *   membership          every membership lookup — an umbrella ("purge all membership data"); no observer sends it alone
 *   membership-seasons  a season created / edited / opened / closed / deleted, or the types it offers changed
 *   membership-types    a type created / edited / enabled / hidden / reordered / deleted
 *   membership-fees     a fee policy created or cancelled
 *
 * The season lookup embeds types and their fees, so it carries all four; the type list carries all but `seasons`.
 */
export const MEMBERSHIP_CACHE_TAGS = {
  all: "membership",
  seasons: "membership-seasons",
  types: "membership-types",
  fees: "membership-fees",
} as const;

const TYPE_LIST_TAGS = [MEMBERSHIP_CACHE_TAGS.all, MEMBERSHIP_CACHE_TAGS.types, MEMBERSHIP_CACHE_TAGS.fees];
const CAMPAIGN_TAGS = [MEMBERSHIP_CACHE_TAGS.all, MEMBERSHIP_CACHE_TAGS.seasons, MEMBERSHIP_CACHE_TAGS.types, MEMBERSHIP_CACHE_TAGS.fees];

/**
 * Safety net only — the same role it plays in lib/api/carousel.ts. What an admin changes is invalidated on demand
 * (above); what the clock changes is covered by `meta.valid_until` (below). This bounds how stale a page can be if a
 * revalidation call is ever lost, and it is short on purpose: a time-to-live alone can NEVER be right on the first
 * request after a change (the cache serves the old answer once while it refreshes) — which is exactly the bug that
 * showed an opened season as closed for up to a minute.
 */
const REVALIDATE_SECONDS = 15;

/**
 * What admin-erp adds to every public membership answer (App\Services\MembershipPublicState): `valid_until` is the first
 * instant the answer can change BY ITSELF — a season opening or closing, a fee policy starting or ending at midnight on
 * the organisation's calendar. Absent on an answer from before the field existed, and null when nothing is scheduled.
 */
interface LookupMeta {
  valid_until?: string | null;
  generated_at?: string | null;
}

function isLookupMeta(v: unknown): v is LookupMeta | undefined {
  return v === undefined || (isRecord(v) && isOptionalString(v.valid_until) && isOptionalString(v.generated_at));
}

/**
 * True when a cached answer has outlived the moment its own `valid_until` named, so it must not be used. A value that
 * cannot be read counts as expired: when in doubt, ask Laravel.
 */
export function isPastValidity(meta: LookupMeta | undefined, now: number = Date.now()): boolean {
  const until = meta?.valid_until;
  if (until === undefined || until === null) return false;
  const at = Date.parse(until);
  return Number.isNaN(at) || now >= at;
}

/**
 * One membership lookup, exact on the FIRST request after any change:
 *   - admin changes are announced (tags above), so the cached copy is simply gone;
 *   - clock changes are announced by the answer itself: a cached copy past its `valid_until` is not used — Laravel is
 *     asked again, uncached, and that answer (always current) is what the page renders. The stale entry is replaced the
 *     next time the short safety-net window above lapses, at the cost of one extra request until then.
 * A failed fetch is not retried: the page falls back exactly as it always has, and a second attempt would only double
 * the wait during an outage.
 */
async function lookup<T extends { data: unknown[]; meta?: LookupMeta }>(
  path: string,
  validate: (json: unknown) => json is T,
  tags: readonly string[],
): Promise<ApiResult<T>> {
  const cached = await apiGet<T>(path, { validate, revalidateSeconds: REVALIDATE_SECONDS, tags: [...tags] });
  if (!cached.ok || !isPastValidity(cached.data.meta)) return cached;

  return apiGet<T>(path, { validate, revalidateSeconds: 0, live: true });
}

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

type MembershipTypesBody = { data: MembershipType[]; meta?: LookupMeta };

function isMembershipTypesResponse(json: unknown): json is MembershipTypesBody {
  return isRecord(json) && Array.isArray(json.data) && json.data.every(isMembershipType) && isLookupMeta(json.meta);
}

export async function getMembershipTypes(): Promise<ApiResult<MembershipType[]>> {
  const result = await lookup<MembershipTypesBody>("/api/v1/membership-types", isMembershipTypesResponse, TYPE_LIST_TAGS);

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

type CampaignsBody = { data: MembershipCampaign[]; meta?: LookupMeta };

function isCampaignsResponse(json: unknown): json is CampaignsBody {
  return isRecord(json) && Array.isArray(json.data) && json.data.every(isMembershipCampaign) && isLookupMeta(json.meta);
}

/**
 * Never a single nullable campaign — see MembershipCampaign's own comment. Which seasons are open is decided by
 * Laravel against the clock at the moment of each uncached request; this is only ever as old as the safety-net window,
 * is dropped the instant an admin changes a season, and is never used past the `valid_until` Laravel names.
 */
export async function getCurrentCampaigns(): Promise<ApiResult<MembershipCampaign[]>> {
  const result = await lookup<CampaignsBody>("/api/v1/public/membership/campaigns/current", isCampaignsResponse, CAMPAIGN_TAGS);

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
