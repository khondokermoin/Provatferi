/**
 * Guards the "no orphan URLs" requirement from the integration plan.
 *
 * app/sitemap.ts and app/(site)/activities/[slug]/page.tsx's
 * generateStaticParams both derive their activity URLs from the exact same
 * source, lib/content.ts `recentActivities` — sitemap.ts maps it to
 * `${website}/activities/${slug}`, generateStaticParams maps it to
 * `{ slug }`. This test can't import either of those two files directly:
 * sitemap.ts imports via the `@/` path alias (a tsconfig/bundler feature,
 * not something plain Node resolves) and the page is a `.tsx` file (Node's
 * native type-stripping does not transform JSX). Pulling in `next build`'s
 * own toolchain just to run this one check would cost far more than it
 * proves, so instead this asserts directly on the shared source: that it is
 * non-empty and every slug is unique. That is the actual invariant either
 * file could violate — a duplicate or missing slug there is exactly what
 * would make the sitemap and the real routes diverge.
 *
 * Once activities move to the API (lib/api/activities.ts — not wired in
 * yet), both files will read from Activity.slug instead; this test's job at
 * that point is to move to lib/api/activities.ts's slug field and assert
 * the same thing against a fetched list.
 */
import { test } from "node:test";
import assert from "node:assert/strict";
import { recentActivities } from "../../content.ts";

test("recentActivities is non-empty — an empty source here would silently empty both the sitemap and the real routes", () => {
  assert.ok(recentActivities.length > 0);
});

test("every recentActivities slug is unique — a duplicate would make generateStaticParams and the sitemap disagree on how many routes exist", () => {
  const slugs = recentActivities.map((a) => a.slug);
  assert.equal(new Set(slugs).size, slugs.length);
});

test("every slug is a valid URL path segment once inserted into /activities/{slug} — no raw spaces or slashes that would need encoding and silently change the URL", () => {
  for (const activity of recentActivities) {
    assert.equal(encodeURIComponent(activity.slug), activity.slug, `slug "${activity.slug}" is not already URL-safe`);
  }
});
