import "server-only";
import type { ApiErrorReason, ApiResult } from "./types";

/**
 * Server-only fetch wrapper for admin.provatferi.org's public /api/v1/*.
 *
 * `import "server-only"` makes it a build error to import this from a Client
 * Component — the ERP base URL must never reach the browser bundle, which is
 * also why it comes from LARAVEL_API_URL (no NEXT_PUBLIC_ prefix).
 *
 * Every call returns an ApiResult, never throws, and never rejects. A page
 * calling this always gets a value to branch on immediately; the caller
 * decides how to fall back to lib/content.ts. Nothing here renders anything,
 * so no internal error detail (a stack trace, a raw exception message) can
 * leak into a response body.
 */

const DEFAULT_TIMEOUT_MS = 5000;
// A multipart photo upload legitimately takes longer than a plain JSON GET,
// especially over a slow mobile connection — apiGet's 5s default would
// abort a perfectly good upload mid-flight.
const DEFAULT_SUBMIT_TIMEOUT_MS = 20000;

function baseUrl(): string | null {
  const url = process.env.LARAVEL_API_URL;
  return url && url.length > 0 ? url.replace(/\/+$/, "") : null;
}

export interface ApiGetOptions<T> {
  /** Narrows `unknown` JSON to T. Reject anything that doesn't match — a
   *  partially-shaped response is treated the same as a failed request. */
  validate: (json: unknown) => json is T;
  /** ISR window in seconds. Callers set this per content type (see each
   *  lib/api/*.ts file) rather than relying on a single global default. */
  revalidateSeconds: number;
  timeoutMs?: number;
}

export async function apiGet<T>(path: string, opts: ApiGetOptions<T>): Promise<ApiResult<T>> {
  const base = baseUrl();
  if (!base) {
    console.error(`[api] LARAVEL_API_URL is not configured; skipping fetch for ${path}`);
    return { ok: false, error: "not_configured" };
  }

  const controller = new AbortController();
  const timeout = setTimeout(() => controller.abort(), opts.timeoutMs ?? DEFAULT_TIMEOUT_MS);

  let response: Response;
  try {
    response = await fetch(`${base}${path}`, {
      signal: controller.signal,
      headers: { Accept: "application/json" },
      next: { revalidate: opts.revalidateSeconds },
    });
  } catch (err) {
    const reason: ApiErrorReason = err instanceof DOMException && err.name === "AbortError" ? "timeout" : "network_error";
    logFailure(path, reason, err);
    return { ok: false, error: reason };
  } finally {
    clearTimeout(timeout);
  }

  if (!response.ok) {
    logFailure(path, "http_error", `HTTP ${response.status}`);
    return { ok: false, error: "http_error" };
  }

  let json: unknown;
  try {
    json = await response.json();
  } catch (err) {
    logFailure(path, "invalid_json", err);
    return { ok: false, error: "invalid_json" };
  }

  if (!opts.validate(json)) {
    logFailure(path, "invalid_shape", "response did not match the expected shape");
    return { ok: false, error: "invalid_shape" };
  }

  return { ok: true, data: json };
}

/**
 * Server-side only — this never reaches a response body or the browser
 * console. Deliberately logs just enough to diagnose an outage (path,
 * reason, one line of detail), never the raw response body, which could
 * someday carry something we don't want in application logs.
 */
function logFailure(path: string, reason: ApiErrorReason, detail: unknown): void {
  const message = detail instanceof Error ? detail.message : String(detail);
  console.error(`[api] ${reason} for ${path}: ${message}`);
}

// ---------------------------------------------------------------------------
// Write path — public form submissions (§7/§22-27/§41). Distinct from
// ApiResult<T> because a form has a case ApiGet never does: a 422 with
// field-level messages the caller must show next to the right input, not
// just "something went wrong".
// ---------------------------------------------------------------------------

export type ApiSubmitResult<T> =
  | { ok: true; data: T }
  | { ok: false; error: "validation"; errors: Record<string, string[]> }
  | { ok: false; error: ApiErrorReason };

export interface ApiPostOptions<T> {
  validate: (json: unknown) => json is T;
  timeoutMs?: number;
}

function isLaravelValidationErrorBody(json: unknown): json is { message: string; errors: Record<string, string[]> } {
  return (
    isRecord(json) &&
    typeof json.message === "string" &&
    isRecord(json.errors) &&
    Object.values(json.errors).every((v) => Array.isArray(v) && v.every((s) => typeof s === "string"))
  );
}

