import { apiGet, isRecord, isStringOrNull } from "./client";
import type { ApiResult, JobPosting, SkillOption } from "./types";

function isSkillOption(v: unknown): v is SkillOption {
  return isRecord(v) && typeof v.key === "string" && typeof v.label === "string";
}

function isNoticeAction(v: unknown): v is { url: string; label: string } | null {
  return v === null || (isRecord(v) && typeof v.url === "string" && typeof v.label === "string");
}

function isFieldRequirements(v: unknown): v is Record<string, "required" | "optional"> | null {
  return v === null || (isRecord(v) && Object.values(v).every((x) => x === "required" || x === "optional"));
}

/** Postings can open/close day to day — a shorter window than static content. */
const REVALIDATE_SECONDS = 120;

export function isJobPosting(v: unknown): v is JobPosting {
  return (
    isRecord(v) &&
    typeof v.id === "number" &&
    typeof v.title === "string" &&
    typeof v.slug === "string" &&
    isStringOrNull(v.summary) &&
    isStringOrNull(v.department) &&
    isStringOrNull(v.description) &&
    isStringOrNull(v.requirements) &&
    isStringOrNull(v.organization_unit) &&
    isStringOrNull(v.employment_type) &&
    isStringOrNull(v.employment_type_label) &&
    typeof v.is_volunteer === "boolean" &&
    isStringOrNull(v.volunteer_note) &&
    isStringOrNull(v.salary_range) &&
    typeof v.application_mode === "string" &&
    typeof v.application_mode_label === "string" &&
    isStringOrNull(v.opening_date) &&
    isStringOrNull(v.application_deadline) &&
    isStringOrNull(v.published_at) &&
    isStringOrNull(v.notice_slug) &&
    typeof v.accepts_applications === "boolean" &&
    isStringOrNull(v.apply_path) &&
    isNoticeAction(v.notice_action) &&
    (v.skill_options === null || (Array.isArray(v.skill_options) && v.skill_options.every(isSkillOption))) &&
    // Same undefined-tolerant shape as share_image_url just below — a live
    // ERP not yet carrying this deploy would omit the key entirely rather
    // than send null, and a strict check would reject every posting.
    (v.field_requirements === undefined || isFieldRequirements(v.field_requirements)) &&
    // §12: optional-or-null, not just nullable — see the identical note on
    // isNoticeDetail in notices.ts. This build's own log caught the hazard:
    // the live ERP (not yet migrated) omits this key entirely, and a strict
    // isStringOrNull() rejected every posting production actually returned.
    (v.share_image_url === undefined || isStringOrNull(v.share_image_url))
  );
}

/** "স্বেচ্ছাসেবী হিসেবে আবেদন করুন" / "আবেদন করুন" — the primary CTA's wording, decided in one place. */
export function applyCtaLabel(isVolunteer: boolean): string {
  return isVolunteer ? "স্বেচ্ছাসেবী হিসেবে আবেদন করুন" : "আবেদন করুন";
}

/**
 * A WhatsApp destination is named for what it is, so the secondary button
 * never reads like the way to apply. Any other action keeps the label the
 * admin gave it.
 */
export function communityCtaLabel(url: string, fallbackLabel: string): string {
  return /(^|\/\/|\.)(wa\.me|chat\.whatsapp\.com)/.test(url) ? "WhatsApp Group-এ যুক্ত হোন" : fallbackLabel;
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

export async function getJobPosting(slug: string): Promise<ApiResult<JobPosting>> {
  const result = await apiGet<{ data: JobPosting }>(`/api/v1/job-postings/${encodeURIComponent(slug)}`, {
    validate: isJobPostingResponse,
    revalidateSeconds: REVALIDATE_SECONDS,
  });

  return result.ok ? { ok: true, data: result.data.data } : result;
}

/** "আবেদন চলমান" / "শেষ তারিখ ১৫ সেপ্টেম্বর ২০২৬" — the one place this wording is decided. */
export function applicationWindowLabel(
  job: Pick<JobPosting, "application_mode" | "application_deadline">,
  formatDate: (value: string | null) => string | null,
  isOpen = true,
): string {
  if (!isOpen) return "আবেদন বন্ধ";
  if (job.application_mode === "rolling") return "আবেদন চলমান";
  const deadline = formatDate(job.application_deadline);
  return deadline ? `শেষ তারিখ ${deadline}` : "শেষ তারিখ এখনো ঘোষিত হয়নি";
}
