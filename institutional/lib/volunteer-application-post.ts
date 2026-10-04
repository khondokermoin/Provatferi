import "server-only";
import { forwardApplication, GENERIC_SUBMIT_ERROR, type VolunteerApplicationState } from "./volunteer-application";
import { handleUpload } from "./upload-route";

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
 * The guard (same-origin, multipart only, a ceiling on the body, parse) is shared with every other upload
 * route: lib/upload-route.ts. What is specific to this form is below: its ceiling, its refusal, and its
 * upstream call (forwardApplication) with the opt-in timing cookie.
 */

/** Laravel's own ceilings are 5 MB for the photo and 5 MB for the CV; the rest of the form is text. */
export const MAX_BODY_BYTES = 12 * 1024 * 1024;

const refusal = (): VolunteerApplicationState => ({ status: "error", message: GENERIC_SUBMIT_ERROR, values: {}, skills: [], consents: [] });

export function handleApplicationPost(request: Request, slug: string): Promise<Response> {
  return handleUpload<VolunteerApplicationState>(request, {
    maxBytes: MAX_BODY_BYTES,
    refuse: refusal,
    run: async (formData) => {
      const { state, timing } = await forwardApplication(slug, formData, request.headers.get("x-pf-timing") === "1");
      // Same cookie the Server Action sets for the opt-in measurement (scripts/submit-qa.mjs reads it).
      return { state, headers: timing ? { "Set-Cookie": `pf_timing=${encodeURIComponent(timing)}; Max-Age=60; Path=/; SameSite=Lax; Secure` } : undefined };
    },
  });
}
