import { revalidateTag } from "next/cache";
import { NextResponse } from "next/server";
import { CAROUSEL_CACHE_TAG } from "@/lib/api/carousel";
import { verifyRevalidation } from "@/lib/revalidate-auth";

/**
 * On-demand cache invalidation, called server-to-server by Laravel when an
 * admin changes the homepage carousel. Not a public API: every request must
 * carry an HMAC signature made with REVALIDATE_SECRET (see
 * lib/revalidate-auth.ts), and only tags on the allow-list below can be
 * invalidated, so even a leaked secret cannot be used to purge arbitrary
 * cached data.
 *
 * Failures all return the same bare 401 — never which check failed — so the
 * endpoint cannot be used to probe for a valid timestamp or signature.
 */
const ALLOWED_TAGS: ReadonlySet<string> = new Set([CAROUSEL_CACHE_TAG]);

export async function POST(request: Request) {
  const secret = process.env.REVALIDATE_SECRET;
  if (!secret) {
    return NextResponse.json({ ok: false }, { status: 503 });
  }

  let tag: unknown;
  try {
    ({ tag } = await request.json());
  } catch {
    return NextResponse.json({ ok: false }, { status: 400 });
  }
  if (typeof tag !== "string") {
    return NextResponse.json({ ok: false }, { status: 400 });
  }

  const verdict = verifyRevalidation({
    secret,
    timestamp: request.headers.get("x-revalidate-timestamp"),
    signature: request.headers.get("x-revalidate-signature"),
    tag,
  });
  if (!verdict.ok) {
    return NextResponse.json({ ok: false }, { status: 401 });
  }

  if (!ALLOWED_TAGS.has(tag)) {
    return NextResponse.json({ ok: false }, { status: 400 });
  }

  // expire: 0 = stale content is NOT served while it refreshes, so the very
  // next request after an admin's save (usually the admin reloading the
  // homepage to look) already gets fresh data. The default profile would
  // serve the old slides to that first visitor.
  revalidateTag(tag, { expire: 0 });

  return NextResponse.json({ ok: true, revalidated: tag });
}
