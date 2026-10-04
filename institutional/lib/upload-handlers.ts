import "server-only";
import { postCommitteeCorrection, postCommitteeRegistration } from "./api/committee-registration";
import { updateMemberProfile } from "./api/member";
import { postMembershipApplication } from "./api/membership";
import { COMMITTEE_CORRECTION_FAILURE, COMMITTEE_REGISTRATION_FAILURE, MEMBER_PROFILE_FAILURE, MEMBERSHIP_FAILURE } from "./form-messages";
import type { FormState, MemberProfileAnswer, MembershipFormState } from "./form-state";
import { handleUpload, MAX_PHOTO_FORM_BYTES, photoFormRefusal, stateFromResult } from "./upload-route";

/**
 * The four file-upload forms that used to submit through a Server Action, as same-origin route handlers
 * (the route.ts files under app/api are thin wrappers around these). Each one: the shared guard (lib/upload-route.ts), then
 * ONE upstream call named right here, then Laravel's answer as the form's state. Laravel keeps every business rule
 * and every validation; the Server Actions in the pages' actions.ts remain only as the no-JavaScript path, and
 * call the very same upstream functions and share these messages.
 */

/** §22-25: the token is the one in the path of THIS request (the link the nominee opened), never a field of the form. */
export function handleCommitteeRegistrationPost(request: Request, token: string): Promise<Response> {
  return handleUpload<FormState>(request, {
    maxBytes: MAX_PHOTO_FORM_BYTES,
    refuse: photoFormRefusal(COMMITTEE_REGISTRATION_FAILURE),
    run: async (formData) => ({ state: stateFromResult(await postCommitteeRegistration(token, formData), { success: () => ({}), failure: COMMITTEE_REGISTRATION_FAILURE }) }),
  });
}

/** §27: single-use token from the path; Laravel refuses a spent one, which is what makes a repeat harmless. */
export function handleCommitteeCorrectionPost(request: Request, token: string): Promise<Response> {
  return handleUpload<FormState>(request, {
    maxBytes: MAX_PHOTO_FORM_BYTES,
    refuse: photoFormRefusal(COMMITTEE_CORRECTION_FAILURE),
    run: async (formData) => ({ state: stateFromResult(await postCommitteeCorrection(token, formData), { success: () => ({}), failure: COMMITTEE_CORRECTION_FAILURE }) }),
  });
}

export function handleMembershipApplicationPost(request: Request): Promise<Response> {
  return handleUpload<MembershipFormState>(request, {
    maxBytes: MAX_PHOTO_FORM_BYTES,
    refuse: photoFormRefusal(MEMBERSHIP_FAILURE),
    run: async (formData) => ({
      state: stateFromResult(await postMembershipApplication(formData), { success: (data) => ({ applicationNo: data.data.application_no }), failure: MEMBERSHIP_FAILURE }),
    }),
  });
}

/**
 * §12: behind the member login. `sessionToken` is read from the HttpOnly cookie by the route file (it is the only
 * thing here that needs Next's request context) and forwarded as a Bearer to Laravel — never taken from the
 * request body or a header the browser could set. No session: 401 before the upload is even read.
 */
export function handleMemberProfilePost(request: Request, sessionToken: string | null): Promise<Response> {
  const refuse = photoFormRefusal(MEMBER_PROFILE_FAILURE);
  return handleUpload<MemberProfileAnswer>(request, {
    maxBytes: MAX_PHOTO_FORM_BYTES,
    refuse,
    authorize: () => (sessionToken ? null : { state: { status: "unauthenticated" }, status: 401 }),
    run: async (formData) => ({
      state: stateFromResult(await updateMemberProfile(sessionToken as string, formData), { success: () => ({}), failure: MEMBER_PROFILE_FAILURE }),
    }),
  });
}
