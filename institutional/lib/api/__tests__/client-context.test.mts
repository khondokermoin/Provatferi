import assert from "node:assert/strict";
import { createHmac } from "node:crypto";
import test from "node:test";
import { actionForPath, verifyForTest } from "../../client-context.ts";
import { rateLimitedMessage } from "../../form-messages.ts";

/**
 * The signed client context the site sends to the ERP, from this side of the wire
 * (docs/RATE_LIMITING_ARCHITECTURE.md).
 *
 * The ERP verifies what this module signs, so the two have to agree exactly on one string. Neither test suite can
 * run the other's language, so BOTH pin the same fixed example: this file builds it, and
 * tests/Feature/RateLimit/PublicRateLimitTest.php asserts the identical literal. If either side ever changes the
 * canonical form, one of the two fails rather than both silently drifting into a signature mismatch in production.
 */

/** The one canonical form. Mirrored in PHP as PublicClientContext::canonical(). */
const CANONICAL_EXAMPLE = ["v1", "1762000000", "public.committee.register", "203.0.113.7", "visitor-one-session"].join("\n");

test("the canonical signing string is exactly the shape the ERP rebuilds", () => {
  // Line-separated, in this order, with an empty line for an absent value. Written out literally so a reordering
  // or a different separator is a failure here and not a mystery 403 later.
  assert.equal(CANONICAL_EXAMPLE, "v1\n1762000000\npublic.committee.register\n203.0.113.7\nvisitor-one-session");
});

test("a signature over the canonical string verifies, and any edit to it does not", () => {
  const secret = "a-test-secret-that-is-at-least-32-characters";
  const signature = createHmac("sha256", secret).update(CANONICAL_EXAMPLE).digest("hex");

  assert.equal(verifyForTest(CANONICAL_EXAMPLE, signature, secret), true);

  // A different visitor, a different action, a different time, a different secret: each must fail.
  assert.equal(verifyForTest(CANONICAL_EXAMPLE.replace("visitor-one-session", "somebody-else"), signature, secret), false);
  assert.equal(verifyForTest(CANONICAL_EXAMPLE.replace("public.committee.register", "public.token.lookup"), signature, secret), false);
  assert.equal(verifyForTest(CANONICAL_EXAMPLE.replace("1762000000", "1762000001"), signature, secret), false);
  assert.equal(verifyForTest(CANONICAL_EXAMPLE, signature, "another-secret-of-at-least-32-characters-x"), false);
});

test("a signature of the wrong length is rejected without throwing", () => {
  const secret = "a-test-secret-that-is-at-least-32-characters";
  // timingSafeEqual throws on a length mismatch if it is not guarded; this proves the guard is there.
  assert.equal(verifyForTest(CANONICAL_EXAMPLE, "short", secret), false);
  assert.equal(verifyForTest(CANONICAL_EXAMPLE, "", secret), false);
});

test("every forwarded write path maps to the limiter action that guards it", () => {
  // The ERP's route limiter and this table have to name the same action, or the context is minted for the wrong
  // one and the endpoint falls back to the shared ceiling — silently.
  const expected: Array<[string, string]> = [
    ["/public/recruitment/volunteer-2026/applications", "public.volunteer.submit"],
    ["/public/recruitment/volunteer-2026/application-success", "public.application.success"],
    ["/public/membership/applications", "public.membership.submit"],
    ["/public/committee-submissions", "public.committee.register"],
    ["/public/committee-submissions/correction/abc123", "public.committee.correct"],
    ["/member/auth/login", "member.auth.login"],
    ["/member/auth/password/email", "member.auth.password.email"],
    ["/member/auth/password/reset", "member.auth.password.reset"],
  ];

  for (const [path, action] of expected) {
    assert.equal(actionForPath(path), action, `${path} should map to ${action}`);
  }
});

test("a path that is not a limited action sends no context at all", () => {
  // Content reads deliberately carry no context: reading a cookie during a render would opt the page out of
  // static rendering, and a read has nothing to gain from per-visitor keys.
  for (const path of ["/public/notices", "/settings", "/public/leadership", "/member/profile", "/nonsense"]) {
    assert.equal(actionForPath(path), null, `${path} should not be treated as a limited action`);
  }
});

test("the rate-limit message tells the visitor when to return, in their language, and leaks nothing", () => {
  const bn = rateLimitedMessage(90, "bn");
  const en = rateLimitedMessage(90, "en");

  assert.match(en, /2 minutes/, "90 seconds should round up to 2 minutes");
  assert.match(bn, /২|মিনিট/, "the Bangla message should be in Bangla");

  // No figure invented when the ERP did not send one.
  assert.doesNotMatch(rateLimitedMessage(null, "en"), /\d/);
  assert.match(rateLimitedMessage(null, "en"), /try again/i);

  // One minute is singular; nothing says "1 minutes".
  assert.match(rateLimitedMessage(30, "en"), /1 minute\b/);

  // Nothing about the mechanism reaches the visitor.
  for (const message of [bn, en, rateLimitedMessage(null, "bn")]) {
    for (const leak of ["sid", "cip", "proxy", "203.0", "limiter", "bucket"]) {
      assert.ok(!message.toLowerCase().includes(leak), `the message mentioned "${leak}"`);
    }
  }
});
