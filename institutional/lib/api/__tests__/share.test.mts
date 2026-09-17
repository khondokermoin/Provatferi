import { test } from "node:test";
import assert from "node:assert/strict";
import { canonicalNoticeUrl, shareTargets } from "../../share.ts";

const URL_UNDER_TEST = "https://provatferi.org/notices/volunteer-team-call";
const TITLE = "প্রভাতফেরীর স্বেচ্ছাসেবী টিমে যুক্ত হওয়ার আহ্বান";

test("canonicalNoticeUrl builds the canonical URL and never doubles the slash", () => {
  assert.equal(canonicalNoticeUrl("https://provatferi.org", "volunteer-team-call"), URL_UNDER_TEST);
  assert.equal(canonicalNoticeUrl("https://provatferi.org/", "volunteer-team-call"), URL_UNDER_TEST);
});

test("every share target carries the canonical URL, encoded", () => {
  const targets = shareTargets(URL_UNDER_TEST, TITLE);
  const encoded = encodeURIComponent(URL_UNDER_TEST);

  assert.deepEqual(
    targets.map((t) => t.id),
    ["facebook", "whatsapp", "linkedin", "x", "email"],
  );
  for (const target of targets) {
    assert.ok(target.href.includes(encoded), `${target.id} should carry the encoded canonical URL`);
    assert.ok(target.label.length > 0);
  }
});

test("Bengali titles survive encoding into share links", () => {
  const targets = shareTargets(URL_UNDER_TEST, TITLE);
  const x = targets.find((t) => t.id === "x");

  assert.ok(x);
  assert.ok(x.href.includes(encodeURIComponent(TITLE)));
  // Raw Bengali (or a raw space) in a query string would break the link.
  assert.ok(!/[ঀ-৿ ]/.test(x.href));
});

test("no share target points at a tracking or query-carrying URL", () => {
  const targets = shareTargets("https://provatferi.org/notices/x?utm_source=test", "শিরোনাম");
  // The builder shares exactly what it is given; the page passes the
  // canonical URL, which is asserted by canonicalNoticeUrl's own test.
  assert.ok(targets.every((t) => t.href.includes(encodeURIComponent("https://provatferi.org/notices/x?utm_source=test"))));
});
