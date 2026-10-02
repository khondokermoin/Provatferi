import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import test from "node:test";
import { REVALIDATE_MAX_SKEW_SECONDS, signRevalidation, verifyRevalidation } from "../lib/revalidate-auth.ts";

/**
 * Reads the real matcher literal out of proxy.ts instead of importing it:
 * proxy.ts imports `next/server`, which Node cannot resolve outside the
 * bundler, and Next requires `config.matcher` to be a static literal in that
 * file, so it cannot be moved into an importable module either. Parsing the
 * source keeps the test pinned to exactly what ships.
 */
function proxyMatcher(): string {
  const source = readFileSync(new URL("../proxy.ts", import.meta.url), "utf8");
  const found = source.match(/matcher:\s*\[\s*"((?:[^"\\]|\\.)*)"\s*\]/);
  assert.ok(found, "could not find a string-literal matcher in proxy.ts");
  return JSON.parse(`"${found[1]}"`) as string;
}

const SECRET = "test-secret-not-a-real-one";
const TAG = "homepage-carousel";
const NOW = 1_800_000_000;

function signed(overrides: Partial<{ secret: string; timestamp: string; tag: string }> = {}) {
  const timestamp = overrides.timestamp ?? String(NOW);
  return {
    timestamp,
    signature: signRevalidation(overrides.secret ?? SECRET, timestamp, overrides.tag ?? TAG),
  };
}

test("a correctly signed, fresh request is accepted", () => {
  const { timestamp, signature } = signed();
  assert.deepEqual(verifyRevalidation({ secret: SECRET, timestamp, signature, tag: TAG, nowSeconds: NOW }), { ok: true });
});

test("a signature made with a different secret is rejected", () => {
  const { timestamp, signature } = signed({ secret: "someone-elses-secret" });
  assert.deepEqual(verifyRevalidation({ secret: SECRET, timestamp, signature, tag: TAG, nowSeconds: NOW }), { ok: false, reason: "bad_signature" });
});

test("a signature cannot be reused for a different tag", () => {
  // The tag is inside the signed payload, so a captured request to purge one
  // thing cannot be replayed to purge another.
  const { timestamp, signature } = signed({ tag: "homepage-carousel" });
  assert.deepEqual(verifyRevalidation({ secret: SECRET, timestamp, signature, tag: "notices", nowSeconds: NOW }), { ok: false, reason: "bad_signature" });
});

test("a tampered timestamp invalidates the signature", () => {
  const { signature } = signed();
  const result = verifyRevalidation({ secret: SECRET, timestamp: String(NOW + 1), signature, tag: TAG, nowSeconds: NOW });
  assert.deepEqual(result, { ok: false, reason: "bad_signature" });
});

test("a request older than the skew window is rejected even with a valid signature", () => {
  const stale = String(NOW - REVALIDATE_MAX_SKEW_SECONDS - 1);
  const { signature } = signed({ timestamp: stale });
  assert.deepEqual(verifyRevalidation({ secret: SECRET, timestamp: stale, signature, tag: TAG, nowSeconds: NOW }), { ok: false, reason: "stale" });
});

test("a request dated too far in the future is rejected too", () => {
  const future = String(NOW + REVALIDATE_MAX_SKEW_SECONDS + 1);
  const { signature } = signed({ timestamp: future });
  assert.deepEqual(verifyRevalidation({ secret: SECRET, timestamp: future, signature, tag: TAG, nowSeconds: NOW }), { ok: false, reason: "stale" });
});

test("a request exactly at the edge of the window is still accepted", () => {
  const edge = String(NOW - REVALIDATE_MAX_SKEW_SECONDS);
  const { signature } = signed({ timestamp: edge });
  assert.deepEqual(verifyRevalidation({ secret: SECRET, timestamp: edge, signature, tag: TAG, nowSeconds: NOW }), { ok: true });
});

test("missing headers are rejected, not treated as an error", () => {
  assert.deepEqual(verifyRevalidation({ secret: SECRET, timestamp: null, signature: null, tag: TAG, nowSeconds: NOW }), { ok: false, reason: "missing" });
  assert.deepEqual(verifyRevalidation({ secret: SECRET, timestamp: String(NOW), signature: null, tag: TAG, nowSeconds: NOW }), { ok: false, reason: "missing" });
  assert.deepEqual(verifyRevalidation({ secret: SECRET, timestamp: null, signature: "ab", tag: TAG, nowSeconds: NOW }), { ok: false, reason: "missing" });
});

test("timestamps that only parse as numbers are rejected as stale", () => {
  // Number("1e9"), Number("0x10"), Number(" 5 ") all succeed; none is a unix
  // timestamp a legitimate signer would ever send.
  for (const odd of ["1e9", "0x10", " 5 ", "-1", "1.5", "", "abc"]) {
    const signature = signRevalidation(SECRET, odd, TAG);
    const result = verifyRevalidation({ secret: SECRET, timestamp: odd, signature, tag: TAG, nowSeconds: NOW });
    assert.equal(result.ok, false, `"${odd}" must not be accepted`);
  }
});

test("a malformed or wrong-length signature is a clean rejection, never a thrown error", () => {
  // timingSafeEqual throws on a length mismatch; the verifier must turn that
  // into a rejection or a garbage header becomes a 500.
  for (const garbage of ["", "zz", "abc", "00", "g".repeat(64), "a".repeat(63), "a".repeat(200)]) {
    assert.doesNotThrow(() => verifyRevalidation({ secret: SECRET, timestamp: String(NOW), signature: garbage, tag: TAG, nowSeconds: NOW }));
    const result = verifyRevalidation({ secret: SECRET, timestamp: String(NOW), signature: garbage, tag: TAG, nowSeconds: NOW });
    assert.equal(result.ok, false);
  }
});

// The proxy rewrites every unprefixed path to /bn/... so a Bangla-default URL
// resolves under app/[locale]. A route that lives outside [locale] and is not
// excluded from the matcher gets rewritten to /bn/<route> and 404s. For
// /api/revalidate that failure is invisible: Laravel deliberately swallows a
// failed revalidation call so an admin's save never breaks, so the carousel
// would just quietly go back to updating once every 120 seconds.
test("the proxy matcher leaves /api/* alone but still handles real pages", () => {
  const pattern = new RegExp(`^${proxyMatcher()}$`);

  assert.equal(pattern.test("/api/revalidate"), false, "/api/revalidate must NOT be rewritten to /bn/api/revalidate");
  assert.equal(pattern.test("/api/anything/else"), false);

  for (const page of ["/", "/about", "/en", "/en/about", "/activities/some-slug", "/recruitment/apply"]) {
    assert.equal(pattern.test(page), true, `${page} must still go through the locale rewrite`);
  }
  for (const asset of ["/favicon.ico", "/sitemap.xml", "/robots.txt", "/brand/logo.png"]) {
    assert.equal(pattern.test(asset), false, `${asset} must stay excluded`);
  }
});
