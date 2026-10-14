import { apiGet, isOptionalString, isRecord, isStringOrNull } from "./client";
import type { ApiResult, SettingsResponse } from "./types";

/**
 * The cache tag of the organisation's public settings (contact, social links, location). The ERP sends it to
 * /api/revalidate the moment an admin saves them (admin-erp: SettingObserver / PublicSiteRevalidator::SETTINGS_TAG), so
 * a change shows on the site within a second or two; it must stay in step with that constant and with the allow-list in
 * lib/revalidate-request.ts.
 */
export const SETTINGS_CACHE_TAG = "site-settings";

/**
 * The safety net, not the mechanism: if the signed call from the ERP is ever lost, the data is still re-read within this
 * window. Settings rarely change, so five minutes keeps the load on the ERP low.
 */
const REVALIDATE_SECONDS = 300;

function isSocialEntry(v: unknown): boolean {
  return isRecord(v) && typeof v.platform === "string" && typeof v.url === "string";
}

function isLocation(v: unknown): boolean {
  return isRecord(v) && typeof v.map_url === "string" && isOptionalString(v.label) && isOptionalString(v.label_en);
}

/**
 * Shape only. The new fields (support_email, address_en, social, location) are OPTIONAL here because the ERP and this site
 * deploy separately: a site build must keep working against an ERP that does not send them yet. What a value MAY BE (a
 * safe link, a publishable address) is decided in lib/contact-resolve.ts, which drops a bad entry instead of failing the
 * whole response.
 */
function isSettingsResponse(json: unknown): json is { data: SettingsResponse } {
  if (!isRecord(json) || !isRecord(json.data)) return false;
  const d = json.data;

  const organization = d.organization;
  const contact = d.contact;
  const seo = d.seo;
  const links = d.links;

  return (
    isRecord(organization) &&
    isStringOrNull(organization.name_bn) &&
    isStringOrNull(organization.name_en) &&
    isStringOrNull(organization.short_name) &&
    isStringOrNull(organization.acronym) &&
    isStringOrNull(organization.tagline) &&
    isRecord(contact) &&
    isStringOrNull(contact.email) &&
    isOptionalString(contact.support_email) &&
    isStringOrNull(contact.phone) &&
    isStringOrNull(contact.address) &&
    isOptionalString(contact.address_en) &&
    isStringOrNull(contact.facebook_url) &&
    (d.social === undefined || (Array.isArray(d.social) && d.social.every(isSocialEntry))) &&
    (d.location === undefined || d.location === null || isLocation(d.location)) &&
    isRecord(seo) &&
    isStringOrNull(seo.title) &&
    isStringOrNull(seo.description) &&
    Array.isArray(seo.alternate_names) &&
    isStringOrNull(seo.canonical_url) &&
    isRecord(links) &&
    isStringOrNull(links.website_url) &&
    isStringOrNull(links.literature_url)
  );
}

export async function getSettings(): Promise<ApiResult<SettingsResponse>> {
  const result = await apiGet<{ data: SettingsResponse }>("/api/v1/settings", {
    validate: isSettingsResponse,
    revalidateSeconds: REVALIDATE_SECONDS,
    tags: [SETTINGS_CACHE_TAG],
  });

  return result.ok ? { ok: true, data: result.data.data } : result;
}
