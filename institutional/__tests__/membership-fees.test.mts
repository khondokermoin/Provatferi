import { test, before, after, mock } from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { feeQuote, formatTaka, FEE_LABELS, isPositiveAmount } from "../lib/fees.ts";
import { getCurrentCampaigns, getMembershipTypes } from "../lib/api/membership.ts";

before(() => {
  process.env.LARAVEL_API_URL = "https://admin.example.test";
});
after(() => {
  globalThis.fetch = undefined as unknown as typeof fetch;
});
const json = (body: unknown) => new Response(JSON.stringify(body), { status: 200, headers: { "Content-Type": "application/json" } });

/* ---------------------------------------------------------------------------------------------- formatting */

test("a whole-taka fee reads ৳500 in English and ৳৫০০ in Bangla; a free tier reads ৳0 / ৳০, never 'Free' or blank", () => {
  assert.equal(formatTaka("500.00", "en"), "৳500");
  assert.equal(formatTaka("500.00", "bn"), "৳৫০০");
  assert.equal(formatTaka("0.00", "en"), "৳0");
  assert.equal(formatTaka("0.00", "bn"), "৳০");
  assert.equal(formatTaka("0", "en"), "৳0");
});

test("decimals appear only when there are some, thousands are grouped, leading zeros are dropped", () => {
  assert.equal(formatTaka("99.50", "en"), "৳99.50");
  assert.equal(formatTaka("99.5", "en"), "৳99.50");
  assert.equal(formatTaka("1500.00", "en"), "৳1,500");
  assert.equal(formatTaka("1500.00", "bn"), "৳১,৫০০");
  assert.equal(formatTaka("1234567.89", "en"), "৳1,234,567.89");
  assert.equal(formatTaka("007.00", "en"), "৳7");
  assert.equal(formatTaka("99999999.99", "en"), "৳99,999,999.99");
});

test("anything that is not a plain non-negative amount formats to null instead of being guessed at", () => {
  for (const bad of ["-5", "5e2", "abc", "", " ", "1.234", "123456789", "5,00", "৳500", undefined, null]) {
    assert.equal(formatTaka(bad as string, "en"), null, String(bad));
  }
});

test("isPositiveAmount: above zero only, without turning the amount into a float", () => {
  assert.equal(isPositiveAmount("200.00"), true);
  assert.equal(isPositiveAmount("0.50"), true);
  assert.equal(isPositiveAmount("0.00"), false);
  assert.equal(isPositiveAmount("0"), false);
  assert.equal(isPositiveAmount(null), false);
  assert.equal(isPositiveAmount("-5.00"), false, "a malformed (negative) amount is never positive");
  assert.equal(isPositiveAmount("abc1"), false);
});

test("the two fee labels exist in both languages", () => {
  assert.equal(FEE_LABELS.en.registration, "Registration fee");
  assert.equal(FEE_LABELS.en.monthly, "Monthly contribution");
  assert.equal(FEE_LABELS.bn.registration, "নিবন্ধন ফি");
  assert.equal(FEE_LABELS.bn.monthly, "মাসিক চাঁদা");
});

/* ---------------------------------------------------------------------------------------------- the quote */

test("a live response is quoted from registration_fee and monthly_contribution", () => {
  assert.deepEqual(feeQuote({ fee: "500.00", registration_fee: "500.00", monthly_contribution: "200.00" }), { registration: "500.00", monthly: "200.00" });
  assert.deepEqual(feeQuote({ fee: "0.00", registration_fee: "0.00", monthly_contribution: "0.00" }), { registration: "0.00", monthly: "0.00" });
});

test("a response cached from before fee policies existed still quotes the registration fee (via the deprecated `fee`) with no monthly figure", () => {
  assert.deepEqual(feeQuote({ fee: "100.00" }), { registration: "100.00", monthly: null });
});

test("a malformed amount is not quoted at all", () => {
  assert.equal(feeQuote({ fee: "free" }), null);
  assert.deepEqual(feeQuote({ fee: "0.00", registration_fee: "0.00", monthly_contribution: "lots" }), { registration: "0.00", monthly: null });
});

/* ---------------------------------------------------------------------------------------------- the API contract */

const live = {
  id: 2, name: "শিক্ষার্থী সদস্য", name_en: "Student Member", slug: "student", code: "ST", description: null, description_en: null, duration_months: null,
  fee: "0.00", registration_fee: "0.00", monthly_contribution: "0.00", fee_effective_from: "2026-10-05", is_student: true, is_public_self_apply: true,
};

test("the membership-types guard accepts the body admin-erp now sends, and the pre-change body", async () => {
  globalThis.fetch = mock.fn(async () => json({ data: [live] }));
  const now = await getMembershipTypes();
  assert.equal(now.ok, true, JSON.stringify(now));
  if (now.ok) assert.equal(now.data[0].registration_fee, "0.00");

  const { registration_fee, monthly_contribution, fee_effective_from, code, ...old } = live;
  void registration_fee; void monthly_contribution; void fee_effective_from; void code;
  globalThis.fetch = mock.fn(async () => json({ data: [old] }));
  assert.equal((await getMembershipTypes()).ok, true, "a response cached before the change is still accepted");
});

test("a present-but-malformed fee field is rejected like any other malformed field", async () => {
  for (const bad of [{ registration_fee: "-1.00" }, { monthly_contribution: 5 }, { registration_fee: "1e3" }, { code: 7 }]) {
    globalThis.fetch = mock.fn(async () => json({ data: [{ ...live, ...bad }] }));
    assert.equal((await getMembershipTypes()).ok, false, JSON.stringify(bad));
  }
});

test("the campaign guard accepts the fee fields on a nested type", async () => {
  const campaign = {
    id: 1, name: "সিজন", name_en: null, slug: "s", campaign_type: "regular", opens_at: null, closes_at: null, description: null,
    cash_payment_instructions: null, public_profile_opt_in: false,
    membership_types: [{ ...live, is_public_self_apply: undefined }],
  };
  globalThis.fetch = mock.fn(async () => json({ data: [campaign] }));
  const result = await getCurrentCampaigns();
  assert.equal(result.ok, true, JSON.stringify(result));
  if (result.ok) assert.equal(result.data[0].membership_types[0].monthly_contribution, "0.00");
});

/* ---------------------------------------------------------------------------------------------- the screens */

test("the form and the type cards read the fees from the API quote, with no hardcoded amounts, no 'Free' wording and no payment widget", () => {
  // Comments may legitimately mention "৳0" or "Free" while explaining the rule; only the code itself is held to it.
  const code = (url: string) =>
    readFileSync(new URL(url, import.meta.url), "utf8")
      .replace(/\{?\/\*[\s\S]*?\*\/\}?/g, "")
      .replace(/(^|\s)\/\/.*$/gm, "$1");
  const form = code("../components/MembershipApplicationForm.tsx");
  const page = code("../app/[locale]/(site)/membership/page.tsx");
  for (const [name, source] of [["form", form], ["page", page]] as const) {
    assert.match(source, /feeQuote\(/, `${name} must quote through feeQuote()`);
    assert.match(source, /formatTaka\(/, `${name} must format through formatTaka()`);
    assert.doesNotMatch(source, /৳\s*\d/, `${name} must not contain a hardcoded amount`);
    assert.doesNotMatch(source, /বিনামূল্যে|"— Free"|\bFree\b/, `${name} must show ৳0, never "Free"`);
  }
  assert.doesNotMatch(form, /Number\(type\.fee\)/, "the form must not decide anything from the flat fee any more");
});
