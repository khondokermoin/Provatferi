import assert from "node:assert/strict";
import test from "node:test";
import { CAROUSEL_CACHE_TAG } from "../lib/api/carousel.ts";
import { MEMBERSHIP_CACHE_TAGS } from "../lib/api/membership.ts";
import {
  canonicalTags,
  createReplayGuard,
  NONCE_PATTERN,
  REVALIDATE_MAX_SKEW_SECONDS,
  signRevalidation,
  signRevalidationV2,
  verifyRevalidationV2,
} from "../lib/revalidate-auth.ts";
import { ALLOWED_REVALIDATE_TAGS, decideRevalidation, MAX_TAGS_PER_REQUEST } from "../lib/revalidate-request.ts";

/**
 * 2026-10-05: the signed revalidation call grew several tags per call, a nonce the site refuses to see twice, and an
 * allow-list that now includes the membership tags. The legacy single-tag scheme is still accepted (an admin-erp release
 * that has not been updated keeps working) and is pinned here too. The v1 verifier's own tests live in
 * revalidate-auth.test.mts.
 */

const SECRET = "test-secret-not-a-real-one";
const NOW = 1_800_000_000;
const NONCE = "0123456789abcdef0123456789abcdef";

function v2(tags: string[], overrides: Partial<{ secret: string; timestamp: string; nonce: string }> = {}) {
  const timestamp = overrides.timestamp ?? String(NOW);
  const nonce = overrides.nonce ?? NONCE;
  return {
    headers: { timestamp, nonce, signature: signRevalidationV2(overrides.secret ?? SECRET, timestamp, nonce, tags) },
    body: { tags },
  };
}

function decide(request: { headers: { timestamp: string | null; nonce: string | null; signature: string | null }; body: unknown }, guard = createReplayGuard()) {
  return decideRevalidation({ secret: SECRET, ...request, replayGuard: guard, nowSeconds: NOW });
}

// ---------------------------------------------------------------------------
// the v2 signature
// ---------------------------------------------------------------------------

test("a correctly signed, fresh v2 request verifies", () => {
  const { headers } = v2(["membership-seasons"]);
  assert.deepEqual(verifyRevalidationV2({ secret: SECRET, ...headers, tags: ["membership-seasons"], nowSeconds: NOW }), { ok: true });
});

test("the signature covers the sorted, unique tags — the order and repeats in the body never matter", () => {
  assert.deepEqual(canonicalTags(["b", "a", "b", "c"]), ["a", "b", "c"]);
  assert.equal(signRevalidationV2(SECRET, "1", NONCE, ["b", "a"]), signRevalidationV2(SECRET, "1", NONCE, ["a", "b", "a"]));
  assert.notEqual(signRevalidationV2(SECRET, "1", NONCE, ["a", "b"]), signRevalidationV2(SECRET, "1", NONCE, ["a"]));
});

test("a v2 signature cannot be reused for other tags, another nonce, another timestamp or by another secret", () => {
  const { headers } = v2(["membership-seasons"]);
  const base = { secret: SECRET, ...headers, tags: ["membership-seasons"], nowSeconds: NOW };
  assert.equal(verifyRevalidationV2({ ...base, tags: ["membership-types"] }).ok, false);
  assert.equal(verifyRevalidationV2({ ...base, nonce: "f".repeat(32) }).ok, false);
  assert.equal(verifyRevalidationV2({ ...base, timestamp: String(NOW + 1) }).ok, false);
  assert.equal(verifyRevalidationV2({ ...base, secret: "someone-elses-secret" }).ok, false);
});

test("v1 and v2 signatures are not interchangeable (the v2. prefix separates the schemes)", () => {
  const timestamp = String(NOW);
  // a legacy signature for a tag, presented as v2
  const legacy = signRevalidation(SECRET, timestamp, "membership-seasons");
  assert.equal(verifyRevalidationV2({ secret: SECRET, timestamp, nonce: NONCE, signature: legacy, tags: ["membership-seasons"], nowSeconds: NOW }).ok, false);
  // and a v2 signature presented to the legacy path
  const current = signRevalidationV2(SECRET, timestamp, NONCE, ["membership-seasons"]);
  const result = decide({ headers: { timestamp, nonce: null, signature: current }, body: { tag: "membership-seasons" } });
  assert.deepEqual(result, { ok: false, status: 401 });
});

