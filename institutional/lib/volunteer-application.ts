import "server-only";
import { apiPostForm, isRecord, type ApiPostTiming } from "./api/client";

/**
 * Server-side half of the volunteer application submit, shared by its two entry points:
 *
 *  - app/api/recruitment/[slug]/apply/route.ts — what the form's own JavaScript posts to (a plain
 *    multipart fetch). See that file for why the form does not go through a Server Action.
 *  - the Server Action in app/[locale]/(site)/recruitment/[slug]/apply/actions.ts — the form's
 *    no-JavaScript / not-yet-hydrated path (a native post, answered with a redirect).
 *
 * Both forward the same FormData to Laravel and turn Laravel's answer into the same state.
 */

/**
 * §14: on a validation error the submitted text is handed back so the form can
 * re-render it as defaultValue (a no-JavaScript post resets the form; the JavaScript path keeps the
 * DOM as typed, so this matters most there).
 */
export type SubmittedValues = Record<string, string>;

export type VolunteerApplicationState =
  | { status: "idle" }
  | { status: "success" }
  | { status: "validation"; errors: Record<string, string[]>; values: SubmittedValues; skills: string[]; consents: string[] }
  | { status: "error"; message: string; values: SubmittedValues; skills: string[]; consents: string[] };

export const GENERIC_SUBMIT_ERROR =
  "আবেদন জমা দেওয়া যায়নি — একটু পরে আবার চেষ্টা করুন। সমস্যা চলতে থাকলে আমাদের সঙ্গে যোগাযোগ করুন।";

function isApplicationCreatedResponse(v: unknown): v is { data: { application_no: string } } {
  return isRecord(v) && isRecord(v.data) && typeof v.data.application_no === "string";
}

/** Everything except the file inputs, which a browser will not let us re-populate. */
const TEXT_FIELDS = [
  "applicant_name",
  "applicant_phone",
  "applicant_email",
  "district",
  "current_location",
  "profession",
  "experience",
  "other_skills",
  "contribution",
  "availability",
  "preferred_contact",
  "linkedin_url",
  "facebook_url",
  "portfolio_url",
] as const;

/** The consent boxes are echoed too — re-ticking three declarations after a
 *  typo in one field is exactly the kind of busywork §14 rules out. */
const CONSENT_FIELDS = ["accuracy_declaration", "privacy_consent", "contact_consent"] as const;

export function echoBack(formData: FormData): { values: SubmittedValues; skills: string[]; consents: string[] } {
  const values: SubmittedValues = {};
  for (const field of TEXT_FIELDS) {
    const value = formData.get(field);
    if (typeof value === "string") values[field] = value;
  }
  const skills = formData.getAll("skills[]").filter((s): s is string => typeof s === "string");
  const consents = CONSENT_FIELDS.filter((field) => formData.get(field) !== null);

  return { values, skills, consents };
}

const round = (ms: number | undefined) => (ms === undefined ? null : Math.round(ms * 10) / 10);

export interface ForwardedApplication {
  state: VolunteerApplicationState;
  /**
   * Phase durations as JSON, only when the caller opted in (`x-pf-timing: 1`, which scripts/submit-qa.mjs
   * sends and a visitor never does): this layer's own time, the multipart rebuild, the Laravel hop and
   * Laravel's own Server-Timing. Durations only, never any of the submitted data.
   */
  timing: string | null;
}

/**
 * §2: the website form is the system of record. The posting slug comes from the route, never from an
 * editable field, so a submission can never be retargeted at a different posting by editing the DOM.
 */
export async function forwardApplication(slug: string, formData: FormData, wantTiming: boolean): Promise<ForwardedApplication> {
  const startedAt = performance.now();
  let forwarded: ApiPostTiming | undefined;

  const result = await apiPostForm(`/api/v1/public/recruitment/${encodeURIComponent(slug)}/applications`, formData, {
    validate: isApplicationCreatedResponse,
    ...(wantTiming ? { forwardHeaders: { "X-Pf-Timing": "1" }, onTiming: (t: ApiPostTiming) => { forwarded = t; } } : {}),
  });

  const timing = wantTiming
    ? JSON.stringify({
        actionMs: round(performance.now() - startedAt),
        prepMs: round(forwarded?.prepMs),
        laravelHeadersMs: round(forwarded?.headersMs),
        laravelBodyMs: round(forwarded?.bodyMs),
        laravel: forwarded?.serverTiming ?? null,
      })
    : null;

  if (result.ok) return { state: { status: "success" }, timing };

  const { values, skills, consents } = echoBack(formData);
  if (result.error === "validation") {
    return { state: { status: "validation", errors: result.errors, values, skills, consents }, timing };
  }
  return { state: { status: "error", message: GENERIC_SUBMIT_ERROR, values, skills, consents }, timing };
}
