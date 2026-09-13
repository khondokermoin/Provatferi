import { apiGetAuthenticated, apiPostAuthenticated, apiPostForm, apiPostFormAuthenticated, isRecord, isStringOrNull } from "./client";
import type { ApiSubmitResult } from "./client";
import type {
  ApiResult,
  MemberDashboard,
  MemberMembershipSummary,
  MemberPaymentSummary,
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
    isStringOrNull(v.membership_type)
  );
}

function isSeasonHistoryEntry(v: unknown): v is MemberSeasonHistoryEntry {
  return isRecord(v) && isStringOrNull(v.season) && isStringOrNull(v.joined_at);
}

function isPaymentSummary(v: unknown): v is MemberPaymentSummary {
  return (
    isRecord(v) &&
    typeof v.amount_received === "string" &&
    isStringOrNull(v.method) &&
    typeof v.status === "string" &&
    isStringOrNull(v.received_at)
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
    isRecord(v.library) &&
    Array.isArray(v.library.transactions)
  );
}

function isDashboardResponse(json: unknown): json is { data: MemberDashboard } {
  return isRecord(json) && isMemberDashboard(json.data);
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
