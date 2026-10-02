import assert from "node:assert/strict";
import { readdirSync, readFileSync, statSync } from "node:fs";
import { join, relative } from "node:path";
import test from "node:test";

/**
 * Every internal link must go through components/SiteLink.tsx, which turns
 * Next's automatic prefetching off. A raw `import Link from "next/link"`
 * silently reintroduces it — and with it the 2026-10-03 prefetch loop on the
 * Bangla homepage (see SiteLink.tsx for the full account). Nothing about a
 * single raw link looks wrong in review or in a build, which is exactly why
 * this is a test and not a convention.
 */

const ROOT = new URL("..", import.meta.url).pathname.replace(/^\/([A-Za-z]:)/, "$1");
const SCAN = ["app", "components", "lib"];
const ALLOWED = new Set([join("components", "SiteLink.tsx")]);

function* sourceFiles(dir: string): Generator<string> {
  for (const entry of readdirSync(dir)) {
    const full = join(dir, entry);
    if (statSync(full).isDirectory()) {
      yield* sourceFiles(full);
    } else if (/\.(tsx?|mts|mjs|jsx?)$/.test(entry)) {
      yield full;
    }
  }
}

test("no source file imports next/link directly — all links use components/SiteLink", () => {
  const offenders: string[] = [];
  let scanned = 0;
  for (const base of SCAN) {
    for (const file of sourceFiles(join(ROOT, base))) {
      scanned++;
      const rel = relative(ROOT, file);
      if (ALLOWED.has(rel)) continue;
      if (/from\s+["']next\/link["']|require\(\s*["']next\/link["']\s*\)|import\(\s*["']next\/link["']\s*\)/.test(readFileSync(file, "utf8"))) {
        offenders.push(rel);
      }
    }
  }
  assert.ok(scanned > 50, `scanned only ${scanned} files — the path resolution is wrong, so this test is not protecting anything`);
  assert.deepEqual(offenders, [], `import Link from "@/components/SiteLink" instead of next/link in: ${offenders.join(", ")}`);
});

test("SiteLink really defaults prefetch to false and lets a caller opt back in", () => {
  const source = readFileSync(join(ROOT, "components", "SiteLink.tsx"), "utf8");
  assert.match(source, /prefetch\s*=\s*false/, "SiteLink must default `prefetch` to false");
  assert.match(source, /<NextLink\s+prefetch=\{prefetch\}\s+\{\.\.\.props\}/, "an explicit prefetch prop must still be passed through to next/link");
});
