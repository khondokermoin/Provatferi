import { revalidateTag } from "next/cache";
import { NextResponse } from "next/server";
import { createReplayGuard } from "@/lib/revalidate-auth";
import { decideRevalidation } from "@/lib/revalidate-request";

/**
 * On-demand cache invalidation, called server-to-server by Laravel when an admin changes something the site caches:
 * the homepage carousel, and the membership seasons, types and fees. Not a public API — every request carries an HMAC
 * signature made with REVALIDATE_SECRET, a nonce this process refuses to see twice, and only allow-listed tags can be
 * invalidated. The whole policy lives in lib/revalidate-request.ts (and is unit-tested there); this file only applies
 * its verdict. The secret never reaches a browser: it is read from the server's environment, not NEXT_PUBLIC_.
 *
 * Failures all return a bare status — never which check failed — so the endpoint cannot be used to probe for a valid
 * timestamp, nonce or signature.
 */
const replayGuard = createReplayGuard();

export async function POST(request: Request) {
  const secret = process.env.REVALIDATE_SECRET;
  if (!secret) {
    return NextResponse.json({ ok: false }, { status: 503 });
  }

  let body: unknown;
  try {
    body = await request.json();
  } catch {
    return NextResponse.json({ ok: false }, { status: 400 });
  }

  const decision = decideRevalidation({
    secret,
    headers: {
      timestamp: request.headers.get("x-revalidate-timestamp"),
      nonce: request.headers.get("x-revalidate-nonce"),
      signature: request.headers.get("x-revalidate-signature"),
    },
    body,
    replayGuard,
  });
  if (!decision.ok) {
    return NextResponse.json({ ok: false }, { status: decision.status });
  }

  // expire: 0 = stale content is NOT served while it refreshes, so the very next request after an admin's save (usually
  // the admin, or a visitor, loading the page) is built from fresh data. The default profile would serve the old data
  // to that first visitor — the exact bug this endpoint exists to prevent.
  for (const tag of decision.tags) {
    revalidateTag(tag, { expire: 0 });
  }

  return NextResponse.json({ ok: true, revalidated: decision.scheme === "v1" ? decision.tags[0] : decision.tags });
}
