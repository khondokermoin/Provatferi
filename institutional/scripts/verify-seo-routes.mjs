// Runs automatically after every `next build` (see package.json "postbuild").
//
// Context: a readiness pass once reported /sitemap.xml as live, and a later
// pass found it missing. Investigation (2026-09-08) found app/sitemap.ts and
// app/robots.ts both present and correct on disk, and this whole directory
// was never committed to git (no history to bisect) — so the discrepancy was
// almost certainly a stale build/deploy, not a lost file. This script makes
// that class of regression loud and immediate: if either route ever fails to
// build again, `next build` fails right here instead of a silent 404 later.
import { existsSync, readFileSync } from "node:fs";
import { join } from "node:path";

const manifestPath = join(process.cwd(), ".next", "app-path-routes-manifest.json");

if (!existsSync(manifestPath)) {
  console.error("[verify-seo-routes] .next/app-path-routes-manifest.json not found — did `next build` run?");
  process.exit(1);
}

const manifest = JSON.parse(readFileSync(manifestPath, "utf8"));
const required = ["/sitemap.xml/route", "/robots.txt/route"];
const missing = required.filter((key) => !(key in manifest));

if (missing.length > 0) {
  console.error(`[verify-seo-routes] Missing required route(s) from the build: ${missing.join(", ")}`);
  console.error("[verify-seo-routes] Check app/sitemap.ts and app/robots.ts exist and export a valid handler.");
  process.exit(1);
}

console.log("[verify-seo-routes] /sitemap.xml and /robots.txt are present in the build output.");
