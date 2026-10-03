import "server-only";
import { forwardApplication, GENERIC_SUBMIT_ERROR, type VolunteerApplicationState } from "./volunteer-application";

/**
 * The volunteer application form's JavaScript submit endpoint (app/api/recruitment/[slug]/apply/route.ts is
 * a thin wrapper around this): a plain multipart POST carrying the photo, the CV and the text.
 *
 * Why this is a plain route and not the form's Server Action: a Server Action request carries a
 * `Next-Action` header, and Cloudflare's managed WAF rule "React - Leaking Server Functions"
 * (CVE-2025-55183) blocks any request that has that header and whose first 1 MiB contains the bytes
 * `"$F` or `'$F`. A JPEG or PDF is binary: those bytes turn up by chance about once per 8 MB, so
 * roughly one photo upload in nine (1 - e^(-2 MiB / 16 MiB) = 11.8%) was refused with a bare 403 —
 * deterministically for that file: the same photo failed every retry — before the request ever reached
 * this app. Measured on production with probes to a non-existent action id (nothing ran): random 1 MiB
 * bodies were refused 4 times in 40; a marker planted in a body was seen up to byte 1,048,576 and not
 * beyond; the same bodies without the header were never refused. This request has no such header, so
 * the rule does not apply to it. (The Server Action stays for the no-JavaScript path: a native form
 * post carries no such header either.)
 *
 * Same job as the Server Action: forward to Laravel, answer with the form's state. Always JSON, and 200 for
 * every answer the form can show; an error page from an edge or proxy layer is not JSON, and the form
 * treats that as a generic failure.
 */

/** Laravel's own ceilings are 5 MB for the photo and 5 MB for the CV; the rest of the form is text. */
export const MAX_BODY_BYTES = 12 * 1024 * 1024;

const refusal = (): VolunteerApplicationState => ({ status: "error", message: GENERIC_SUBMIT_ERROR, values: {}, skills: [], consents: [] });

function reply(state: VolunteerApplicationState, init: { status?: number; timing?: string | null } = {}): Response {
  const headers = new Headers({ "Cache-Control": "no-store" });
  if (init.timing) {
    // Same cookie the Server Action sets for the opt-in measurement (scripts/submit-qa.mjs reads it).
    headers.append("Set-Cookie", `pf_timing=${encodeURIComponent(init.timing)}; Max-Age=60; Path=/; SameSite=Lax; Secure`);
  }
  return Response.json(state, { status: init.status ?? 200, headers });
}

/** The body, or null when it is larger than a legitimate submission can be — never buffer more than that. */
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

export async function handleApplicationPost(request: Request, slug: string): Promise<Response> {
  // A browser says when a request was started by another site's page; this form is only ever posted from its own.
  if (request.headers.get("sec-fetch-site") === "cross-site") {
    return reply(refusal(), { status: 403 });
  }

  const contentType = request.headers.get("content-type") ?? "";
  if (!contentType.toLowerCase().startsWith("multipart/form-data")) {
    return reply(refusal(), { status: 415 });
  }

  const body = await readBodyWithin(request, MAX_BODY_BYTES);
  if (body === null) {
    return reply(refusal(), { status: 413 });
  }

  let formData: FormData;
  try {
    formData = await new Response(body as BodyInit, { headers: { "content-type": contentType } }).formData();
  } catch {
    return reply(refusal(), { status: 400 });
  }

  const { state, timing } = await forwardApplication(slug, formData, request.headers.get("x-pf-timing") === "1");

  return reply(state, { timing });
}