/**
 * An untouched `<input type="file">` submits as a PRESENT key holding an
 * empty File — never an absent key. Confirmed live 2026-09-24 exactly what
 * that empty File looks like BY THE TIME a Server Action's FormData sees it
 * (logged server-side from the real, deployed volunteer-application form):
 * `size: 0`, but `name: "undefined"` (the literal string, not an absent
 * name) and `type: "application/octet-stream"` — Next.js's own parsing of
 * the browser's incoming multipart request normalizes an empty file part to
 * this shape, not to `name: ""` as MDN's File/FormData semantics would
 * suggest. Forwarding that shape on to Laravel in the outgoing request was
 * found to sometimes reach it corrupted: not absent, not 0 bytes, but
 * reported as exceeding the 5MB `max:5120` rule — reproduced live as "CV
 * optional" silently behaving as required, since photo (always provided,
 * required on the live posting) triggers the same multipart request as the
 * untouched, optional CV field.
 *
 * A direct Node-constructed FormData sent straight to Laravel (bypassing
 * Next.js's own request parsing entirely) never showed this, confirming the
 * corruption is in that Next.js hop, not in Laravel or in this codebase's
 * request-building logic. Since a fix inside Next.js itself isn't available
 * here, every File field with size 0 is dropped before the outgoing request
 * is built — checked on size alone, not name, since name is exactly what
 * this normalization was found to mangle — so the corruption path is never
 * exercised: Laravel receives a genuinely absent field, exactly as it
 * already correctly handles.
 */
function stripEmptyFiles(formData: FormData): FormData {
  const clean = new FormData();
  for (const [key, value] of formData.entries()) {
    if (value instanceof File && value.size === 0) continue;
    clean.append(key, value);
  }
  return clean;
}

/**
 * POSTs a FormData body (so a File field works without hand-rolled
 * multipart encoding) to a public admin-erp write endpoint. Never throws.
 * `errors` on the validation branch is Laravel's own field=>messages[] map,
 * passed through as-is rather than reshaped, so a form field can be keyed
 * directly by the same name it was submitted under.
 */
export async function apiPostForm<T>(path: string, formData: FormData, opts: ApiPostOptions<T>): Promise<ApiSubmitResult<T>> {
  const base = baseUrl();
  if (!base) {
    console.error(`[api] LARAVEL_API_URL is not configured; skipping POST for ${path}`);
    return { ok: false, error: "not_configured" };
  }

  const controller = new AbortController();
  const timeout = setTimeout(() => controller.abort(), opts.timeoutMs ?? DEFAULT_SUBMIT_TIMEOUT_MS);

  let response: Response;
  try {
    response = await fetch(`${base}${path}`, {
      method: "POST",
      body: stripEmptyFiles(formData),
      signal: controller.signal,
      headers: { Accept: "application/json" },
    });
  } catch (err) {
    const reason: ApiErrorReason = err instanceof DOMException && err.name === "AbortError" ? "timeout" : "network_error";
    logFailure(path, reason, err);
    return { ok: false, error: reason };
  } finally {
    clearTimeout(timeout);
  }

  let json: unknown;
  try {
    json = await response.json();
  } catch (err) {
    logFailure(path, "invalid_json", err);
    return { ok: false, error: "invalid_json" };
  }

  if (response.status === 422 && isLaravelValidationErrorBody(json)) {
    return { ok: false, error: "validation", errors: json.errors };
  }

  if (!response.ok) {
    logFailure(path, "http_error", `HTTP ${response.status}`);
    return { ok: false, error: "http_error" };
  }

  if (!opts.validate(json)) {
    logFailure(path, "invalid_shape", "response did not match the expected shape");
    return { ok: false, error: "invalid_shape" };
  }

  return { ok: true, data: json };
}

// ---------------------------------------------------------------------------
// Authenticated path — the member portal (§12). The Sanctum token lives only
// in this Next.js app's own HttpOnly cookie; every call here carries it as
// a Bearer header to admin-erp, server-side only. Never cached — this is
// one member's own session-bound data, and Next.js's fetch cache has no
// concept of "per-viewer", so caching it at all would risk serving one
// member's dashboard to another.
// ---------------------------------------------------------------------------

export interface ApiGetAuthenticatedOptions<T> {
  validate: (json: unknown) => json is T;
  timeoutMs?: number;
}

export async function apiGetAuthenticated<T>(path: string, token: string, opts: ApiGetAuthenticatedOptions<T>): Promise<ApiResult<T>> {
  const base = baseUrl();
  if (!base) {
    console.error(`[api] LARAVEL_API_URL is not configured; skipping fetch for ${path}`);
    return { ok: false, error: "not_configured" };
  }

  const controller = new AbortController();
  const timeout = setTimeout(() => controller.abort(), opts.timeoutMs ?? DEFAULT_TIMEOUT_MS);

  let response: Response;
  try {
    response = await fetch(`${base}${path}`, {
      signal: controller.signal,
      headers: { Accept: "application/json", Authorization: `Bearer ${token}` },
      cache: "no-store",
    });
  } catch (err) {
    const reason: ApiErrorReason = err instanceof DOMException && err.name === "AbortError" ? "timeout" : "network_error";
    logFailure(path, reason, err);
    return { ok: false, error: reason };
  } finally {
    clearTimeout(timeout);
  }

  if (!response.ok) {
    logFailure(path, "http_error", `HTTP ${response.status}`);
    return { ok: false, error: "http_error" };
  }

  let json: unknown;
  try {
    json = await response.json();
  } catch (err) {
    logFailure(path, "invalid_json", err);
    return { ok: false, error: "invalid_json" };
  }

  if (!opts.validate(json)) {
    logFailure(path, "invalid_shape", "response did not match the expected shape");
    return { ok: false, error: "invalid_shape" };
  }

  return { ok: true, data: json };
}

