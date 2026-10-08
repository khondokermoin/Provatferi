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
  /** Cache tags for this fetch, so the data can be invalidated on demand
   *  (see app/api/revalidate) instead of waiting out `revalidateSeconds`. */
  tags?: string[];
  timeoutMs?: number;
  /** Skip Next's data cache entirely and ask Laravel now (`cache: "no-store"`); `revalidateSeconds` and `tags` are
   *  ignored. For the one case a cached copy is known to be out of date (see lib/api/membership.ts) — never a default:
   *  every call costs a round trip to Laravel. */
  live?: boolean;
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
      ...(opts.live
        ? { cache: "no-store" as const }
        : { next: { revalidate: opts.revalidateSeconds, ...(opts.tags ? { tags: opts.tags } : {}) } }),
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

/**
 * Where the time went inside one forwarded POST. Only collected when the
 * caller asks (`onTiming`); never part of a normal submission's response.
 */
export interface ApiPostTiming {
  /** Rebuilding the multipart body (stripEmptyFiles) before forwarding. */
  prepMs: number;
  /** fetch() until Laravel's response HEADERS arrive: re-uploading the files,
   *  TLS/CDN hops and Laravel's whole handling of the request. */
  headersMs: number;
  /** Reading the (tiny) JSON response body. */
  bodyMs: number;
  /** Laravel's own `Server-Timing` header, verbatim, when it sent one. */
  serverTiming: string | null;
}

export interface ApiPostOptions<T> {
  validate: (json: unknown) => json is T;
  timeoutMs?: number;
  /** Extra request headers for the forwarded call (e.g. the opt-in timing flag). */
  forwardHeaders?: Record<string, string>;
  /** Receives the phase timings of this call, if the caller wants them. */
  onTiming?: (timing: ApiPostTiming) => void;
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

  const t0 = performance.now();
  const body = stripEmptyFiles(formData);
  const t1 = performance.now();

  let response: Response;
  try {
    response = await fetch(`${base}${path}`, {
      method: "POST",
      body,
      signal: controller.signal,
      headers: { Accept: "application/json", ...opts.forwardHeaders },
    });
  } catch (err) {
    const reason: ApiErrorReason = err instanceof DOMException && err.name === "AbortError" ? "timeout" : "network_error";
    logFailure(path, reason, err);
    return { ok: false, error: reason };
  } finally {
    clearTimeout(timeout);
  }
  const t2 = performance.now();

  let json: unknown;
  try {
    json = await response.json();
  } catch (err) {
    logFailure(path, "invalid_json", err);
    return { ok: false, error: "invalid_json" };
  }
  const t3 = performance.now();
  opts.onTiming?.({ prepMs: t1 - t0, headersMs: t2 - t1, bodyMs: t3 - t2, serverTiming: response.headers.get("server-timing") });

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

/** A PDF is built per request (Membership task 5: a receipt is never stored), so the wait is longer than for JSON. */
const DEFAULT_FILE_TIMEOUT_MS = 30000;

export type ApiFileResult = { ok: true; response: Response } | { ok: false; status: number | null; error: ApiErrorReason };

/**
 * Same Bearer-token GET as apiGetAuthenticated, for an answer that is a FILE (a member's receipt as a PDF): the Response
 * is handed back unread so the caller can stream it on, and a refusal carries its HTTP status so the caller can tell a
 * signed-out member (401/403) from a receipt that is not theirs or does not exist (404). Never throws, never cached.
 */
export async function apiGetAuthenticatedFile(path: string, token: string, opts: { accept: string; timeoutMs?: number }): Promise<ApiFileResult> {
  const base = baseUrl();
  if (!base) {
    console.error(`[api] LARAVEL_API_URL is not configured; skipping fetch for ${path}`);
    return { ok: false, status: null, error: "not_configured" };
  }

  const controller = new AbortController();
  const timeout = setTimeout(() => controller.abort(), opts.timeoutMs ?? DEFAULT_FILE_TIMEOUT_MS);

  try {
    const response = await fetch(`${base}${path}`, {
      signal: controller.signal,
      headers: { Accept: opts.accept, Authorization: `Bearer ${token}` },
      cache: "no-store",
    });
    if (!response.ok) {
      logFailure(path, "http_error", `HTTP ${response.status}`);
      await response.body?.cancel();
      return { ok: false, status: response.status, error: "http_error" };
    }
    return { ok: true, response };
  } catch (err) {
    const reason: ApiErrorReason = err instanceof DOMException && err.name === "AbortError" ? "timeout" : "network_error";
    logFailure(path, reason, err);
    return { ok: false, status: null, error: reason };
  } finally {
    clearTimeout(timeout);
  }
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

/**
 * Phase 2 (bilingual site) fields: admin-erp and institutional deploy through
 * separate pipelines (see this file's own header) — the exact reason
 * share_image_url below tolerates `undefined`, extended here as a named
 * helper for the many new `_en` fields added across lib/api/*.ts's guards.
 * A build can legitimately run against a not-yet-migrated backend that omits
 * the key entirely; rejecting `undefined` would break every existing
 * request the moment institutional's stricter guard ships first.
 */
export function isOptionalString(v: unknown): v is string | null | undefined {
  return v === undefined || isStringOrNull(v);
}
