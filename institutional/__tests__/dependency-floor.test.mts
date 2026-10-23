import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { join } from "node:path";
import test from "node:test";

/**
 * The security floor of the two Next.js applications (2026-10-23): the lockfiles must never again resolve a version older than the
 * one that fixed the October 2026 advisories.
 *
 *   next  16.4.1   GHSA-673q-mjm6-r5xr (RCE through `use cache`), GHSA-2464-fhr4-w9xw (RCE in the image optimizer with SVG input),
 *                  GHSA-p3m5-59mp-jv5r (a request that holds the CPU of an App Router app with a Server Action) — and the
 *                  moderate ones fixed in 16.3.8 (SSRF in the optimizer, cache poisoning, metadata image routes)
 *   sharp  0.35.5  GHSA-wq5f-xc86-pv6w (librsvg use-after-free; Next's optimizer calls it)
 *   source-map-js 1.2.2  GHSA-68fv-2mgg-jv7q (an indexed source map can block the event loop)
 *
 * It reads package-lock.json, the file Hostinger's `npm install` follows, not just package.json: a range in package.json can still
 * resolve to an old copy in the lock, and a copy nested under another package counts too.
 */
const ROOT = new URL("../..", import.meta.url).pathname.replace(/^\/([A-Za-z]:)/, "$1");
const FLOORS: Record<string, string> = { next: "16.4.1", sharp: "0.35.5", "source-map-js": "1.2.2" };

const read = (path: string) => JSON.parse(readFileSync(join(ROOT, path), "utf8"));
const parts = (version: string) => version.split("-")[0].split(".").map((n) => Number(n));
function atLeast(version: string, floor: string): boolean {
  const a = parts(version);
  const b = parts(floor);
  for (let i = 0; i < 3; i++) {
    if ((a[i] ?? 0) !== (b[i] ?? 0)) return (a[i] ?? 0) > (b[i] ?? 0);
  }
  return true;
}

for (const app of ["institutional", "frontend"]) {
  test(`${app}: the lockfile resolves no copy of next, sharp or source-map-js older than the patched version`, () => {
    const packages: Record<string, { version?: string }> = read(`${app}/package-lock.json`).packages;
    for (const [name, floor] of Object.entries(FLOORS)) {
      const copies = Object.entries(packages).filter(([key]) => key === `node_modules/${name}` || key.endsWith(`/node_modules/${name}`));
      assert.ok(copies.length > 0, `${app}: ${name} is in the lockfile`);
      for (const [key, entry] of copies) {
        assert.ok(entry.version && atLeast(entry.version, floor), `${app}: ${key} is ${entry.version}, must be at least ${floor}`);
      }
    }
  });

  test(`${app}: next and eslint-config-next are exact pins at the same version, and the lockfile agrees`, () => {
    const pkg = read(`${app}/package.json`);
    assert.match(pkg.dependencies.next, /^\d+\.\d+\.\d+$/, "next is pinned exactly: an upgrade is a decision, not a side effect of `npm install`");
    assert.equal(pkg.devDependencies["eslint-config-next"], pkg.dependencies.next, "eslint-config-next follows next");
    const packages = read(`${app}/package-lock.json`).packages;
    assert.equal(packages["node_modules/next"].version, pkg.dependencies.next, "the lockfile holds the pinned next");
    assert.equal(packages["node_modules/eslint-config-next"].version, pkg.dependencies.next);
    assert.ok(atLeast(pkg.dependencies.next, FLOORS.next));
  });
}

test("frontend (Sahittopata): the production image optimizer never fetches from this server's own localhost", () => {
  const source = readFileSync(join(ROOT, "frontend/next.config.ts"), "utf8");
  // The XAMPP WordPress address may exist, but only outside production.
  assert.match(source, /process\.env\.NODE_ENV === "production" \? \[\]/, "the development host is switched off in production");
  const production = source.replace(/^\s*\/\/.*$/gm, "").replace(/const developmentImageHosts[\s\S]*?;\n/, "");
  assert.doesNotMatch(production, /localhost/, "no other mention of localhost in the remote patterns");
  assert.match(source, /hostname: "cms\.provatferi\.org", pathname: "\/wp-content\/uploads\/\*\*"/);
  assert.match(source, /hostname: "secure\.gravatar\.com", pathname: "\/avatar\/\*\*"/);
  assert.match(source, /localPatterns: \[\]/, "no file of the site itself is handed to the optimizer");
  assert.doesNotMatch(source, /dangerouslyAllowSVG|dangerouslyAllowLocalIP|unoptimized/);
});
