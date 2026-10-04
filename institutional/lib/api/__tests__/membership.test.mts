import { test, before, after, mock } from "node:test";
import assert from "node:assert/strict";
import { getCurrentCampaigns } from "../membership.ts";

before(() => {
  process.env.LARAVEL_API_URL = "https://admin.example.test";
});

after(() => {
  globalThis.fetch = undefined as unknown as typeof fetch;
});

function jsonResponse(body: unknown, status = 200): Response {
  return new Response(JSON.stringify(body), { status, headers: { "Content-Type": "application/json" } });
}

const validType = {
  id: 1, name: "সাধারণ সদস্য", slug: "general", description: null,
  duration_months: 12, fee: "500.00", is_student: false, is_public_self_apply: true,
};

test("getCurrentCampaigns returns an empty array (not an error) when nothing is open", async () => {
  globalThis.fetch = mock.fn(async () => jsonResponse({ data: [] }));

  const result = await getCurrentCampaigns();
  assert.equal(result.ok, true);
  if (result.ok) assert.deepEqual(result.data, []);
});

test("getCurrentCampaigns can return more than one campaign at once — a regular season and a special drive running in parallel", async () => {
  globalThis.fetch = mock.fn(async () =>
    jsonResponse({
      data: [
        {
          id: 1, name: "নিয়মিত সিজন", name_en: null, slug: "regular", campaign_type: "regular",
          opens_at: null, closes_at: null, description: null, cash_payment_instructions: null,
          public_profile_opt_in: true, membership_types: [validType],
        },
        {
          id: 2, name: "বিশেষ অভিযান", name_en: "Special Drive", slug: "special", campaign_type: "special",
          opens_at: null, closes_at: null, description: null, cash_payment_instructions: "নগদে পরিশোধ করুন।",
          public_profile_opt_in: false, membership_types: [],
        },
      ],
    }),
  );

  const result = await getCurrentCampaigns();
  assert.equal(result.ok, true);
  if (result.ok) {
    assert.equal(result.data.length, 2);
    assert.equal(result.data[1].campaign_type, "special");
  }
});

/**
 * The body admin-erp's MembershipCampaignController::publicPayload() really sends — copied from a live response. Its
 * nested membership types carry no `is_public_self_apply` (the endpoint already returns only public self-apply types)
 * and no `name_en` / `description_en`. The fixture above adds the flag, which is why the guard once required it,
 * passed every test, and rejected every real open season — the application form never rendered.
 */
const LARAVEL_CAMPAIGN_BODY = {
  data: [
    {
      id: 2, name: "QA UPLOAD TEST qaa6b882 (ignore)", name_en: "QA UPLOAD TEST qaa6b882 (ignore)", slug: "qa-upload-season-qaa6b882", campaign_type: "regular",
      opens_at: "2026-10-04T09:40:15.000000Z", closes_at: "2026-10-04T10:26:15.000000Z", description: null,
      cash_payment_instructions: "QA — ignore.", public_profile_opt_in: false,
      membership_types: [{ id: 2, name: "QA UPLOAD TEST qaa6b882 type", slug: "qa-upload-type-qaa6b882", description: null, duration_months: null, fee: "0.00", is_student: false }],
    },
  ],
};

test("getCurrentCampaigns accepts exactly what Laravel's campaigns endpoint sends, so an open season really renders the form", async () => {
  globalThis.fetch = mock.fn(async () => jsonResponse(LARAVEL_CAMPAIGN_BODY));

  const result = await getCurrentCampaigns();
  assert.equal(result.ok, true, JSON.stringify(result));
  if (result.ok) {
    assert.equal(result.data.length, 1);
    assert.equal(result.data[0].membership_types[0].name, "QA UPLOAD TEST qaa6b882 type");
    assert.equal(result.data[0].membership_types[0].is_public_self_apply, undefined, "the flag is implied, not sent");
  }
});

test("a campaign type may carry the flag, but only as a boolean", async () => {
  const withFlag = (flag: unknown) => ({ data: [{ ...LARAVEL_CAMPAIGN_BODY.data[0], membership_types: [{ ...LARAVEL_CAMPAIGN_BODY.data[0].membership_types[0], is_public_self_apply: flag }] }] });
  globalThis.fetch = mock.fn(async () => jsonResponse(withFlag(true)));
  assert.equal((await getCurrentCampaigns()).ok, true);
  globalThis.fetch = mock.fn(async () => jsonResponse(withFlag("yes")));
  const bad = await getCurrentCampaigns();
  assert.equal(bad.ok, false);
});

test("getCurrentCampaigns rejects a campaign whose nested membership_types entry is malformed", async () => {
  globalThis.fetch = mock.fn(async () =>
    jsonResponse({
      data: [
        {
          id: 1, name: "সিজন", name_en: null, slug: "x", campaign_type: "regular",
          opens_at: null, closes_at: null, description: null, cash_payment_instructions: null,
          public_profile_opt_in: true, membership_types: [{ id: 1, name: "Missing fields" }],
        },
      ],
    }),
  );

  const result = await getCurrentCampaigns();
  assert.equal(result.ok, false);
  if (!result.ok) assert.equal(result.error, "invalid_shape");
});
