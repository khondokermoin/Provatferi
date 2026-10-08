import { apiGetAuthenticated, apiGetAuthenticatedFile, apiPostAuthenticated, apiPostForm, apiPostFormAuthenticated, isRecord, isStringOrNull } from "./client";
import type { ApiFileResult, ApiSubmitResult } from "./client";
import type {
  ApiResult,
  MemberDashboard,
  MemberMembershipSummary,
  MemberMonthlyContribution,
  MemberMonthlyDue,
  MemberPaymentSummary,
  MemberReceiptSummary,
  MemberProfile,
  MemberProfileState,
  MemberProfileVersionView,
  MemberSeasonHistoryEntry,
} from "./types";

function isLoginResponse(v: unknown): v is { data: { token: string } } {
  return isRecord(v) && isRecord(v.data) && typeof v.data.token === "string";
}

export async function memberLogin(email: string, password: string): Promise<ApiSubmitResult<{ token: string }>> {
  const form = new FormData();
  form.set("email", email);
  form.set("password", password);

  const result = await apiPostForm("/api/v1/member/auth/login", form, { validate: isLoginResponse });
  return result.ok ? { ok: true, data: result.data.data } : result;
}

function isMessageResponse(v: unknown): v is { message: string } {
  return isRecord(v) && typeof v.message === "string";
}

export async function memberRequestPasswordReset(email: string): Promise<ApiSubmitResult<{ message: string }>> {
  const form = new FormData();
  form.set("email", email);

  return apiPostForm("/api/v1/member/auth/password/email", form, { validate: isMessageResponse });
}

export async function memberResetPassword(
  token: string,
  email: string,
  password: string,
  passwordConfirmation: string,
): Promise<ApiSubmitResult<{ message: string }>> {
  const form = new FormData();
  form.set("token", token);
  form.set("email", email);
  form.set("password", password);
  form.set("password_confirmation", passwordConfirmation);

  return apiPostForm("/api/v1/member/auth/password/reset", form, { validate: isMessageResponse });
}

/** Best-effort — the caller clears its own cookie regardless of this result. */
export async function memberLogout(token: string): Promise<ApiResult<{ message: string }>> {
  return apiPostAuthenticated("/api/v1/member/auth/logout", token, { validate: isMessageResponse });
}

function isMemberProfile(v: unknown): v is MemberProfile {
  return (
    isRecord(v) &&
    isStringOrNull(v.member_code) &&
    typeof v.name === "string" &&
    typeof v.email === "string" &&
    isStringOrNull(v.phone) &&
    typeof v.status === "string" &&
    typeof v.public_profile_enabled === "boolean" &&
    typeof v.public_profile_approved === "boolean" &&
    isStringOrNull(v.public_slug)
  );
}

function isMembershipSummary(v: unknown): v is MemberMembershipSummary {
  return (
    isRecord(v) &&
    typeof v.member_code === "string" &&
    typeof v.status === "string" &&
    isStringOrNull(v.start_date) &&
    isStringOrNull(v.expiry_date) &&
    isStringOrNull(v.membership_type) &&
    // 2026-10-06 additions: optional, so this site works against an ERP build that does not send them yet.
    (v.registration_fee === undefined || isStringOrNull(v.registration_fee)) &&
    (v.payment_state === undefined || typeof v.payment_state === "string") &&
    // 2026-10-08 (monthly dues): optional for the same reason.
    (v.monthly === undefined || isMonthlyContribution(v.monthly))
  );
}

function isMonthlyDue(v: unknown): v is MemberMonthlyDue {
  return (
    isRecord(v) &&
    typeof v.period === "string" &&
    typeof v.amount === "string" &&
    typeof v.paid === "string" &&
    typeof v.waived === "string" &&
    typeof v.outstanding === "string" &&
    typeof v.state === "string"
  );
}

function isMonthlyContribution(v: unknown): v is MemberMonthlyContribution {
  return (
    isRecord(v) &&
    typeof v.current_period === "string" &&
    isStringOrNull(v.current_amount) &&
    typeof v.required === "boolean" &&
    typeof v.month_state === "string" &&
    typeof v.outstanding === "string" &&
    typeof v.overdue_count === "number" &&
    typeof v.credit === "string" &&
    Array.isArray(v.recent) &&
    v.recent.every(isMonthlyDue)
  );
}

function isSeasonHistoryEntry(v: unknown): v is MemberSeasonHistoryEntry {
  return isRecord(v) && isStringOrNull(v.season) && isStringOrNull(v.joined_at);
}

