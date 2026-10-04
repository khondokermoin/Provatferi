import "server-only";
import type { ApiSubmitResult } from "./api/client";
import { PHOTO_TOO_LARGE, type FormState } from "./form-state";

/**
 * The guard every file-upload route handler goes through (the route.ts files under app/api). A file never travels through a
 * Server Action: Cloudflare's managed WAF rule "React - Leaking Server Functions" (CVE-2025-55183) refuses, with
 * a bare 403 from the edge, any request that has a `Next-Action` header and whose first 1 MiB contains the bytes
 * `"$F` or `'$F` — which a photo or PDF holds by chance about once per 8 MB (see volunteer-application-post.ts
 * for the measurements). A plain multipart POST to a route has no such header and is never refused by it.
 *
 * What this adds to "just a route" is everything a Server Action used to do for free or that a public upload
 * endpoint needs anyway:
 *  - same-origin only (a Server Action's own Origin/Host check is gone, so it is redone here — see isSameOrigin);
 *  - multipart/form-data only, and a hard ceiling on the body, counted while reading rather than trusted from
 *    the header, so nothing larger than a legitimate submission is ever buffered;
 *  - no open proxy: each route names ONE upstream path itself; nothing from the request chooses where it goes;
 *  - Laravel stays the only judge of the content — nothing here validates or weakens its rules.
 * Empty-file normalisation (an untouched <input type="file"> arrives as a zero-byte File that must be left out,
 * not forwarded) is owned by the shared API client (stripEmptyFiles in lib/api/client.ts), which every upstream
 * call below goes through — it is deliberately NOT repeated here or in a form.
 */

/** Laravel caps a photo at 5 MB (max:5120); the form's text is a few KB; the rest is multipart framing. */
export const MAX_PHOTO_FORM_BYTES = 6 * 1024 * 1024;

export type UploadRefusal = "cross-site" | "not-multipart" | "too-large" | "unreadable";

const REFUSAL_STATUS: Record<UploadRefusal, number> = { "cross-site": 403, "not-multipart": 415, "too-large": 413, unreadable: 400 };

/**
 * Same-origin, the way a browser can prove it. A Server Action checked the Origin header against the host; this does
 * the same and adds Fetch Metadata:
 *  - `Sec-Fetch-Site`, when the browser sends it, must be `same-origin` (a form on another site, or a sibling
 *    subdomain, says cross-site / same-site);
 *  - `Origin`, when present (a browser always sends it on a POST), must be this site's own host — the host the
 *    visitor used (`x-forwarded-host`, else `host`) or the one in the request URL;
 *  - a request carrying NEITHER is refused: every browser's fetch() sends at least one, so only a script posting
 *    from nowhere lacks both, and a public form has no use for those.
 * The member session cookie is SameSite=Lax, which already keeps a cross-site POST from carrying it; this is the
 * second wall, and the only one for the endpoints that need no login.
 */
export function isSameOrigin(request: Request): boolean {
  const fetchSite = request.headers.get("sec-fetch-site");
  if (fetchSite !== null && fetchSite !== "same-origin") return false;

  const origin = request.headers.get("origin");
  if (origin === null) return fetchSite === "same-origin";

  let originHost: string;
  try {
    originHost = new URL(origin).host;
  } catch {
    return false; // "null" (a sandboxed or privacy-stripped origin) and anything unparseable
  }
  const ownHosts = new Set<string>();
  const forwarded = request.headers.get("x-forwarded-host")?.split(",")[0]?.trim();
  if (forwarded) ownHosts.add(forwarded);
  const host = request.headers.get("host");
  if (host) ownHosts.add(host);
  try {
    ownHosts.add(new URL(request.url).host);
  } catch {
    // a relative request.url never happens under Next; the other two hosts still stand
  }
  return ownHosts.has(originHost);
}

export function jsonReply(body: unknown, init: { status?: number; headers?: HeadersInit } = {}): Response {
  const headers = new Headers(init.headers);
  headers.set("Cache-Control", "no-store");
  return Response.json(body, { status: init.status ?? 200, headers });
}

