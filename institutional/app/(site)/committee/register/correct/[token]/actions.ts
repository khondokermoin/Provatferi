"use server";

import { apiPostForm, isRecord } from "@/lib/api/client";

export type CommitteeCorrectionState =
  | { status: "idle" }
  | { status: "success" }
  | { status: "validation"; errors: Record<string, string[]> }
  | { status: "error"; message: string };

function isSubmissionUpdatedResponse(v: unknown): v is { data: { id: number } } {
  return isRecord(v) && isRecord(v.data) && typeof v.data.id === "number";
}

/**
 * §27/§41: the correction token is single-use — a successful resubmission
 * here means the same link can never load the form again (the GET side,
 * getCorrectionSubmission, already refuses a spent token). The token is
 * bound in from the URL, same principle as the registration form's action.
 */
export async function submitCommitteeCorrection(
  token: string,
  _prev: CommitteeCorrectionState,
  formData: FormData,
): Promise<CommitteeCorrectionState> {
  const result = await apiPostForm(`/api/v1/public/committee-submissions/correction/${encodeURIComponent(token)}`, formData, {
    validate: isSubmissionUpdatedResponse,
  });

  if (result.ok) return { status: "success" };
  if (result.error === "validation") return { status: "validation", errors: result.errors };

  return { status: "error", message: "সংশোধিত তথ্য জমা দেওয়া যায়নি — একটু পরে আবার চেষ্টা করুন।" };
}
