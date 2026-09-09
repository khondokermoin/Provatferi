import { apiGet, isRecord, isStringOrNull } from "./client";
import type { ApiResult, SettingsResponse } from "./types";

/**
 * Settings rarely change — a ~5 minute ISR window (300s) trades a little
 * staleness for far fewer requests against the ERP, per the suggested
 * default for this content type.
 */
const REVALIDATE_SECONDS = 300;

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
    isStringOrNull(contact.phone) &&
    isStringOrNull(contact.address) &&
    isStringOrNull(contact.facebook_url) &&
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
  });

  return result.ok ? { ok: true, data: result.data.data } : result;
}
