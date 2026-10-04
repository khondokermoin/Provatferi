/**
 * The answer an upload form gets back, one shape for every form that posts a file.
 *
 * Both halves import this — the same-origin route handler that answers (lib/upload-route.ts and the handlers
 * built on it) and the client form that reads the answer (lib/post-upload.ts, lib/use-upload-submit.ts) — so it
 * must stay free of server-only and client-only imports. A form's own success payload (a membership application
 * number, say) rides in `Extra`.
 */

export type FieldErrors = Record<string, string[]>;

/** A Server Action as `useActionState` calls it: the previous state and the submitted form in, the next state out. */
export type FormAction<S> = (previous: S, formData: FormData) => Promise<S>;

export type FormState<Extra extends object = object> =
  | { status: "idle" }
  | ({ status: "success" } & Extra)
  | { status: "validation"; errors: FieldErrors }
  | { status: "error"; message: string };

/** A request that never got as far as Laravel because the photo is bigger than any legitimate one. */
export const PHOTO_TOO_LARGE: FieldErrors = { photo: ["ছবির আকার সর্বোচ্চ ৫ মেগাবাইট হতে পারবে।"] };

/**
 * Narrows what a route handler answered with. A page from an edge or proxy layer (a Cloudflare block, a 502) is
 * not JSON and never gets here — the caller treats a thrown parse as a generic failure — and neither does JSON
 * of some other shape.
 */
export function isFormState<Extra extends object = object>(value: unknown): value is FormState<Extra> {
  if (typeof value !== "object" || value === null) return false;
  const { status, errors, message } = value as { status?: unknown; errors?: unknown; message?: unknown };
  if (status === "success") return true;
  if (status === "validation") return typeof errors === "object" && errors !== null && !Array.isArray(errors);
  return status === "error" && typeof message === "string";
}

/** The membership application's success carries the number the applicant is told to keep. */
export type MembershipFormState = FormState<{ applicationNo: string }>;

export function isMembershipFormState(value: unknown): value is MembershipFormState {
  if (!isFormState<{ applicationNo: string }>(value)) return false;
  return value.status !== "success" || typeof value.applicationNo === "string";
}

/** The member profile form can also be told the session is gone (the cookie expired while the page was open). */
export type MemberProfileAnswer = FormState | { status: "unauthenticated" };

export function isMemberProfileAnswer(value: unknown): value is MemberProfileAnswer {
  if (typeof value === "object" && value !== null && (value as { status?: unknown }).status === "unauthenticated") return true;
  return isFormState(value);
}
