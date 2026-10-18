import "server-only";
import { apiPostJson, isRecord } from "./api/client";

/**
 * Server-side half of "the Messenger group is offered only after a verified application"
 * (admin-erp/docs/VOLUNTEER_MESSENGER_GROUP.md).
 *
 * When Laravel accepts an application it hands this server a short-lived PROOF (an opaque, authenticated-encrypted blob:
 * lib/volunteer-application.ts keeps it out of everything the browser is sent). The proof travels in an HttpOnly,
 * Secure, SameSite=Lax cookie that is scoped to the confirmation page's own path, never in an address, a form field or
 * the page's HTML. The confirmation page (a Server Component) reads it and asks Laravel to verify it; only a proof that
 * verifies releases the invite, and the invite is rendered only into that one response, which is never cached.
 *
 * Nothing here decides anything: Laravel does (signature, expiry, posting, application still live). This file only carries
 * the proof and refuses to render a link that is not a Messenger group invite.
 */

/** The cookie that carries the proof from the submission to the confirmation page. */
export const SUCCESS_COOKIE = "pf_applied";

/** A proof as Laravel issued it: the opaque value and how long (seconds) it stays valid. */
export interface SuccessProof {
  value: string;
  maxAge: number;
}

/** What a proof may look like on its way through (base64 of an encrypted envelope); anything else is not stored. */
const PROOF_PATTERN = /^[A-Za-z0-9+/=_-]{40,2048}$/;
const MIN_MAX_AGE = 60;
const MAX_MAX_AGE = 3600;

/** The proof from Laravel's answer, or null when it is missing or not shaped like one (the page then shows no group). */
export function readSuccessProof(data: Record<string, unknown>): SuccessProof | null {
  const value = data.success_proof;
  const maxAge = data.success_proof_expires_in;
  if (typeof value !== "string" || !PROOF_PATTERN.test(value)) return null;
  if (typeof maxAge !== "number" || !Number.isFinite(maxAge)) return null;
  return { value, maxAge: Math.min(MAX_MAX_AGE, Math.max(MIN_MAX_AGE, Math.floor(maxAge))) };
}

/**
 * Where the cookie is sent: the confirmation page itself (a cookie's Path is what the browser sees, not what proxy.ts
 * rewrites it to), and nothing else on the site.
 */
export function successCookiePath(slug: string, locale: "bn" | "en"): string {
  const segment = encodeURIComponent(slug);
  return locale === "en" ? `/en/recruitment/${segment}/apply/success` : `/recruitment/${segment}/apply/success`;
}

/** The confirmation page in both languages, for a route handler that can send one Set-Cookie line per path. */
export function successCookiePaths(slug: string): string[] {
  return [successCookiePath(slug, "bn"), successCookiePath(slug, "en")];
}

/** `Set-Cookie` header lines for a route handler (one per path). */
export function successSetCookieHeaders(slug: string, proof: SuccessProof): [string, string][] {
  return successCookiePaths(slug).map((path): [string, string] => [
    "Set-Cookie",
    `${SUCCESS_COOKIE}=${encodeURIComponent(proof.value)}; Max-Age=${proof.maxAge}; Path=${path}; HttpOnly; Secure; SameSite=Lax`,
  ]);
}

/**
 * The cookie through Next's cookie store, for a Server Action (the no-JavaScript path). It sets ONE cookie, for the one
 * confirmation the action redirects to: Next's store is keyed by NAME, so a second `set` of the same name with another Path
 * would replace the first rather than add to it (seen in a real browser: the Bangla cookie was lost and the confirmation
 * showed the neutral page). The action redirects to the unprefixed (Bangla) address, so that is the default.
 */
export function setSuccessCookie(
  store: { set: (name: string, value: string, options: { maxAge: number; path: string; httpOnly: boolean; secure: boolean; sameSite: "lax" }) => unknown },
  slug: string,
  proof: SuccessProof,
  locale: "bn" | "en" = "bn",
): void {
  store.set(SUCCESS_COOKIE, proof.value, { maxAge: proof.maxAge, path: successCookiePath(slug, locale), httpOnly: true, secure: true, sameSite: "lax" });
}

/** The only shape of link the confirmation will render: Laravel's canonical Messenger group invite, nothing wider. */
const INVITE_PATTERN = /^https:\/\/m\.me\/j\/[A-Za-z0-9_-]{6,100}\/$/;

export function isMessengerInvite(value: unknown): value is string {
  return typeof value === "string" && INVITE_PATTERN.test(value);
}

export type SuccessVerification =
  /** A real, current proof. `messengerUrl` is null when the feature is off or has no valid invite. */
  | { kind: "verified"; messengerUrl: string | null }
  /** No proof, or one that did not verify (forged, altered, expired, another posting's, application no longer live). */
  | { kind: "unverified" }
  /** Laravel could not be asked: the page can say so, but must not guess. */
  | { kind: "unavailable" };

interface VerifyResponse {
  data: { verified: boolean; messenger: { url: string } | null };
}

function isVerifyResponse(json: unknown): json is VerifyResponse {
  if (!isRecord(json) || !isRecord(json.data) || typeof json.data.verified !== "boolean") return false;
  const messenger = json.data.messenger;
  return messenger === null || messenger === undefined || (isRecord(messenger) && typeof messenger.url === "string");
}

/**
 * Asks Laravel whether `proof` shows that this browser has just submitted an application to this posting. The proof goes in
 * the request BODY. No proof means no question is asked at all.
 */
export async function verifyVolunteerSuccess(slug: string, proof: string | null): Promise<SuccessVerification> {
  if (proof === null || proof === "") return { kind: "unverified" };

  const result = await apiPostJson(`/api/v1/public/recruitment/${encodeURIComponent(slug)}/application-success`, { proof }, { validate: isVerifyResponse });
  if (!result.ok) return { kind: "unavailable" };
  if (!result.data.data.verified) return { kind: "unverified" };

  const url = result.data.data.messenger?.url;
  // Laravel already normalises the link; this is the last gate before it becomes an href.
  return { kind: "verified", messengerUrl: isMessengerInvite(url) ? url : null };
}