export interface ApiPostAuthenticatedOptions<T> {
  validate: (json: unknown) => json is T;
  timeoutMs?: number;
}

/** Same Bearer-token pattern as apiGetAuthenticated, for a member-session POST with no body (e.g. logout). */
export async function apiPostAuthenticated<T>(path: string, token: string, opts: ApiPostAuthenticatedOptions<T>): Promise<ApiResult<T>> {
  const base = baseUrl();
  if (!base) {
    console.error(`[api] LARAVEL_API_URL is not configured; skipping POST for ${path}`);
    return { ok: false, error: "not_configured" };
  }

  const controller = new AbortController();
  const timeout = setTimeout(() => controller.abort(), opts.timeoutMs ?? DEFAULT_TIMEOUT_MS);

  let response: Response;
  try {
    response = await fetch(`${base}${path}`, {
      method: "POST",
      signal: controller.signal,
      headers: { Accept: "application/json", Authorization: `Bearer ${token}` },
      cache: "no-store",
    });
  } catch (err) {
    const reason: ApiErrorReason = err instanceof DOMException && err.name === "AbortError" ? "timeout" : "network_error";
    logFailure(path, reason, err);
    return { ok: false, error: reason };
  } finally {
    clearTimeout(timeout);
  }

  if (!response.ok) {
    logFailure(path, "http_error", `HTTP ${response.status}`);
    return { ok: false, error: "http_error" };
  }

  let json: unknown;
  try {
    json = await response.json();
  } catch (err) {
    logFailure(path, "invalid_json", err);
    return { ok: false, error: "invalid_json" };
  }

  if (!opts.validate(json)) {
    logFailure(path, "invalid_shape", "response did not match the expected shape");
    return { ok: false, error: "invalid_shape" };
  }

  return { ok: true, data: json };
}

/**
 * Bearer-authenticated counterpart to apiPostForm — a member submitting
 * their own profile edit (with a photo) needs both the auth header AND the
 * field-level validation-error branch, which plain apiPostAuthenticated
 * (body-less, used only for logout) doesn't return.
 */
export async function apiPostFormAuthenticated<T>(path: string, token: string, formData: FormData, opts: ApiPostOptions<T>): Promise<ApiSubmitResult<T>> {
  const base = baseUrl();
  if (!base) {
    console.error(`[api] LARAVEL_API_URL is not configured; skipping POST for ${path}`);
    return { ok: false, error: "not_configured" };
  }

  const controller = new AbortController();
  const timeout = setTimeout(() => controller.abort(), opts.timeoutMs ?? DEFAULT_SUBMIT_TIMEOUT_MS);

  let response: Response;
  try {
    response = await fetch(`${base}${path}`, {
      method: "POST",
      body: stripEmptyFiles(formData), // see stripEmptyFiles' docblock above apiPostForm
      signal: controller.signal,
      headers: { Accept: "application/json", Authorization: `Bearer ${token}` },
      cache: "no-store",
    });
  } catch (err) {
    const reason: ApiErrorReason = err instanceof DOMException && err.name === "AbortError" ? "timeout" : "network_error";
    logFailure(path, reason, err);
    return { ok: false, error: reason };
  } finally {
    clearTimeout(timeout);
  }

  let json: unknown;
  try {
    json = await response.json();
  } catch (err) {
    logFailure(path, "invalid_json", err);
    return { ok: false, error: "invalid_json" };
  }

  if (response.status === 422 && isLaravelValidationErrorBody(json)) {
    return { ok: false, error: "validation", errors: json.errors };
  }

  if (!response.ok) {
    logFailure(path, "http_error", `HTTP ${response.status}`);
    return { ok: false, error: "http_error" };
  }

  if (!opts.validate(json)) {
    logFailure(path, "invalid_shape", "response did not match the expected shape");
    return { ok: false, error: "invalid_shape" };
  }

  return { ok: true, data: json };
}

// ---------------------------------------------------------------------------
// Small runtime guards shared across lib/api/*.ts. Hand-rolled rather than a
// schema library — no validation dependency existed in this project, and the
// shapes here are small and stable enough not to need one.
// ---------------------------------------------------------------------------

export function isRecord(v: unknown): v is Record<string, unknown> {
  return typeof v === "object" && v !== null && !Array.isArray(v);
}

export function isStringOrNull(v: unknown): v is string | null {
  return v === null || typeof v === "string";
}

export function isNumberOrNull(v: unknown): v is number | null {
  return v === null || typeof v === "number";
}
