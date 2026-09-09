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
