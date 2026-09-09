import { apiGet, isRecord, isNumberOrNull, isStringOrNull } from "./client";
import type { ApiResult, MembershipType } from "./types";

const REVALIDATE_SECONDS = 300;

function isMembershipType(v: unknown): v is MembershipType {
  return (
    isRecord(v) &&
    typeof v.id === "number" &&
    typeof v.name === "string" &&
    typeof v.slug === "string" &&
    isStringOrNull(v.description) &&
    isNumberOrNull(v.duration_months) &&
    typeof v.fee === "string" &&
    typeof v.is_student === "boolean"
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