function isPaymentSummary(v: unknown): v is MemberPaymentSummary {
  return (
    isRecord(v) &&
    // A waiver records no amount received (null). Requiring a string here used to fail the whole dashboard for a
    // member whose fee was waived — they were sent back to the login page on every visit.
    isStringOrNull(v.amount_received) &&
    isStringOrNull(v.method) &&
    typeof v.status === "string" &&
    isStringOrNull(v.received_at)
  );
}

function isReceiptSummary(v: unknown): v is MemberReceiptSummary {
  return (
    isRecord(v) &&
    typeof v.receipt_no === "string" &&
    RECEIPT_NUMBER_PATTERN.test(v.receipt_no) &&
    typeof v.purpose === "string" &&
    typeof v.amount === "string" &&
    typeof v.credit === "string" &&
    typeof v.payment_date === "string" &&
    Array.isArray(v.periods) &&
    v.periods.every((p) => typeof p === "string")
  );
}

function isMemberDashboard(v: unknown): v is MemberDashboard {
  return (
    isRecord(v) &&
    isMemberProfile(v.profile) &&
    Array.isArray(v.memberships) &&
    v.memberships.every(isMembershipSummary) &&
    Array.isArray(v.season_history) &&
    v.season_history.every(isSeasonHistoryEntry) &&
    Array.isArray(v.payments) &&
    v.payments.every(isPaymentSummary) &&
    // 2026-10-08 (official receipts): optional, so this site works against an ERP build that does not send them yet.
    (v.receipts === undefined || (Array.isArray(v.receipts) && v.receipts.every(isReceiptSummary))) &&
    isRecord(v.library) &&
    Array.isArray(v.library.transactions)
  );
}

function isDashboardResponse(json: unknown): json is { data: MemberDashboard } {
  return isRecord(json) && isMemberDashboard(json.data);
}

/** A receipt number as admin-erp issues it: PLCC-RCT-{year}-{at least six digits}. Nothing else is ever sent upstream. */
export const RECEIPT_NUMBER_PATTERN = /^PLCC-RCT-\d{4}-\d{6,}$/;

/**
 * The signed-in member's own receipt as a PDF, unread (see apiGetAuthenticatedFile). Laravel looks it up only among
 * that member's receipts: someone else's receipt number is the same 404 as one that does not exist.
 */
export function getMemberReceiptPdf(token: string, receiptNo: string, options: { lang: "bn" | "en"; disposition: "inline" | "attachment" }): Promise<ApiFileResult> {
  return apiGetAuthenticatedFile(`/api/v1/member/receipts/${encodeURIComponent(receiptNo)}/pdf?lang=${options.lang}&disposition=${options.disposition}`, token, { accept: "application/pdf" });
}

export async function getMemberDashboard(token: string): Promise<ApiResult<MemberDashboard>> {
  const result = await apiGetAuthenticated<{ data: MemberDashboard }>("/api/v1/member/me", token, {
    validate: isDashboardResponse,
  });

  return result.ok ? { ok: true, data: result.data.data } : result;
}

function isProfileVersionView(v: unknown): v is MemberProfileVersionView {
  return (
    isRecord(v) &&
    isStringOrNull(v.bio) &&
    isStringOrNull(v.profession) &&
    isStringOrNull(v.facebook_url) &&
    isStringOrNull(v.linkedin_url) &&
    isStringOrNull(v.website_url) &&
    isStringOrNull(v.photo_url) &&
    isStringOrNull(v.submitted_at)
  );
}

function isProfileState(v: unknown): v is MemberProfileState {
  return (
    isRecord(v) &&
    typeof v.public_profile_enabled === "boolean" &&
    typeof v.public_profile_approved === "boolean" &&
    isStringOrNull(v.public_slug) &&
    (v.live === null || isProfileVersionView(v.live)) &&
    (v.pending === null || isProfileVersionView(v.pending))
  );
}

function isProfileStateResponse(json: unknown): json is { data: MemberProfileState } {
  return isRecord(json) && isProfileState(json.data);
}

export async function getMemberProfileState(token: string): Promise<ApiResult<MemberProfileState>> {
  const result = await apiGetAuthenticated<{ data: MemberProfileState }>("/api/v1/member/profile", token, {
    validate: isProfileStateResponse,
  });

  return result.ok ? { ok: true, data: result.data.data } : result;
}

export async function updateMemberProfile(token: string, formData: FormData): Promise<ApiSubmitResult<{ message: string }>> {
  return apiPostFormAuthenticated("/api/v1/member/profile", token, formData, { validate: isMessageResponse });
}
