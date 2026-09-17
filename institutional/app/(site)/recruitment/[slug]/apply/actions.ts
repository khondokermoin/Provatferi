"use server";

import { apiPostForm, isRecord } from "@/lib/api/client";

export type VolunteerApplicationState =
  | { status: "idle" }
  | { status: "success"; applicationNo: string }
  | { status: "validation"; errors: Record<string, string[]> }
  | { status: "error"; message: string };

function isApplicationCreatedResponse(v: unknown): v is { data: { application_no: string } } {
  return isRecord(v) && isRecord(v.data) && typeof v.data.application_no === "string";
}

/**
 * §2: the website form is the system of record. The posting slug is bound in
 * from the route (see the form's `.bind(null, slug)`) rather than carried as
 * an editable hidden input, so a submission can never be retargeted at a
 * different posting by editing the DOM.
 */
export async function submitVolunteerApplication(
  slug: string,
  _prev: VolunteerApplicationState,
  formData: FormData,
): Promise<VolunteerApplicationState> {
  const result = await apiPostForm(`/api/v1/public/recruitment/${encodeURIComponent(slug)}/applications`, formData, {
    validate: isApplicationCreatedResponse,
  });

  if (result.ok) return { status: "success", applicationNo: result.data.data.application_no };
  if (result.error === "validation") return { status: "validation", errors: result.errors };

  return {
    status: "error",
    message: "আবেদন জমা দেওয়া যায়নি — একটু পরে আবার চেষ্টা করুন। সমস্যা চলতে থাকলে আমাদের সঙ্গে যোগাযোগ করুন।",
  };
}
