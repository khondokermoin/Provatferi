import { apiGet, isOptionalString, isRecord, isStringOrNull } from "./client";
import type {
  ApiResult,
  NoticeRecruitmentInfo,
  NoticeSitemapEntry,
  NoticeTypeFacet,
  PublicNoticeDetail,
  PublicNoticeList,
  PublicNoticeSummary,
} from "./types";

/** New notices should surface quickly; the board is small and cheap to refetch. */
const REVALIDATE_SECONDS = 120;

export interface NoticeQuery {
  type?: string;
  year?: number;
  q?: string;
  page?: number;
  perPage?: number;
}

export function buildNoticeQuery(query: NoticeQuery): string {
  const params = new URLSearchParams();
  if (query.type) params.set("type", query.type);
  if (query.year) params.set("year", String(query.year));
  if (query.q) params.set("q", query.q);
  if (query.page && query.page > 1) params.set("page", String(query.page));
  if (query.perPage) params.set("per_page", String(query.perPage));
  const search = params.toString();
  return search ? `?${search}` : "";
}

function isBoolean(v: unknown): v is boolean {
  return typeof v === "boolean";
}

export function isNoticeSummary(v: unknown): v is PublicNoticeSummary {
  return (
    isRecord(v) &&
    typeof v.slug === "string" &&
    typeof v.title === "string" &&
    isOptionalString(v.title_en) &&
    typeof v.notice_type === "string" &&
    typeof v.notice_type_label === "string" &&
    isStringOrNull(v.summary) &&
    isOptionalString(v.summary_en) &&
    typeof v.published_at === "string" &&
    isStringOrNull(v.expires_at) &&
    isBoolean(v.is_pinned) &&
    isBoolean(v.is_new) &&
    isBoolean(v.is_expired) &&
    isBoolean(v.is_archived)
  );
}

function isTypeFacet(v: unknown): v is NoticeTypeFacet {
  return isRecord(v) && typeof v.key === "string" && typeof v.label === "string" && typeof v.count === "number";
}

export function isNoticeList(json: unknown): json is PublicNoticeList {
  if (!isRecord(json) || !Array.isArray(json.data) || !json.data.every(isNoticeSummary)) return false;
  const { meta, filters } = json;
  return (
    isRecord(meta) &&
    typeof meta.current_page === "number" &&
    typeof meta.last_page === "number" &&
    typeof meta.per_page === "number" &&
    typeof meta.total === "number" &&
    isRecord(filters) &&
    Array.isArray(filters.types) &&
    filters.types.every(isTypeFacet) &&
    Array.isArray(filters.years) &&
    filters.years.every((year) => typeof year === "number")
  );
}

function isRecruitmentInfo(v: unknown): v is NoticeRecruitmentInfo {
  return (
    isRecord(v) &&
    isStringOrNull(v.slug) &&
    typeof v.title === "string" &&
    isOptionalString(v.title_en) &&
    isStringOrNull(v.employment_type_label) &&
    isBoolean(v.is_volunteer) &&
    isStringOrNull(v.volunteer_note) &&
    isStringOrNull(v.salary_range) &&
    typeof v.application_mode === "string" &&
    typeof v.application_mode_label === "string" &&
    isStringOrNull(v.opening_date) &&
    isStringOrNull(v.application_deadline) &&
    isBoolean(v.is_open) &&
    // The notice's primary CTA depends on these two, so a payload without
    // them is rejected rather than silently rendering a notice with no way
    // to apply.
    isBoolean(v.accepts_applications) &&
    isStringOrNull(v.apply_path)
  );
}

export function isNoticeDetail(v: unknown): v is PublicNoticeDetail {
  if (!isNoticeSummary(v)) return false;
  const record = v as unknown as Record<string, unknown>;
  const { action, attachment, recruitment } = record;
  return (
    typeof record.body === "string" &&
    isOptionalString(record.body_en) &&
    isStringOrNull(record.organization_unit) &&
    isOptionalString(record.organization_unit_en) &&
    isStringOrNull(record.cover_image_url) &&
    // §12: optional-or-null, not just nullable — the ERP and this site deploy
    // through separate pipelines, so a build can run against a not-yet-
    // migrated backend that omits the key entirely. Rejecting `undefined`
    // here would 404 every notice the moment this code ships first.
    (record.share_image_url === undefined || isStringOrNull(record.share_image_url)) &&
    isStringOrNull(record.updated_at) &&
    (action === null ||
      (isRecord(action) && typeof action.url === "string" && typeof action.label === "string" && isOptionalString(action.label_en))) &&
    (attachment === null ||
      (isRecord(attachment) &&
        typeof attachment.url === "string" &&
        (attachment.size === null || typeof attachment.size === "number") &&
        isStringOrNull(attachment.mime))) &&
    (recruitment === null || isRecruitmentInfo(recruitment))
  );
}

function isNoticeDetailResponse(json: unknown): json is { data: PublicNoticeDetail } {
  return isRecord(json) && isNoticeDetail(json.data);
}

function isSitemapResponse(json: unknown): json is { data: NoticeSitemapEntry[] } {
  return (
    isRecord(json) &&
    Array.isArray(json.data) &&
    json.data.every((entry) => isRecord(entry) && typeof entry.slug === "string" && isStringOrNull(entry.updated_at))
  );
}

export async function getNotices(query: NoticeQuery = {}): Promise<ApiResult<PublicNoticeList>> {
  return apiGet<PublicNoticeList>(`/api/v1/public/notices${buildNoticeQuery(query)}`, {
    validate: isNoticeList,
    revalidateSeconds: REVALIDATE_SECONDS,
  });
}

export async function getNotice(slug: string): Promise<ApiResult<PublicNoticeDetail>> {
  const result = await apiGet<{ data: PublicNoticeDetail }>(`/api/v1/public/notices/${encodeURIComponent(slug)}`, {
    validate: isNoticeDetailResponse,
    revalidateSeconds: REVALIDATE_SECONDS,
  });

  return result.ok ? { ok: true, data: result.data.data } : result;
}

export async function getNoticeSitemap(): Promise<ApiResult<NoticeSitemapEntry[]>> {
  const result = await apiGet<{ data: NoticeSitemapEntry[] }>("/api/v1/public/notices/sitemap", {
    validate: isSitemapResponse,
    revalidateSeconds: REVALIDATE_SECONDS,
  });

  return result.ok ? { ok: true, data: result.data.data } : result;
}
