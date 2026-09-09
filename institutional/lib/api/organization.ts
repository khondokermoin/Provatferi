import { apiGet, isRecord, isNumberOrNull, isStringOrNull } from "./client";
import type { ApiResult, OrganizationUnit } from "./types";

/**
 * Branch/unit data changes about as often as settings does — same 300s
 * window. This is deliberately NOT the governance/committee roster: that
 * has no public endpoint at all (see app/(site)/organization/page.tsx,
 * which keeps governancePositions from lib/content.ts as static content —
 * this file only ever feeds the office/address section of that page).
 */
const REVALIDATE_SECONDS = 300;

function isOrganizationUnit(v: unknown): v is OrganizationUnit {
  return (
    isRecord(v) &&
    typeof v.id === "number" &&
    isNumberOrNull(v.parent_id) &&
    typeof v.name === "string" &&
    typeof v.slug === "string" &&
    typeof v.unit_type === "string" &&
    isStringOrNull(v.address) &&
    isStringOrNull(v.phone) &&
    isStringOrNull(v.email)
  );
}

function isOrganizationUnitsResponse(json: unknown): json is { data: OrganizationUnit[] } {
  return isRecord(json) && Array.isArray(json.data) && json.data.every(isOrganizationUnit);
}

export async function getOrganizationUnits(): Promise<ApiResult<OrganizationUnit[]>> {
  const result = await apiGet<{ data: OrganizationUnit[] }>("/api/v1/organization-units", {
    validate: isOrganizationUnitsResponse,
    revalidateSeconds: REVALIDATE_SECONDS,
  });

  return result.ok ? { ok: true, data: result.data.data } : result;
}
