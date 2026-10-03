// The site's own public URL — used to resolve every relative canonical/OG URL
// into an absolute one, and by robots.ts / sitemap.ts. Falls back to the real
// production domain so a missing env var never silently emits localhost URLs.
//
// Lives here, not in app/layout.tsx: a layout may only export the Next.js
// route-segment fields, and any other export fails the type check that
// `next build --webpack` runs over .next/types. (Turbopack builds skip that
// check, which is how the extra export went unnoticed until the production
// build had to be moved to webpack for Hostinger's build container.)
export const SITE_URL = process.env.NEXT_PUBLIC_SITE_URL ?? "https://sahittopata.provatferi.org";
