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
