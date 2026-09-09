import { apiGet, isRecord, isStringOrNull } from "./client";
import type { ApiResult, JobPosting } from "./types";

/** Postings can open/close day to day — a shorter window than static content. */
const REVALIDATE_SECONDS = 120;

function isJobPosting(v: unknown): v is JobPosting {
  return (
    isRecord(v) &&
    typeof v.id === "number" &&
    typeof v.title === "string" &&
    typeof v.slug === "string" &&
    isStringOrNull(v.summary) &&
    isStringOrNull(v.department) &&
    isStringOrNull(v.description) &&
    isStringOrNull(v.requirements) &&
    isStringOrNull(v.employment_type) &&
    isStringOrNull(v.salary_range) &&
    isStringOrNull(v.opening_date) &&
    isStringOrNull(v.application_deadline) &&
    isStringOrNull(v.published_at)
  );
}

function isJobPostingsResponse(json: unknown): json is { data: JobPosting[] } {
  return isRecord(json) && Array.isArray(json.data) && json.data.every(isJobPosting);
}

function isJobPostingResponse(json: unknown): json is { data: JobPosting } {
  return isRecord(json) && isJobPosting(json.data);
}

export async function getJobPostings(): Promise<ApiResult<JobPosting[]>> {
  const result = await apiGet<{ data: JobPosting[] }>("/api/v1/job-postings", {
    validate: isJobPostingsResponse,
    revalidateSeconds: REVALIDATE_SECONDS,
  });

  return result.ok ? { ok: true, data: result.data.data } : result;
}

/** Not yet used by any page — no job-posting detail route exists on the
 *  institutional site today. Included so the layer covers the full public
 *  contract, ready for when that route is added. */
export async function getJobPosting(slug: string): Promise<ApiResult<JobPosting>> {
  const result = await apiGet<{ data: JobPosting }>(`/api/v1/job-postings/${encodeURIComponent(slug)}`, {
    validate: isJobPostingResponse,
    revalidateSeconds: REVALIDATE_SECONDS,
  });

  return result.ok ? { ok: true, data: result.data.data } : result;
}
