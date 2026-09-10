import { test, before, after, mock } from "node:test";
import assert from "node:assert/strict";
import { getActivity, getActivities, getActivitiesWithFallback } from "../activities.ts";
import { recentActivities } from "../../content.ts";

before(() => {
  process.env.LARAVEL_API_URL = "https://admin.example.test";
});

after(() => {
  globalThis.fetch = undefined as unknown as typeof fetch;
});

function jsonResponse(body: unknown, status = 200): Response {
  return new Response(JSON.stringify(body), { status, headers: { "Content-Type": "application/json" } });
}

const sampleActivity = {
  id: 1,
  activity_type_id: 3,
  organization_unit_id: 1,
  title: "মাদকবিরোধী অভিযান",
  slug: "madokbirodhi-ovijan-2026-08-18",
  summary: null,
  description: null,
  objective: null,
  venue: "লেবাশ ও দোল্লাই নোয়াবপুর",
  address: null,
  hero_image_path: null,
  what_happened: null,
  outcomes: null,
  gallery: [],
  related_links: [],
  facebook_post_url: null,
  start_datetime: "2026-08-18T00:00:00.000000Z",
  end_datetime: null,
  featured: false,
  participant_count: 0,
  published_at: "2026-08-18T00:00:00.000000Z",
  type: { id: 3, name: "সামাজিক সচেতনতা", slug: "social-awareness" },
  // Snake_case, matching what Eloquent's relationsToArray() actually puts on
  // the wire (Str::snake() runs on the relation name regardless of the
  // camelCase method used server-side) — a first draft of the Activity type
  // had this as `organizationUnit` and would have rejected every real row;
  // caught by checking the live response during the cutover, not by this
  // test alone, but this test is what keeps it caught going forward.
  organization_unit: { id: 1, name: "প্রভাতফেরী সাহিত্য ও সাংস্কৃতিক কেন্দ্র", slug: "provatferi-central" },
};

test("getActivity maps a slug containing dashes and digits into the correct URL-encoded path segment", async () => {
  // eslint-disable-next-line @typescript-eslint/no-unused-vars -- params exist only so TS types the captured call arguments below
  const fetchMock = mock.fn(async (_url: RequestInfo | URL, _init?: RequestInit) => jsonResponse({ data: sampleActivity }));
  globalThis.fetch = fetchMock;

  const result = await getActivity("madokbirodhi-ovijan-2026-08-18");
  assert.equal(result.ok, true);

  const calledUrl = fetchMock.mock.calls[0].arguments[0] as string;
  assert.equal(calledUrl, "https://admin.example.test/api/v1/activities/madokbirodhi-ovijan-2026-08-18");
});

test("getActivity percent-encodes a slug containing characters that would otherwise break the path", async () => {
  // eslint-disable-next-line @typescript-eslint/no-unused-vars -- params exist only so TS types the captured call arguments below
  const fetchMock = mock.fn(async (_url: RequestInfo | URL, _init?: RequestInit) => jsonResponse({ data: sampleActivity }));
  globalThis.fetch = fetchMock;

  await getActivity("a slug/with?odd&chars");
  const calledUrl = fetchMock.mock.calls[0].arguments[0] as string;
  assert.equal(calledUrl, "https://admin.example.test/api/v1/activities/a%20slug%2Fwith%3Fodd%26chars");
});

test("getActivity accepts a real-shaped record (type + organization_unit populated) and preserves Bangla text exactly", async () => {
  globalThis.fetch = mock.fn(async () => jsonResponse({ data: sampleActivity }));
  const result = await getActivity("madokbirodhi-ovijan-2026-08-18");
  assert.equal(result.ok, true);
  if (result.ok) {
    assert.equal(result.data.title, "মাদকবিরোধী অভিযান");
    assert.equal(result.data.organization_unit?.name, "প্রভাতফেরী সাহিত্য ও সাংস্কৃতিক কেন্দ্র");
    assert.equal(result.data.type?.name, "সামাজিক সচেতনতা");
  }
});

test("getActivities rejects a malformed activity record (organization_unit missing its name field) as invalid_shape", async () => {
  const malformed = { ...sampleActivity, organization_unit: { id: 1, slug: "provatferi-central" } };
  globalThis.fetch = mock.fn(async () => jsonResponse({ data: [malformed], total: 1, current_page: 1, last_page: 1 }));
  const result = await getActivities();
  assert.equal(result.ok, false);
  if (!result.ok) assert.equal(result.error, "invalid_shape");
});

test("getActivities rejects a response missing the pagination envelope (data present, total/current_page/last_page absent) as invalid_shape", async () => {
  globalThis.fetch = mock.fn(async () => jsonResponse({ data: [] }));
  const result = await getActivities();
  assert.equal(result.ok, false);
  if (!result.ok) assert.equal(result.error, "invalid_shape");
});

