import { NextRequest, NextResponse } from "next/server";

/**
 * Phase 2 — locale routing. Bangla is the default and stays UNPREFIXED in
 * the browser (provatferi.org/activities), English is the only prefixed
 * locale (provatferi.org/en/activities). This REWRITES (never redirects) an
 * unprefixed request to an internal `/bn/...` path so `app/[locale]/...`
 * can resolve it — the address bar never shows `/bn`. A request already
 * under `/en/...` matches `[locale]=en` directly and needs no rewrite.
 *
 * The matcher below excludes anything containing a dot (favicon.ico,
 * /sitemap.xml, /robots.txt, /brand/*.png, /js/*.js, ...) and `_next` — all
 * of those are real routes/static files at the true app root, outside
 * `[locale]`, and rewriting them to `/bn/sitemap.xml` etc. would 404 them.
 *
 * `x-pathname` carries the real, browser-visible URL through to Server
 * Components that have no route params to read it from — specifically
 * `not-found.tsx`, whose params are not reliably populated for a genuinely
 * unmatched URL (as opposed to an explicit `notFound()` call from inside a
 * resolved page, which does have them). Client Components don't need this;
 * they already derive locale from `usePathname()`.
 */
export function middleware(request: NextRequest) {
  const { pathname } = request.nextUrl;
  const headers = new Headers(request.headers);
  headers.set("x-pathname", pathname);

  if (pathname === "/en" || pathname.startsWith("/en/")) {
    return NextResponse.next({ request: { headers } });
  }

  const rewritten = request.nextUrl.clone();
  rewritten.pathname = pathname === "/" ? "/bn" : `/bn${pathname}`;
  return NextResponse.rewrite(rewritten, { request: { headers } });
}

export const config = {
  matcher: ["/((?!_next|.*\\..*).*)"],
};