/** The body, or null when it is larger than `limit` — never buffers more than that. */
async function readBodyWithin(request: Request, limit: number): Promise<Uint8Array | null> {
  const declared = Number(request.headers.get("content-length"));
  if (Number.isFinite(declared) && declared > limit) return null;
  if (!request.body) return new Uint8Array();

  const reader = request.body.getReader();
  const chunks: Uint8Array[] = [];
  let total = 0;
  for (;;) {
    const { done, value } = await reader.read();
    if (done) break;
    total += value.byteLength;
    if (total > limit) {
      await reader.cancel().catch(() => undefined);
      return null;
    }
    chunks.push(value);
  }
  return Buffer.concat(chunks);
}

export type UploadForm = { ok: true; formData: FormData } | { ok: false; refusal: UploadRefusal };

/** Same-origin, multipart, within the ceiling, parseable — or the reason it is not. */
export async function readUploadForm(request: Request, maxBytes: number): Promise<UploadForm> {
  if (!isSameOrigin(request)) return { ok: false, refusal: "cross-site" };

  const contentType = request.headers.get("content-type") ?? "";
  if (!contentType.toLowerCase().startsWith("multipart/form-data")) return { ok: false, refusal: "not-multipart" };

  const body = await readBodyWithin(request, maxBytes);
  if (body === null) return { ok: false, refusal: "too-large" };

  try {
    return { ok: true, formData: await new Response(body as BodyInit, { headers: { "content-type": contentType } }).formData() };
  } catch {
    return { ok: false, refusal: "unreadable" };
  }
}

/**
 * The whole route in one place: guard, then the route's own upstream call, then the answer as JSON — 200 for every
 * answer the form can show (an error page from an edge or proxy is not JSON, and the form treats that as a generic
 * failure), the refusal's own status when the guard said no.
 */
export async function handleUpload<S>(
  request: Request,
  options: {
    maxBytes: number;
    /** The state the form shows for a request the guard refused. */
    refuse: (why: UploadRefusal) => S;
    /**
     * For a route behind a login: runs after the same-origin check and BEFORE the body is read, so a request with
     * no session never gets a 6 MB upload buffered on its behalf. Return the answer to give, or null to carry on.
     */
    authorize?: () => { state: S; status: number } | null;
    /** The route's upstream call (it never throws) and the headers to add to the answer, if any. */
    run: (formData: FormData) => Promise<{ state: S; headers?: HeadersInit }>;
  },
): Promise<Response> {
  if (!isSameOrigin(request)) return jsonReply(options.refuse("cross-site"), { status: REFUSAL_STATUS["cross-site"] });

  const denied = options.authorize?.() ?? null;
  if (denied) return jsonReply(denied.state, { status: denied.status });

  const form = await readUploadForm(request, options.maxBytes);
  if (!form.ok) return jsonReply(options.refuse(form.refusal), { status: REFUSAL_STATUS[form.refusal] });

  try {
    const { state, headers } = await options.run(form.formData);
    return jsonReply(state, { headers });
  } catch {
    return jsonReply(options.refuse("unreadable"), { status: 500 });
  }
}

/** The two answers that mean "this did not go through" — what a refused request can show. */
export type FailureState = Extract<FormState, { status: "validation" | "error" }>;

/** What a refused request shows on the plain photo forms: the size problem as a field error, anything else generic. */
export function photoFormRefusal(message: string): (why: UploadRefusal) => FailureState {
  return (why) => (why === "too-large" ? { status: "validation", errors: PHOTO_TOO_LARGE } : { status: "error", message });
}

/** Laravel's answer to a form post → the state the form renders. */
export function stateFromResult<T, Extra extends object = object>(
  result: ApiSubmitResult<T>,
  options: { success: (data: T) => Extra; failure: string },
): FormState<Extra> {
  if (result.ok) return { status: "success", ...options.success(result.data) } as FormState<Extra>;
  if (result.error === "validation") return { status: "validation", errors: result.errors };
  return { status: "error", message: options.failure };
}
