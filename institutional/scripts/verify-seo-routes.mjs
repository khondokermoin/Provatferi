// Runs automatically after every `next build` (see package.json "postbuild").
//
// Context: a readiness pass once reported /sitemap.xml as live, and a later
// pass found it missing. Investigation (2026-09-08) found app/sitemap.ts and
// app/robots.ts both present and correct on disk, and this whole directory
// was never committed to git (no history to bisect) — so the discrepancy was
// almost certainly a stale build/deploy, not a lost file. This script makes
// that class of regression loud and immediate: if either route ever fails to
// build again, `next build` fails right here instead of a silent 404 later.
//
// Phase 2 Increment 2 (plan Section I): extended to assert the `[locale]`
// route tree itself survived the build — both locales resolve to the SAME
// route set (bn is served from these routes unprefixed via middleware.ts's
// rewrite; en matches them directly), so one manifest check covers both.
import { existsSync, readFileSync } from "node:fs";
import { join } from "node:path";

const manifestPath = join(process.cwd(), ".next", "app-path-routes-manifest.json");

if (!existsSync(manifestPath)) {
  console.error("[verify-seo-routes] .next/app-path-routes-manifest.json not found — did `next build` run?");
  process.exit(1);
}

const manifest = JSON.parse(readFileSync(manifestPath, "utf8"));
const required = [
  "/sitemap.xml/route",
  "/robots.txt/route",
  // Representative sample of the [locale] tree — home plus one static and
  // one dynamic-segment leaf, enough to catch a whole-subtree loss (e.g. an
  // accidental un-nesting during a future refactor) without hardcoding
  // every route this app has.
  "/[locale]/(site)/page",
  "/[locale]/(site)/about/page",
  "/[locale]/(site)/notices/page",
  "/[locale]/(site)/notices/[slug]/page",
  "/[locale]/(site)/activities/[slug]/page",
  "/[locale]/(site)/recruitment/[slug]/page",
];
const missing = required.filter((key) => !(key in manifest));

if (missing.length > 0) {
  console.error(`[verify-seo-routes] Missing required route(s) from the build: ${missing.join(", ")}`);
  console.error("[verify-seo-routes] Check app/sitemap.ts, app/robots.ts and the app/[locale]/(site) tree exist and export valid handlers.");
  process.exit(1);
}

console.log("[verify-seo-routes] /sitemap.xml, /robots.txt and the [locale] route tree are present in the build output.");
