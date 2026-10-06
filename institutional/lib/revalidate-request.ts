import { CAROUSEL_CACHE_TAG } from "./api/carousel";
import { MEMBERS_CACHE_TAG } from "./api/member-directory";
import { MEMBERSHIP_CACHE_TAGS } from "./api/membership";
import { canonicalTags, verifyRevalidation, verifyRevalidationV2, type ReplayGuard } from "./revalidate-auth";

/**
 * Decides what a POST /api/revalidate request may do, as a plain function of its headers and body so the whole policy
 * is unit-testable without Next (app/api/revalidate/route.ts is a thin shell around it).
 *
 * Not a public API: a request must carry a valid HMAC signature (lib/revalidate-auth.ts), and only the tags on the
 * allow-list below can be invalidated — so even a leaked secret cannot be used to purge arbitrary cached data, and one
 * unknown tag refuses the whole request rather than half-applying it.
 *
 * Every failure is a bare status code — never which check failed — so the endpoint cannot be used to probe for a valid
 * timestamp, nonce or signature. Order: the secret must be configured (503); the body must have the right shape (400);
 * the signature must verify (401); a nonce must not have been seen (401); every tag must be allowed (400). Tag names
 * are only judged AFTER the caller proved who it is.
 */
export const ALLOWED_REVALIDATE_TAGS: ReadonlySet<string> = new Set([CAROUSEL_CACHE_TAG, ...Object.values(MEMBERSHIP_CACHE_TAGS), MEMBERS_CACHE_TAG]);

/** More than the number of tags that exist is not a legitimate call. */
export const MAX_TAGS_PER_REQUEST = 8;

export type RevalidateDecision =
  | { ok: true; tags: string[]; scheme: "v1" | "v2" }
  | { ok: false; status: 400 | 401 | 503 };

export function decideRevalidation(input: {
  secret: string | undefined;
  headers: { timestamp: string | null; nonce: string | null; signature: string | null };
  body: unknown;
  replayGuard: ReplayGuard;
  nowSeconds?: number;
}): RevalidateDecision {
  const { secret, headers, body, replayGuard, nowSeconds } = input;
  if (!secret) return { ok: false, status: 503 };

  const record = typeof body === "object" && body !== null && !Array.isArray(body) ? (body as Record<string, unknown>) : null;
  if (record === null) return { ok: false, status: 400 };

  // A nonce header means v2: several tags, nonce signed and remembered. Without one it is the legacy single-tag form.
  if (headers.nonce !== null) {
    const tags = record.tags;
    if (!Array.isArray(tags) || tags.length === 0 || tags.length > MAX_TAGS_PER_REQUEST || !tags.every((t) => typeof t === "string")) {
      return { ok: false, status: 400 };
    }
    const wanted = canonicalTags(tags as string[]);

    const verdict = verifyRevalidationV2({ secret, timestamp: headers.timestamp, nonce: headers.nonce, signature: headers.signature, tags: wanted, nowSeconds });
    if (!verdict.ok) return { ok: false, status: 401 };
    if (!replayGuard.claim(headers.nonce, nowSeconds)) return { ok: false, status: 401 };

    if (!wanted.every((tag) => ALLOWED_REVALIDATE_TAGS.has(tag))) return { ok: false, status: 400 };
    return { ok: true, tags: wanted, scheme: "v2" };
  }

  const tag = record.tag;
  if (typeof tag !== "string") return { ok: false, status: 400 };

  const verdict = verifyRevalidation({ secret, timestamp: headers.timestamp, signature: headers.signature, tag, nowSeconds });
  if (!verdict.ok) return { ok: false, status: 401 };

  if (!ALLOWED_REVALIDATE_TAGS.has(tag)) return { ok: false, status: 400 };
  return { ok: true, tags: [tag], scheme: "v1" };
}