test("a stale or far-future v2 request, a missing field, a bad nonce and a garbage signature are all clean rejections", () => {
  const stale = String(NOW - REVALIDATE_MAX_SKEW_SECONDS - 1);
  const old = v2(["membership-fees"], { timestamp: stale });
  assert.deepEqual(verifyRevalidationV2({ secret: SECRET, ...old.headers, tags: ["membership-fees"], nowSeconds: NOW }), { ok: false, reason: "stale" });

  const fresh = v2(["membership-fees"]);
  assert.deepEqual(verifyRevalidationV2({ secret: SECRET, ...fresh.headers, nonce: null, tags: ["membership-fees"], nowSeconds: NOW }), { ok: false, reason: "missing" });
  for (const nonce of ["", "short", "has spaces in it and is long enough!!", "x".repeat(65), "ünïcödé-ünïcödé-ünïcödé-ünïcödé"]) {
    assert.equal(NONCE_PATTERN.test(nonce), false);
    assert.equal(verifyRevalidationV2({ secret: SECRET, ...fresh.headers, nonce, tags: ["membership-fees"], nowSeconds: NOW }).ok, false, nonce);
  }
  for (const garbage of ["", "zz", "00", "g".repeat(64), "a".repeat(63), "a".repeat(200)]) {
    assert.doesNotThrow(() => verifyRevalidationV2({ secret: SECRET, ...fresh.headers, signature: garbage, tags: ["membership-fees"], nowSeconds: NOW }));
    assert.equal(verifyRevalidationV2({ secret: SECRET, ...fresh.headers, signature: garbage, tags: ["membership-fees"], nowSeconds: NOW }).ok, false);
  }
});

// ---------------------------------------------------------------------------
// replay protection
// ---------------------------------------------------------------------------

test("a nonce is accepted once and refused every time after, a different one is fine", () => {
  const guard = createReplayGuard();
  assert.equal(guard.claim("a".repeat(32), NOW), true);
  assert.equal(guard.claim("a".repeat(32), NOW), false);
  assert.equal(guard.claim("a".repeat(32), NOW + 5), false);
  assert.equal(guard.claim("b".repeat(32), NOW), true);
});

test("a nonce is remembered for as long as a request carrying it could still pass the timestamp check, and no longer", () => {
  const guard = createReplayGuard();
  const edge = NOW + REVALIDATE_MAX_SKEW_SECONDS * 2; // a request signed at NOW stops passing the timestamp check 300 s later, a future-dated one 300 s earlier
  assert.equal(guard.claim("c".repeat(32), NOW), true);
  assert.equal(guard.claim("c".repeat(32), edge), false, "still remembered when the oldest-possible replay would still verify");
  assert.equal(guard.claim("c".repeat(32), NOW + REVALIDATE_MAX_SKEW_SECONDS * 2 + 31), true, "forgotten once no replay of it could verify any more");
});

test("the memory is bounded: the oldest nonces go first", () => {
  const guard = createReplayGuard({ maxEntries: 3 });
  for (const n of ["1", "2", "3"]) assert.equal(guard.claim(n.repeat(20), NOW), true);
  assert.equal(guard.claim("4".repeat(20), NOW), true);
  assert.equal(guard.claim("4".repeat(20), NOW), false, "the newest is remembered");
  assert.equal(guard.claim("1".repeat(20), NOW), true, "the oldest was evicted to make room");
});

// ---------------------------------------------------------------------------
// the policy: what a request may do
// ---------------------------------------------------------------------------

test("every tag on the allow-list is accepted, in one signed call, and comes back sorted", () => {
  const everything = [CAROUSEL_CACHE_TAG, ...Object.values(MEMBERSHIP_CACHE_TAGS)];
  assert.equal(everything.length, 5);
  const result = decide(v2([...everything].reverse()));
  assert.deepEqual(result, { ok: true, tags: canonicalTags(everything), scheme: "v2" });
  assert.deepEqual([...ALLOWED_REVALIDATE_TAGS].sort(), canonicalTags(everything), "the allow-list is exactly the carousel tag and the four membership tags");
});

test("one tag the site does not know refuses the WHOLE request — nothing is half-applied", () => {
  const result = decide(v2(["membership-seasons", "notices"]));
  assert.deepEqual(result, { ok: false, status: 400 });
});

test("an unknown tag is only judged after the caller has proved who it is", () => {
  const forged = v2(["notices"], { secret: "not-the-secret" });
  assert.deepEqual(decide(forged), { ok: false, status: 401 }, "an unauthenticated caller learns nothing about which tags exist");
});