test("getActivities accepts a well-formed empty page", async () => {
  globalThis.fetch = mock.fn(async () => jsonResponse({ data: [], total: 0, current_page: 1, last_page: 1 }));
  const result = await getActivities();
  assert.equal(result.ok, true);
  if (result.ok) assert.equal(result.data.total, 0);
});

test("getActivities accepts the real 3/3 production shape and preserves published-only fields only — no status/coordinator_id/created_by in the contract", async () => {
  const three = [sampleActivity, { ...sampleActivity, id: 2, slug: "poribesh-porichonnota-kormosuchi-2026-09-04" }, { ...sampleActivity, id: 3, slug: "sochetonota-mulok-kormosuchi-2026-09-05" }];
  globalThis.fetch = mock.fn(async () => jsonResponse({ data: three, total: 3, current_page: 1, last_page: 1 }));
  const result = await getActivities();
  assert.equal(result.ok, true);
  if (result.ok) {
    assert.equal(result.data.total, 3);
    assert.equal(result.data.data.length, 3);
    for (const activity of result.data.data) {
      assert.ok(!("status" in activity), "public contract must never expose the publication-workflow status field");
      assert.ok(!("coordinator_id" in activity), "public contract must never expose coordinator_id");
      assert.ok(!("created_by" in activity), "public contract must never expose created_by");
    }
  }
});

// --- getActivitiesWithFallback: the single source page.tsx / [slug]/page.tsx / sitemap.ts all read from ---

test("getActivitiesWithFallback uses the API and normalizes venue/type/start_datetime into place/category/date when the API returns real data", async () => {
  globalThis.fetch = mock.fn(async () => jsonResponse({ data: [sampleActivity], total: 1, current_page: 1, last_page: 1 }));
  const { activities, source } = await getActivitiesWithFallback();
  assert.equal(source, "api");
  assert.equal(activities.length, 1);
  assert.equal(activities[0].place, "লেবাশ ও দোল্লাই নোয়াবপুর");
  assert.equal(activities[0].category, "সামাজিক সচেতনতা");
  assert.equal(activities[0].date, "১৮ আগস্ট, ২০২৬"); // human-readable Bengali, matching lib/content.ts's own date format
});

test("getActivitiesWithFallback falls back to lib/content.ts when the API returns a well-formed but empty list — an ERP outage or an empty table must not remove indexed activity pages", async () => {
  globalThis.fetch = mock.fn(async () => jsonResponse({ data: [], total: 0, current_page: 1, last_page: 1 }));
  const { activities, source } = await getActivitiesWithFallback();
  assert.equal(source, "fallback");
  assert.equal(activities.length, recentActivities.length);
  assert.deepEqual(activities.map((a) => a.slug).sort(), recentActivities.map((a) => a.slug).sort());
});

test("getActivitiesWithFallback falls back on a malformed API response", async () => {
  globalThis.fetch = mock.fn(async () => jsonResponse({ data: "not-an-array" }));
  const { source } = await getActivitiesWithFallback();
  assert.equal(source, "fallback");
});

test("getActivitiesWithFallback falls back on a non-200 response", async () => {
  globalThis.fetch = mock.fn(async () => jsonResponse({ message: "server error" }, 500));
  const { source } = await getActivitiesWithFallback();
  assert.equal(source, "fallback");
});

test("getActivitiesWithFallback falls back when the request times out", async () => {
  globalThis.fetch = mock.fn((_url: RequestInfo | URL, init?: RequestInit): Promise<Response> => {
    return new Promise((_resolve, reject) => {
      init?.signal?.addEventListener("abort", () => reject(new DOMException("aborted", "AbortError")));
    });
  });
  const { source } = await getActivitiesWithFallback();
  assert.equal(source, "fallback");
});

test("getActivitiesWithFallback is deterministic given the same input — generateStaticParams, the listing page, and sitemap.ts all call this same function, so two calls in the same build must agree", async () => {
  globalThis.fetch = mock.fn(async () => jsonResponse({ data: [sampleActivity], total: 1, current_page: 1, last_page: 1 }));
  const first = await getActivitiesWithFallback();
  const second = await getActivitiesWithFallback();
  assert.deepEqual(first.activities.map((a) => a.slug), second.activities.map((a) => a.slug));
});

test("lib/content.ts's fallback activity list matches the 3 real activities actually seeded in the ERP by slug", () => {
  const expectedSlugs = ["sochetonota-mulok-kormosuchi-2026-09-05", "poribesh-porichonnota-kormosuchi-2026-09-04", "madokbirodhi-ovijan-2026-08-18"];
  assert.deepEqual(recentActivities.map((a) => a.slug).sort(), expectedSlugs.sort());
});
