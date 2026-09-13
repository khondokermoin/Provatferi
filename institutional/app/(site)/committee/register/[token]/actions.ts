"use server";

import { apiPostForm, isRecord } from "@/lib/api/client";

export type CommitteeRegistrationState =
  | { status: "idle" }
  | { status: "success" }
  | { status: "validation"; errors: Record<string, string[]> }
  | { status: "error"; message: string };

function isSubmissionCreatedResponse(v: unknown): v is { data: { id: number } } {
  return isRecord(v) && isRecord(v.data) && typeof v.data.id === "number";
}

/**
 * §22-25/§41: the token is bound in as the first argument (see the form's
 * `.bind(null, token)`), not read from a hidden input — it comes from the
 * URL the nominee was sent, never something the browser form itself holds
 * as editable state.
 */
export async function submitCommitteeRegistration(
  token: string,
  _prev: CommitteeRegistrationState,
  formData: FormData,
): Promise<CommitteeRegistrationState> {
  formData.set("registration_token", token);

  const result = await apiPostForm("/api/v1/public/committee-submissions", formData, {
    validate: isSubmissionCreatedResponse,
  });

  if (result.ok) return { status: "success" };
  if (result.error === "validation") return { status: "validation", errors: result.errors };

  return { status: "error", message: "আবেদন জমা দেওয়া যায়নি — একটু পরে আবার চেষ্টা করুন।" };
}