test("tags changed after signing are refused", () => {
  const signed = v2(["membership-fees"]);
  assert.deepEqual(decide({ headers: signed.headers, body: { tags: ["membership-types"] } }), { ok: false, status: 401 });
  assert.deepEqual(decide({ headers: signed.headers, body: { tags: ["membership-fees", "membership-types"] } }), { ok: false, status: 401 });
});

test("a captured request replayed is refused — and a failed attempt does not burn the nonce", () => {
  const guard = createReplayGuard();
  const real = v2(["membership-seasons"]);

  // an attacker (or a bug) sends the nonce with a bad signature first: refused, and the nonce stays usable
  const bad = { headers: { ...real.headers, signature: "0".repeat(64) }, body: real.body };
  assert.deepEqual(decide(bad, guard), { ok: false, status: 401 });

  assert.deepEqual(decide(real, guard), { ok: true, tags: ["membership-seasons"], scheme: "v2" });
  assert.deepEqual(decide(real, guard), { ok: false, status: 401 }, "the identical request a second time is a replay");
  assert.deepEqual(decide(v2(["membership-seasons"], { nonce: "9".repeat(32) }), guard), { ok: true, tags: ["membership-seasons"], scheme: "v2" }, "a new call with a new nonce is fine");
});

test("the body must have the right shape, and the shape is checked before the signature", () => {
  const ok = v2(["membership-fees"]);
  const withBody = (body: unknown) => decide({ headers: ok.headers, body });

  assert.deepEqual(withBody(null), { ok: false, status: 400 });
  assert.deepEqual(withBody("tags"), { ok: false, status: 400 });
  assert.deepEqual(withBody([]), { ok: false, status: 400 });
  assert.deepEqual(withBody({}), { ok: false, status: 400 });
  assert.deepEqual(withBody({ tags: [] }), { ok: false, status: 400 });
  assert.deepEqual(withBody({ tags: "membership-fees" }), { ok: false, status: 400 });
  assert.deepEqual(withBody({ tags: ["membership-fees", 7] }), { ok: false, status: 400 });
  const many = Array.from({ length: MAX_TAGS_PER_REQUEST + 1 }, (_, i) => `t${i}`);
  assert.deepEqual(withBody({ tags: many }), { ok: false, status: 400 });
});

test("without a configured secret nothing is ever accepted (503), however well formed", () => {
  const request = v2(["membership-fees"]);
  assert.deepEqual(decideRevalidation({ secret: undefined, ...request, replayGuard: createReplayGuard(), nowSeconds: NOW }), { ok: false, status: 503 });
  assert.deepEqual(decideRevalidation({ secret: "", ...request, replayGuard: createReplayGuard(), nowSeconds: NOW }), { ok: false, status: 503 });
});

// ---------------------------------------------------------------------------
// the legacy single-tag scheme still works (an un-updated admin-erp)
// ---------------------------------------------------------------------------

test("the legacy single-tag request is still accepted for an allowed tag, including a membership one", () => {
  for (const tag of [CAROUSEL_CACHE_TAG, MEMBERSHIP_CACHE_TAGS.seasons]) {
    const timestamp = String(NOW);
    const result = decide({ headers: { timestamp, nonce: null, signature: signRevalidation(SECRET, timestamp, tag) }, body: { tag } });
    assert.deepEqual(result, { ok: true, tags: [tag], scheme: "v1" });
  }
});

test("the legacy path keeps its checks: allow-list, signature, freshness, shape", () => {
  const timestamp = String(NOW);
  const legacy = (tag: unknown, signature: string, ts = timestamp) => decide({ headers: { timestamp: ts, nonce: null, signature }, body: { tag } });

  assert.deepEqual(legacy("notices", signRevalidation(SECRET, timestamp, "notices")), { ok: false, status: 400 }, "authentic but not allow-listed");
  assert.deepEqual(legacy(CAROUSEL_CACHE_TAG, signRevalidation("someone-else", timestamp, CAROUSEL_CACHE_TAG)), { ok: false, status: 401 });
  const stale = String(NOW - REVALIDATE_MAX_SKEW_SECONDS - 1);
  assert.deepEqual(legacy(CAROUSEL_CACHE_TAG, signRevalidation(SECRET, stale, CAROUSEL_CACHE_TAG), stale), { ok: false, status: 401 });
  assert.deepEqual(legacy(42, "00"), { ok: false, status: 400 });
});
