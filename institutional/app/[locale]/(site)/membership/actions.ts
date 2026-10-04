"use server";

import { postMembershipApplication } from "@/lib/api/membership";
import { MEMBERSHIP_FAILURE } from "@/lib/form-messages";
import type { MembershipFormState } from "@/lib/form-state";
import { stateFromResult } from "@/lib/upload-route";

export type MembershipApplicationState = MembershipFormState;

/**
 * §7/§41: forwards the browser's own FormData straight to admin-erp's public
 * intake endpoint — no reshaping needed, since the field names here already
 * match what MembershipApplicationController::store() validates. A 422
 * (honeypot tripped, closed season, non-self-apply type, bad photo) surfaces
 * as field-level messages the form re-renders next to the right input,
 * never a generic failure.
 *
 * This is the no-JavaScript path only. With JavaScript the form posts its photo to
 * /api/membership/apply instead (lib/upload-handlers.ts) — a file in a Server Action request is exposed to
 * Cloudflare's `Next-Action` WAF rule. Both paths make the same upstream call and show the same messages.
 */
export async function submitMembershipApplication(
  _prev: MembershipApplicationState,
  formData: FormData,
): Promise<MembershipApplicationState> {
  return stateFromResult(await postMembershipApplication(formData), {
    success: (data) => ({ applicationNo: data.data.application_no }),
    failure: MEMBERSHIP_FAILURE,
  });
}
