import { test, before, after, mock } from "node:test";
import assert from "node:assert/strict";
import { getActivity, getActivities } from "../activities.ts";

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
  activity_type_id: null,
  organization_unit_id: null,
  title: "মাদকবিরোধী অভিযান",
  slug: "madokbirodhi-ovijan-2026-08-18",
  summary: null,
  description: null,
  objective: null,
  venue: null,
  address: null,
  hero_image_path: null,
  what_happened: null,
  outcomes: null,
  gallery: [],
  related_links: [],
  facebook_post_url: null,
  start_datetime: "2026-08-18T00:00:00+00:00",
  end_datetime: null,
  featured: false,
  participant_count: null,
  published_at: "2026-08-18T00:00:00+00:00",
  type: null,
  organizationUnit: null,
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

test("getActivities rejects the current production response (empty list is fine, but a missing pagination envelope is not) as invalid_shape", async () => {
  globalThis.fetch = mock.fn(async () => jsonResponse({ data: [] }));
  const result = await getActivities();
  assert.equal(result.ok, false);
  if (!result.ok) assert.equal(result.error, "invalid_shape");
});

test("getActivities accepts a well-formed empty page — this is what production actually returns today (0 of the site's 3 real activities are in the ERP yet)", async () => {
  globalThis.fetch = mock.fn(async () => jsonResponse({ data: [], total: 0, current_page: 1, last_page: 1 }));
  const result = await getActivities();
  assert.equal(result.ok, true);
  if (result.ok) assert.equal(result.data.total, 0);
});
