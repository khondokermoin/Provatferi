import { test, before, after, mock } from "node:test";
import assert from "node:assert/strict";
import { getCurrentCampaigns, getMembershipTypes, isPastValidity, MEMBERSHIP_CACHE_TAGS } from "../membership.ts";

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

// ---------------------------------------------------------------------------
// 2026-10-05, the public membership cache: tags, the safety-net window, and `valid_until`
// ---------------------------------------------------------------------------

type FetchInit = RequestInit & { next?: { revalidate?: number; tags?: string[] } };
const initOf = (fetchMock: { mock: { calls: { arguments: unknown[] }[] } }, call: number): FetchInit => fetchMock.mock.calls[call].arguments[1] as FetchInit;
const inSeconds = (s: number) => new Date(Date.now() + s * 1000).toISOString();

const typeRow = { ...validType, fee: "100.00", registration_fee: "100.00", monthly_contribution: "0.00", fee_effective_from: "2026-10-05" };

test("the type list and the season lookup are fetched cached, with their membership tags and a short safety-net window", async () => {
  const fetchMock = mock.fn(async () => jsonResponse({ data: [], meta: { valid_until: null } }));
  globalThis.fetch = fetchMock;

  await getMembershipTypes();
  await getCurrentCampaigns();

  const types = initOf(fetchMock, 0);
  assert.deepEqual(types.next?.tags, [MEMBERSHIP_CACHE_TAGS.all, MEMBERSHIP_CACHE_TAGS.types, MEMBERSHIP_CACHE_TAGS.fees]);
  assert.equal(types.next?.revalidate, 15, "a short safety net: a time-to-live alone can never be right on the first request after a change");
  const campaigns = initOf(fetchMock, 1);
  assert.deepEqual(campaigns.next?.tags, [MEMBERSHIP_CACHE_TAGS.all, MEMBERSHIP_CACHE_TAGS.seasons, MEMBERSHIP_CACHE_TAGS.types, MEMBERSHIP_CACHE_TAGS.fees]);
  assert.equal(campaigns.next?.revalidate, 15);
  assert.equal(fetchMock.mock.callCount(), 2, "nothing was past its validity, so nothing was asked twice");
});

test("a cached answer still inside its validity is used as it is — one request", async () => {
  const fetchMock = mock.fn(async () => jsonResponse({ data: [typeRow], meta: { valid_until: inSeconds(3600), generated_at: inSeconds(-1) } }));
  globalThis.fetch = fetchMock;

  const result = await getMembershipTypes();

  assert.equal(result.ok, true);
  assert.equal(fetchMock.mock.callCount(), 1);
});

test("a cached answer past its valid_until is NOT used: Laravel is asked again, uncached, and that answer wins", async () => {
  let call = 0;
  const fetchMock = mock.fn(async () => {
    call += 1;
    // 1st = the (stale) cached copy: names an instant that has passed; 2nd = Laravel's current answer
    return call === 1
      ? jsonResponse({ data: [], meta: { valid_until: inSeconds(-30) } })
      : jsonResponse({ data: [{ id: 5, name: "চলমান সিজন", name_en: null, slug: "s", campaign_type: "regular", opens_at: null, closes_at: null, description: null, cash_payment_instructions: null, public_profile_opt_in: true, membership_types: [typeRow] }], meta: { valid_until: inSeconds(600) } });
  });
  globalThis.fetch = fetchMock;

  const result = await getCurrentCampaigns();

  assert.equal(result.ok, true);
  if (result.ok) assert.equal(result.data.length, 1, "the season that opened by the clock is there on the very first request");
  assert.equal(fetchMock.mock.callCount(), 2);
  assert.equal(initOf(fetchMock, 0).cache, undefined, "the first look goes through the cache");
  assert.equal(initOf(fetchMock, 1).cache, "no-store", "the second does not");
  assert.equal(initOf(fetchMock, 1).next, undefined);
});

test("an unreadable valid_until counts as expired: when in doubt, ask Laravel", async () => {
  const fetchMock = mock.fn(async () => jsonResponse({ data: [], meta: { valid_until: "not a date" } }));
  globalThis.fetch = fetchMock;

  await getCurrentCampaigns();

  assert.equal(fetchMock.mock.callCount(), 2);
});

test("an answer with no meta at all (admin-erp not yet updated) is accepted and used as before", async () => {
  const fetchMock = mock.fn(async () => jsonResponse({ data: [] }));
  globalThis.fetch = fetchMock;

  const result = await getCurrentCampaigns();

  assert.equal(result.ok, true);
  assert.equal(fetchMock.mock.callCount(), 1);
});

test("a malformed meta is rejected like any other malformed field", async () => {
  globalThis.fetch = mock.fn(async () => jsonResponse({ data: [], meta: { valid_until: 12345 } }));
  const bad = await getCurrentCampaigns();
  assert.equal(bad.ok, false);
  if (!bad.ok) assert.equal(bad.error, "invalid_shape");

  globalThis.fetch = mock.fn(async () => jsonResponse({ data: [], meta: "soon" }));
  assert.equal((await getMembershipTypes()).ok, false);
});

test("a failed lookup is not retried — the page falls back exactly as it always has", async () => {
  const fetchMock = mock.fn(async () => jsonResponse({ message: "down" }, 503));
  globalThis.fetch = fetchMock;

  const result = await getCurrentCampaigns();

  assert.equal(result.ok, false);
  assert.equal(fetchMock.mock.callCount(), 1, "a retry would only double the wait during an outage");
});

test("isPastValidity: null and absent mean nothing is scheduled; a past or unreadable instant means expired", () => {
  const now = Date.parse("2026-10-05T12:00:00+00:00");
  assert.equal(isPastValidity(undefined, now), false);
  assert.equal(isPastValidity({}, now), false);
  assert.equal(isPastValidity({ valid_until: null }, now), false);
  assert.equal(isPastValidity({ valid_until: "2026-10-05T12:00:01+00:00" }, now), false, "one second before: still valid");
  assert.equal(isPastValidity({ valid_until: "2026-10-05T12:00:00+00:00" }, now), true, "AT the instant it is already expired");
  assert.equal(isPastValidity({ valid_until: "2026-10-05T11:59:59+00:00" }, now), true);
  assert.equal(isPastValidity({ valid_until: "garbage" }, now), true);
  assert.equal(isPastValidity({ valid_until: "2026-10-05T18:00:00+00:00" }, now), false, "a different offset spelling of a later instant");
});

test("the tags are exactly the names admin-erp's PublicSiteRevalidator sends", () => {
  assert.deepEqual({ ...MEMBERSHIP_CACHE_TAGS }, { all: "membership", seasons: "membership-seasons", types: "membership-types", fees: "membership-fees" });
});
