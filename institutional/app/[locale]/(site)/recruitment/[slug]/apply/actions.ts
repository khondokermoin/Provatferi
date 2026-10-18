"use server";

import { cookies, headers } from "next/headers";
import { redirect } from "next/navigation";
import { forwardApplication, type VolunteerApplicationState } from "@/lib/volunteer-application";
import { setSuccessCookie } from "@/lib/volunteer-success";

export type { SubmittedValues, VolunteerApplicationState } from "@/lib/volunteer-application";

/**
 * The form's no-JavaScript path (and a click made before the page has hydrated): a native post that
 * ends in a redirect to the confirmation page.
 *
 * With JavaScript, the form does NOT call this — it posts to app/api/recruitment/[slug]/apply, because a
 * Server Action request carries a `Next-Action` header and Cloudflare's managed WAF rule "React -
 * Leaking Server Functions" (CVE-2025-55183) refuses any such request whose first 1 MiB contains the
 * three bytes `"$F` (or `'$F`). A photo or PDF is binary, so it contains them by chance about once per
 * 8 MB: roughly one photo upload in nine was refused with a bare 403 — always that same photo, so a
 * retry could not help. (A request without that header is not subject to the rule.)
 *
 * §2: the posting slug is bound in from the route (see the form's `.bind(null, slug)`) rather than
 * carried as an editable hidden input.
 *
 * §8: a successful submission redirects to its own page. That makes the browser issue a GET, so a
 * refresh cannot resubmit the application or send the confirmation e-mail twice.
 *
 * The confirmation page shows the Messenger group only to a verified applicant, so a success also sets the proof cookie
 * (lib/volunteer-success.ts) before the redirect. It is the cookie for the address redirected to below (the unprefixed,
 * Bangla one): Next's cookie store keeps one cookie per name, so it cannot carry the English path as well, as the
 * JavaScript path's route handler does.
 */
export async function submitVolunteerApplication(
  slug: string,
  _prev: VolunteerApplicationState,
  formData: FormData,
): Promise<VolunteerApplicationState> {
  // Opt-in measurement (scripts/submit-qa.mjs sets this header; a visitor never does). A successful
  // submission ends in redirect(), which cannot carry a return value, so the phase durations travel in a
  // short-lived cookie instead.
  const wantTiming = (await headers()).get("x-pf-timing") === "1";

  const { state, timing, proof } = await forwardApplication(slug, formData, wantTiming);

  const store = await cookies();
  if (timing !== null) {
    store.set("pf_timing", encodeURIComponent(timing), { maxAge: 60, path: "/", sameSite: "lax", secure: true, httpOnly: false });
  }
  if (state.status === "success" && proof) setSuccessCookie(store, slug, proof);

  // Outside any try/catch on purpose: redirect() signals by throwing.
  if (state.status === "success") redirect(`/recruitment/${encodeURIComponent(slug)}/apply/success`);

  return state;
}
