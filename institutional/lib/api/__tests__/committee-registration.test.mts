import { test, before, after, mock } from "node:test";
import assert from "node:assert/strict";
import { getCorrectionSubmission, getRegistrationLink } from "../committee-registration.ts";

before(() => {
  process.env.LARAVEL_API_URL = "https://admin.example.test";
});

after(() => {
  globalThis.fetch = undefined as unknown as typeof fetch;
});

function jsonResponse(body: unknown, status = 200): Response {
  return new Response(JSON.stringify(body), { status, headers: { "Content-Type": "application/json" } });
}

test("getRegistrationLink accepts a committee with open positions, including an occupied one", async () => {
  globalThis.fetch = mock.fn(async () =>
    jsonResponse({
      data: {
        committee: { id: 1, name: "নির্বাহী কমিটি", slug: "executive-2026" },
        positions: [
          { id: 1, name: "সাধারণ সম্পাদক", occupied: false },
          { id: 2, name: "কোষাধ্যক্ষ", occupied: true },
        ],
      },
    }),
  );

  const result = await getRegistrationLink("raw-token");
  assert.equal(result.ok, true);
  if (result.ok) {
    assert.equal(result.data.positions.length, 2);
    assert.equal(result.data.positions[1].occupied, true);
  }
});

test("getRegistrationLink rejects a 404 (invalid, revoked, or expired token) as an error", async () => {
  globalThis.fetch = mock.fn(async () => jsonResponse({ message: "Not Found" }, 404));

  const result = await getRegistrationLink("bad-token");
  assert.equal(result.ok, false);
  if (!result.ok) assert.equal(result.error, "http_error");
});

const correctionBody = {
  committee: { id: 1, name: "নির্বাহী কমিটি" },
  admin_note: "ছবি অস্পষ্ট।",
  full_name: "রফিক উদ্দিন",
  name_en: null,
  email: "rafiq@example.com",
  phone: "01800000000",
  bio: null,
  provatferi_comment: "মন্তব্য",
  facebook_url: null,
  linkedin_url: null,
  website_url: null,
  committee_position_id: 1,
  positions: [{ id: 1, name: "সাধারণ সম্পাদক" }],
};

test("getCorrectionSubmission accepts the applicant's own data including the admin's correction reason", async () => {
  globalThis.fetch = mock.fn(async () => jsonResponse({ data: correctionBody }));

  const result = await getCorrectionSubmission("raw-token");
  assert.equal(result.ok, true);
  if (result.ok) {
    assert.equal(result.data.full_name, "রফিক উদ্দিন");
    assert.equal(result.data.admin_note, "ছবি অস্পষ্ট।");
  }
});

test("getCorrectionSubmission rejects a spent or expired token as an error, not empty data", async () => {
  globalThis.fetch = mock.fn(async () => jsonResponse({ message: "Not Found" }, 404));

  const result = await getCorrectionSubmission("used-token");
  assert.equal(result.ok, false);
});
