import NextLink from "next/link";
import type { ComponentProps } from "react";

/**
 * The site's <Link>: `next/link` with automatic prefetching OFF by default.
 * Every internal link goes through this; app code must not import
 * `next/link` directly (__tests__/no-raw-next-link.test.mts enforces that).
 *
 * Why prefetching is off. Found 2026-10-03 while doing real-Chrome QA of the
 * homepage carousel: on the Bangla homepage (`/`, which proxy.ts rewrites to
 * `/bn`) Next's viewport prefetcher fell into a loop on some page loads —
 * fetch `/about?_rsc=…`, finish, find the entry already stale, fetch again —
 * at the speed of the network round trip. Measured: ~10 requests/second per
 * open tab in production, ~280/second locally, never stopping, for as long as
 * the tab stayed open. Roughly two in three loads of the Bangla homepage were
 * affected; the English homepage and every other page never were (12 of 12
 * loads clean). It reproduces with no carousel and no API data at all, so it
 * predates the carousel work and is a router-level problem on the rewritten
 * root, not anything in the page's own content. Hiding the one hero
 * `<a href="/about">` stopped a live storm instantly, but other links were
 * seen joining in, so fixing one link would not fix the class.
 *
 * Why turning it off costs almost nothing here. A prefetch only ever warms
 * the part of a route up to its nearest loading boundary, and this site has
 * no loading.tsx anywhere — every route is dynamic, and the prefetch response
 * is `no-store` with a zero client stale time — so there is nothing useful
 * for a prefetch to have fetched. What it did cost was ~15 extra server round
 * trips per page load for every visitor, on a small shared Node host. A click
 * simply fetches the page then, exactly as it would have after a stale
 * prefetch.
 *
 * Pass `prefetch` explicitly on a specific link to opt back in. If this site
 * later gains loading boundaries, or Next fixes the loop, this default is the
 * one place to revisit.
 *
 * Deliberately not a client component: it has no hooks or handlers of its
 * own, so it can be used from Server and Client Components alike.
 */
export default function SiteLink({ prefetch = false, ...props }: ComponentProps<typeof NextLink>) {
  return <NextLink prefetch={prefetch} {...props} />;
}
