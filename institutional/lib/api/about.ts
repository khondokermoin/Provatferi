import { apiGet, isRecord, isStringOrNull } from "./client";
import type { AboutResponse, AboutSection, ApiResult, ContentBlock, Objective } from "./types";

const REVALIDATE_SECONDS = 300;

function isAboutSection(v: unknown): v is AboutSection {
  return (
    isRecord(v) &&
    isStringOrNull(v.introduction) &&
    isStringOrNull(v.description) &&
    isStringOrNull(v.history) &&
    isStringOrNull(v.why_exists) &&
    isStringOrNull(v.identity_explanation) &&
    isStringOrNull(v.registration_status)
  );
}

function isContentBlock(v: unknown): v is ContentBlock {
  return isRecord(v) && typeof v.body === "string" && isStringOrNull(v.updated_at);
}

function isObjective(v: unknown): v is Objective {
  return (
    isRecord(v) &&
    typeof v.id === "number" &&
    isStringOrNull(v.title) &&
    typeof v.body === "string" &&
    typeof v.sort_order === "number"
  );
}

function isAboutResponse(json: unknown): json is { data: AboutResponse } {
  if (!isRecord(json) || !isRecord(json.data)) return false;
  const d = json.data;

  return (
    (d.about === null || isAboutSection(d.about)) &&
    (d.mission === null || isContentBlock(d.mission)) &&
    (d.vision === null || isContentBlock(d.vision)) &&
    Array.isArray(d.objectives) &&
    d.objectives.every(isObjective)
  );
}

export async function getAbout(): Promise<ApiResult<AboutResponse>> {
  const result = await apiGet<{ data: AboutResponse }>("/api/v1/about", {
    validate: isAboutResponse,
    revalidateSeconds: REVALIDATE_SECONDS,
  });

  return result.ok ? { ok: true, data: result.data.data } : result;
}
