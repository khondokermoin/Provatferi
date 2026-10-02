import { createHmac, timingSafeEqual } from "node:crypto";

/**
 * Verification for the signed on-demand revalidation call Laravel makes to
 * POST /api/revalidate whenever an admin changes the homepage carousel.
 *
 * The scheme is an HMAC over "<unix-timestamp>.<tag>" with a secret both
 * servers hold. The secret itself never travels: only the timestamp and the
 * resulting signature do, so a captured request cannot be turned into a
 * credential, and the timestamp window bounds how long a captured request
 * stays replayable. It is deliberately dependency-free (node:crypto only) so
 * it is unit-testable in isolation from Next.
 */

/** A request older (or further in the future) than this is rejected. */
export const REVALIDATE_MAX_SKEW_SECONDS = 300;

export function signRevalidation(secret: string, timestamp: string, tag: string): string {
  return createHmac("sha256", secret).update(`${timestamp}.${tag}`).digest("hex");
}

export type RevalidateVerdict = { ok: true } | { ok: false; reason: "missing" | "stale" | "bad_signature" };

export function verifyRevalidation(input: {
  secret: string;
  timestamp: string | null;
  signature: string | null;
  tag: string;
  nowSeconds?: number;
}): RevalidateVerdict {
  const { secret, timestamp, signature, tag } = input;
  if (!timestamp || !signature) return { ok: false, reason: "missing" };

  // Digits only: Number("1e3"), Number("0x10") and Number(" 5 ") all parse,
  // and none of them is a unix timestamp a signer would ever produce.
  if (!/^\d{1,12}$/.test(timestamp)) return { ok: false, reason: "stale" };

  const now = input.nowSeconds ?? Math.floor(Date.now() / 1000);
  if (Math.abs(now - Number(timestamp)) > REVALIDATE_MAX_SKEW_SECONDS) return { ok: false, reason: "stale" };

  const expected = Buffer.from(signRevalidation(secret, timestamp, tag), "hex");
  const received = Buffer.from(signature, "hex");

  // timingSafeEqual throws on a length mismatch, which would turn a malformed
  // signature into a 500 instead of a clean rejection.
  if (expected.length !== received.length || !timingSafeEqual(expected, received)) {
    return { ok: false, reason: "bad_signature" };
  }

  return { ok: true };
}
