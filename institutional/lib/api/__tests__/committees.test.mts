import { test, before, after, mock } from "node:test";
import assert from "node:assert/strict";
import { getCommittee, getCommittees } from "../committees.ts";

before(() => {
  process.env.LARAVEL_API_URL = "https://admin.example.test";
});

after(() => {
  globalThis.fetch = undefined as unknown as typeof fetch;
});

function jsonResponse(body: unknown, status = 200): Response {
  return new Response(JSON.stringify(body), { status, headers: { "Content-Type": "application/json" } });
}

const summary = {
  id: 1, slug: "executive-2026", name: "নির্বাহী কমিটি ২০২৬", committee_type: "executive",
  term_start: "2026-01-01", term_end: null, status: "active", description: null,
};

test("getCommittees accepts a null current committee alongside empty upcoming/previous", async () => {
  globalThis.fetch = mock.fn(async () => jsonResponse({ data: { current: null, upcoming: [], previous: [] } }));

  const result = await getCommittees();
  assert.equal(result.ok, true);
  if (result.ok) assert.equal(result.data.current, null);
});

test("getCommittees accepts a populated current committee with upcoming/previous lists", async () => {
  globalThis.fetch = mock.fn(async () =>
    jsonResponse({ data: { current: summary, upcoming: [{ ...summary, id: 2, status: "upcoming" }], previous: [] } }),
  );

  const result = await getCommittees();
  assert.equal(result.ok, true);
  if (result.ok) {
    assert.equal(result.data.current?.name, summary.name);
    assert.equal(result.data.upcoming.length, 1);
  }
});

test("getCommittee accepts a detail payload with an empty members list", async () => {
  globalThis.fetch = mock.fn(async () => jsonResponse({ data: { ...summary, members: [] } }));

  const result = await getCommittee("executive-2026");
  assert.equal(result.ok, true);
  if (result.ok) assert.deepEqual(result.data.members, []);
});

test("getCommittee accepts a member with all optional fields null and preserves a Bangla name", async () => {
  globalThis.fetch = mock.fn(async () =>
    jsonResponse({
      data: {
        ...summary,
        members: [{
          name: "রফিক উদ্দিন", position: "সাধারণ সম্পাদক", serial_no: 1,
          photo_url: null, bio: null, facebook_url: null, linkedin_url: null, website_url: null,
        }],
      },
    }),
  );

  const result = await getCommittee("executive-2026");
  assert.equal(result.ok, true);
  if (result.ok) assert.equal(result.data.members[0]?.name, "রফিক উদ্দিন");
});

test("getCommittee rejects a 404 (unknown or draft committee) as an error, not empty data", async () => {
  globalThis.fetch = mock.fn(async () => jsonResponse({ message: "Not Found" }, 404));

  const result = await getCommittee("does-not-exist");
  assert.equal(result.ok, false);
  if (!result.ok) assert.equal(result.error, "http_error");
});
