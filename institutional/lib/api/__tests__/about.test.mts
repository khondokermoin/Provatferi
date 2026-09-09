import { test, before, after, mock } from "node:test";
import assert from "node:assert/strict";
import { getAbout } from "../about.ts";

before(() => {
  process.env.LARAVEL_API_URL = "https://admin.example.test";
});

after(() => {
  globalThis.fetch = undefined as unknown as typeof fetch;
});

function jsonResponse(body: unknown, status = 200): Response {
  return new Response(JSON.stringify(body), { status, headers: { "Content-Type": "application/json" } });
}

test("getAbout passes through null about/mission/vision and an empty objectives list — this is exactly production's current shape, and the page must treat it as valid, not as a failure", async () => {
  globalThis.fetch = mock.fn(async () =>
    jsonResponse({ data: { about: null, mission: null, vision: null, objectives: [] } }),
  );

  const result = await getAbout();
  assert.equal(result.ok, true);
  if (result.ok) {
    assert.equal(result.data.about, null);
    assert.equal(result.data.mission, null);
    assert.equal(result.data.vision, null);
    assert.deepEqual(result.data.objectives, []);
  }
});

test("getAbout accepts populated fields with Bangla UTF-8 bodies and preserves them exactly", async () => {
  const missionBn = "বইপড়া, সাহিত্য, সংস্কৃতি, সৃজনশীলতা ও মানবিক সামাজিক কর্মকাণ্ডের মাধ্যমে";
  globalThis.fetch = mock.fn(async () =>
    jsonResponse({
      data: {
        about: {
          introduction: null,
          description: "একটি অরাজনৈতিক প্রতিষ্ঠান।",
          history: null,
          why_exists: null,
          identity_explanation: null,
          registration_status: "প্রস্তাবিত প্রতিষ্ঠাকাল ২০১৯",
        },
        mission: { body: missionBn, updated_at: "2026-09-08T12:12:02+00:00" },
        vision: null,
        objectives: [{ id: 1, title: null, body: "সমাজে বইপড়া", sort_order: 1 }],
      },
    }),
  );

  const result = await getAbout();
  assert.equal(result.ok, true);
  if (result.ok) {
    assert.equal(result.data.mission?.body, missionBn);
    assert.equal(result.data.about?.registration_status, "প্রস্তাবিত প্রতিষ্ঠাকাল ২০১৯");
    assert.equal(result.data.objectives.length, 1);
  }
});

test("getAbout rejects a malformed response (objectives not an array) as invalid_shape rather than passing bad data to the page", async () => {
  globalThis.fetch = mock.fn(async () =>
    jsonResponse({ data: { about: null, mission: null, vision: null, objectives: "not-an-array" } }),
  );

  const result = await getAbout();
  assert.equal(result.ok, false);
  if (!result.ok) assert.equal(result.error, "invalid_shape");
});

test("getAbout rejects a response missing the top-level data envelope", async () => {
  globalThis.fetch = mock.fn(async () => jsonResponse({ about: null }));
  const result = await getAbout();
  assert.equal(result.ok, false);
});
