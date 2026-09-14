import { test } from "node:test";
import assert from "node:assert/strict";
import { dhakaIsoDate, formatBnDate, formatFileSizeBn, toBnDigits } from "../../format.ts";

test("an evening UTC timestamp is already the next day in Bangladesh", () => {
  assert.equal(formatBnDate("2026-09-14T19:30:00+00:00"), "১৫ সেপ্টেম্বর ২০২৬");
  assert.equal(dhakaIsoDate("2026-09-14T19:30:00+00:00"), "2026-09-15");
});

test("a bare calendar date (e.g. a deadline) is shown as that date, never shifted", () => {
  assert.equal(formatBnDate("2026-12-31"), "৩১ ডিসেম্বর ২০২৬");
  assert.equal(dhakaIsoDate("2026-12-31"), "2026-12-31");
});

test("the format matches admin-erp's bn_date(): Bengali digits, full month name, no comma", () => {
  assert.equal(formatBnDate("2026-01-05T06:00:00+00:00"), "৫ জানুয়ারি ২০২৬");
});

test("missing or unparseable dates render nothing rather than 'Invalid Date'", () => {
  assert.equal(formatBnDate(null), null);
  assert.equal(formatBnDate(""), null);
  assert.equal(formatBnDate("not-a-date"), null);
  assert.equal(dhakaIsoDate(undefined), null);
});

test("digits and file sizes use Bengali numerals", () => {
  assert.equal(toBnDigits("2026"), "২০২৬");
  assert.equal(formatFileSizeBn(2_500_000), "২.৪ MB");
  assert.equal(formatFileSizeBn(850_000), "৮৩০ KB");
  assert.equal(formatFileSizeBn(0), null);
  assert.equal(formatFileSizeBn(null), null);
});
