"use server";

import { postCommitteeRegistration } from "@/lib/api/committee-registration";
import { COMMITTEE_REGISTRATION_FAILURE } from "@/lib/form-messages";
import type { FormState } from "@/lib/form-state";
import { stateFromResult } from "@/lib/upload-route";

export type CommitteeRegistrationState = FormState;

/**
 * §22-25/§41: the token is bound in as the first argument (see the form's
 * `.bind(null, token)`), not read from a hidden input — it comes from the
 * URL the nominee was sent, never something the browser form itself holds
 * as editable state.
 *
 * This is the no-JavaScript path only. With JavaScript the form posts its photo to
 * /api/committee/register/[token] instead (lib/upload-handlers.ts) — a file in a Server Action request is exposed
 * to Cloudflare's `Next-Action` WAF rule. Both paths make the same upstream call and show the same messages.
 */
export async function submitCommitteeRegistration(
  token: string,
  _prev: CommitteeRegistrationState,
  formData: FormData,
): Promise<CommitteeRegistrationState> {
  return stateFromResult(await postCommitteeRegistration(token, formData), { success: () => ({}), failure: COMMITTEE_REGISTRATION_FAILURE });
}
