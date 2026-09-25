"use server";

import { redirect } from "next/navigation";
import { apiPostForm, isRecord } from "@/lib/api/client";

/**
 * §14: on a validation error the submitted text is handed back so the form can
 * re-render it as defaultValue. React resets an uncontrolled form once its
 * action settles, so without this the visitor would lose everything they typed
 * the moment one field failed.
 */
export type SubmittedValues = Record<string, string>;

export type VolunteerApplicationState =
  | { status: "idle" }
  | { status: "validation"; errors: Record<string, string[]>; values: SubmittedValues; skills: string[]; consents: string[] }
  | { status: "error"; message: string; values: SubmittedValues; skills: string[]; consents: string[] };

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

function echoBack(formData: FormData): { values: SubmittedValues; skills: string[]; consents: string[] } {
  const values: SubmittedValues = {};
  for (const field of TEXT_FIELDS) {
    const value = formData.get(field);
    if (typeof value === "string") values[field] = value;
  }
  const skills = formData.getAll("skills[]").filter((s): s is string => typeof s === "string");
  const consents = CONSENT_FIELDS.filter((field) => formData.get(field) !== null);

  return { values, skills, consents };
}

/**
 * §2: the website form is the system of record. The posting slug is bound in
 * from the route (see the form's `.bind(null, slug)`) rather than carried as
 * an editable hidden input, so a submission can never be retargeted at a
 * different posting by editing the DOM.
 *
 * §8: a successful submission redirects to its own page. That makes the
 * browser issue a GET, so a refresh cannot resubmit the application or send
 * the confirmation e-mail twice.
 */
export async function submitVolunteerApplication(
  slug: string,
  _prev: VolunteerApplicationState,
  formData: FormData,
): Promise<VolunteerApplicationState> {
  const result = await apiPostForm(`/api/v1/public/recruitment/${encodeURIComponent(slug)}/applications`, formData, {
    validate: isApplicationCreatedResponse,
  });

  // Outside the failure branches on purpose: redirect() signals by throwing,
  // so it must not be wrapped in anything that swallows it.
  if (result.ok) {
    redirect(`/recruitment/${encodeURIComponent(slug)}/apply/success`);
  }

  const { values, skills, consents } = echoBack(formData);

  if (result.error === "validation") {
    return { status: "validation", errors: result.errors, values, skills, consents };
  }

  return {
    status: "error",
    message: "আবেদন জমা দেওয়া যায়নি — একটু পরে আবার চেষ্টা করুন। সমস্যা চলতে থাকলে আমাদের সঙ্গে যোগাযোগ করুন।",
    values,
    skills,
    consents,
  };
}
