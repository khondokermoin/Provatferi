"use server";

import { postCommitteeCorrection } from "@/lib/api/committee-registration";
import { COMMITTEE_CORRECTION_FAILURE } from "@/lib/form-messages";
import type { FormState } from "@/lib/form-state";
import { stateFromResult } from "@/lib/upload-route";

export type CommitteeCorrectionState = FormState;

/**
 * §27/§41: the correction token is single-use — a successful resubmission
 * here means the same link can never load the form again (the GET side,
 * getCorrectionSubmission, already refuses a spent token). The token is
 * bound in from the URL, same principle as the registration form's action.
 *
 * This is the no-JavaScript path only. With JavaScript the form posts its photo to
 * /api/committee/correct/[token] instead (lib/upload-handlers.ts) — a file in a Server Action request is exposed
 * to Cloudflare's `Next-Action` WAF rule. Both paths make the same upstream call and show the same messages.
 */
export async function submitCommitteeCorrection(
  token: string,
  _prev: CommitteeCorrectionState,
  formData: FormData,
): Promise<CommitteeCorrectionState> {
  return stateFromResult(await postCommitteeCorrection(token, formData), { success: () => ({}), failure: COMMITTEE_CORRECTION_FAILURE });
}
