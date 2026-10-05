import { createHmac, timingSafeEqual } from "node:crypto";

/**
 * Verification for the signed on-demand revalidation call Laravel makes to POST /api/revalidate whenever an admin
 * changes something the public site caches (the homepage carousel; the membership seasons, types and fees).
 *
 * The secret itself never travels: only a timestamp, a nonce and the resulting HMAC do, so a captured request cannot be
 * turned into a credential. It is deliberately dependency-free (node:crypto only) so it is unit-testable in isolation
 * from Next.
 *
 * Two schemes are accepted, because the two servers deploy separately and either can be a release ahead:
 *
 *   v2 (current)  HMAC over "v2.<unix-timestamp>.<nonce>.<tag1,tag2,…>" — several tags in one call, the tags sorted and
 *                 unique. The nonce is signed too and the site remembers every nonce it has accepted (createReplayGuard),
 *                 so a captured request is replayable neither inside the timestamp window nor after it.
 *   v1 (legacy)   HMAC over "<unix-timestamp>.<tag>" — one tag, no nonce, replayable inside the timestamp window. Still
 *                 accepted so an admin-erp release that has not been updated keeps working; admin-erp no longer sends it.
 *
 * The "v2." prefix keeps the two apart: a v1 signature can never be passed off as a v2 one or the other way round.
 */

/** A request older (or further in the future) than this is rejected. */
export const REVALIDATE_MAX_SKEW_SECONDS = 300;

/** What a legitimate nonce looks like (admin-erp sends 32 hex characters). */
export const NONCE_PATTERN = /^[A-Za-z0-9_-]{16,64}$/;

export type RevalidateVerdict = { ok: true } | { ok: false; reason: "missing" | "stale" | "bad_signature" | "bad_nonce" };

// ---------------------------------------------------------------------------
// shared checks
// ---------------------------------------------------------------------------

/** Digits only: Number("1e3"), Number("0x10") and Number(" 5 ") all parse, and none is a timestamp a signer would send. */
function timestampIsFresh(timestamp: string, nowSeconds: number): boolean {
  if (!/^\d{1,12}$/.test(timestamp)) return false;
  return Math.abs(nowSeconds - Number(timestamp)) <= REVALIDATE_MAX_SKEW_SECONDS;
}

/** timingSafeEqual throws on a length mismatch, which would turn a malformed signature into a 500 instead of a clean rejection. */
function signaturesMatch(expectedHex: string, receivedHex: string): boolean {
  const expected = Buffer.from(expectedHex, "hex");
  const received = Buffer.from(receivedHex, "hex");
  return expected.length === received.length && timingSafeEqual(expected, received);
}

// ---------------------------------------------------------------------------
// v1 — single tag, legacy
// ---------------------------------------------------------------------------

export function signRevalidation(secret: string, timestamp: string, tag: string): string {
  return createHmac("sha256", secret).update(`${timestamp}.${tag}`).digest("hex");
}

export function verifyRevalidation(input: {
  secret: string;
  timestamp: string | null;
  signature: string | null;
  tag: string;
  nowSeconds?: number;
}): RevalidateVerdict {
  const { secret, timestamp, signature, tag } = input;
  if (!timestamp || !signature) return { ok: false, reason: "missing" };

  if (!timestampIsFresh(timestamp, input.nowSeconds ?? Math.floor(Date.now() / 1000))) return { ok: false, reason: "stale" };

  if (!signaturesMatch(signRevalidation(secret, timestamp, tag), signature)) return { ok: false, reason: "bad_signature" };

  return { ok: true };
}

// ---------------------------------------------------------------------------
// v2 — several tags, nonce
// ---------------------------------------------------------------------------

/** Tags in the one form both sides sign: unique and ascending. */
export function canonicalTags(tags: readonly string[]): string[] {
  return [...new Set(tags)].sort();
}

export function signRevalidationV2(secret: string, timestamp: string, nonce: string, tags: readonly string[]): string {
  return createHmac("sha256", secret).update(`v2.${timestamp}.${nonce}.${canonicalTags(tags).join(",")}`).digest("hex");
}

export function verifyRevalidationV2(input: {
  secret: string;
  timestamp: string | null;
  nonce: string | null;
  signature: string | null;
  tags: readonly string[];
  nowSeconds?: number;
}): RevalidateVerdict {
  const { secret, timestamp, nonce, signature, tags } = input;
  if (!timestamp || !nonce || !signature) return { ok: false, reason: "missing" };
  if (!NONCE_PATTERN.test(nonce)) return { ok: false, reason: "bad_nonce" };

  if (!timestampIsFresh(timestamp, input.nowSeconds ?? Math.floor(Date.now() / 1000))) return { ok: false, reason: "stale" };

  if (!signaturesMatch(signRevalidationV2(secret, timestamp, nonce, tags), signature)) return { ok: false, reason: "bad_signature" };

  return { ok: true };
}

// ---------------------------------------------------------------------------
// replay protection
// ---------------------------------------------------------------------------

export interface ReplayGuard {
  /** True the first time a nonce is claimed; false if it has been claimed within the window (a replay). */
  claim(nonce: string, nowSeconds?: number): boolean;
}

/**
 * Remembers every nonce the site has ACCEPTED (claim it only after the signature verified, so nobody without the secret
 * can fill it), for as long as a request carrying that nonce could still pass the timestamp check: a request is valid
 * for ±REVALIDATE_MAX_SKEW_SECONDS around its timestamp, so a nonce is kept for twice that, plus a margin.
 *
 * In memory, per server process — "where supported": a restart forgets the nonces, and a captured request could then be
 * replayed once more, but only inside its (at most five-minute) timestamp window, and only to purge the very tags it was
 * signed for. The size is bounded, the oldest entries going first.
 */
export function createReplayGuard(options: { windowSeconds?: number; maxEntries?: number } = {}): ReplayGuard {
  const windowSeconds = options.windowSeconds ?? REVALIDATE_MAX_SKEW_SECONDS * 2 + 30;
  const maxEntries = options.maxEntries ?? 5000;
  const seen = new Map<string, number>(); // nonce -> the second at which it can be forgotten

  return {
    claim(nonce: string, nowSeconds: number = Math.floor(Date.now() / 1000)): boolean {
      for (const [remembered, forgetAt] of seen) {
        if (forgetAt <= nowSeconds) seen.delete(remembered);
      }
      if (seen.has(nonce)) return false;

      while (seen.size >= maxEntries) {
        const oldest = seen.keys().next().value;
        if (oldest === undefined) break;
        seen.delete(oldest);
      }
      seen.set(nonce, nowSeconds + windowSeconds);
      return true;
    },
  };
}
