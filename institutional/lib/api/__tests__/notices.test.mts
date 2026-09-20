import { test, before, after, mock } from "node:test";
import assert from "node:assert/strict";
import { buildNoticeQuery, getNotice, getNotices } from "../notices.ts";
import { applicationWindowLabel, getJobPosting } from "../recruitment.ts";

before(() => {
  process.env.LARAVEL_API_URL = "https://admin.example.test";
});

after(() => {
  globalThis.fetch = undefined as unknown as typeof fetch;
});

function jsonResponse(body: unknown, status = 200): Response {
  return new Response(JSON.stringify(body), { status, headers: { "Content-Type": "application/json" } });
}

function without(source: Record<string, unknown>, key: string): Record<string, unknown> {
  const copy = { ...source };
  delete copy[key];
  return copy;
}

const summary = {
  slug: "volunteer-team-call",
  title: "প্রভাতফেরীর স্বেচ্ছাসেবী টিমে যুক্ত হওয়ার আহ্বান",
  notice_type: "volunteer",
  notice_type_label: "স্বেচ্ছাসেবী আহ্বান",
  summary: "দায়িত্বশীল স্বেচ্ছাসেবীদের আহ্বান।",
  published_at: "2026-09-15T04:00:00+00:00",
  expires_at: null,
  is_pinned: true,
  is_new: true,
  is_expired: false,
  is_archived: false,
};

const listPayload = {
  data: [summary],
  meta: { current_page: 1, last_page: 1, per_page: 20, total: 1 },
  filters: { types: [{ key: "volunteer", label: "স্বেচ্ছাসেবী আহ্বান", count: 1 }], years: [2026] },
};

test("buildNoticeQuery leaves out empty filters and page 1", () => {
  assert.equal(buildNoticeQuery({}), "");
  assert.equal(buildNoticeQuery({ page: 1, perPage: 20 }), "?per_page=20");
  assert.equal(buildNoticeQuery({ type: "tender", year: 2026, page: 2 }), "?type=tender&year=2026&page=2");
  assert.ok(buildNoticeQuery({ q: "বই" }).startsWith("?q=%E0%A6"));
});

test("getNotices accepts the board payload and requests the filtered URL", async () => {
  // Typed with the URL parameter so the test can read back what was requested.
  const fetchMock = mock.fn(async (input: RequestInfo | URL) => {
    void input;
    return jsonResponse(listPayload);
  });
  globalThis.fetch = fetchMock as unknown as typeof fetch;

  const result = await getNotices({ type: "volunteer", perPage: 20 });
  assert.equal(result.ok, true);
  if (result.ok) {
    assert.equal(result.data.data[0]?.title, summary.title);
    assert.equal(result.data.filters.types[0]?.label, "স্বেচ্ছাসেবী আহ্বান");
  }
  assert.match(String(fetchMock.mock.calls[0]?.arguments[0]), /\/api\/v1\/public\/notices\?type=volunteer&per_page=20$/);
});

test("getNotices rejects a payload missing a flag rather than rendering half a row", async () => {
  globalThis.fetch = mock.fn(async () => jsonResponse({ ...listPayload, data: [without(summary, "is_new")] })) as unknown as typeof fetch;

  const result = await getNotices();
  assert.equal(result.ok, false);
});

test("getNotice accepts a detail with an action, attachment and live recruitment terms", async () => {
  globalThis.fetch = mock.fn(async () =>
    jsonResponse({
      data: {
        ...summary,
        body: "প্রথম অনুচ্ছেদ।\n\n• প্রথম কাজ",
        organization_unit: "প্রভাতফেরী সাহিত্য ও সাংস্কৃতিক কেন্দ্র",
        action: { url: "https://chat.whatsapp.com/example", label: "স্বেচ্ছাসেবী হিসেবে যুক্ত হোন" },
        cover_image_url: null,
        share_image_url: null,
        attachment: { url: "https://admin.example.test/api/v1/public/notices/volunteer-team-call/attachment", size: 2500000, mime: "application/pdf" },
        recruitment: {
          slug: "volunteer-ab12", title: "স্বেচ্ছাসেবী", employment_type_label: "স্বেচ্ছাসেবী", is_volunteer: true,
          volunteer_note: "এটি একটি স্বেচ্ছাসেবী সুযোগ; বর্তমানে আর্থিক পারিশ্রমিকের প্রতিশ্রুতি নেই।", salary_range: null,
          application_mode: "rolling", application_mode_label: "চলমান", opening_date: "2026-09-15", application_deadline: null, is_open: true,
          accepts_applications: true, apply_path: "/recruitment/volunteer-ab12/apply",
        },
        updated_at: "2026-09-15T04:00:00+00:00",
      },
    }),
  ) as unknown as typeof fetch;

  const result = await getNotice("volunteer-team-call");
  assert.equal(result.ok, true);
  if (result.ok) {
    assert.equal(result.data.recruitment?.salary_range, null);
    assert.equal(result.data.action?.label, "স্বেচ্ছাসেবী হিসেবে যুক্ত হোন");
    // The apply route is resolved by the ERP, never composed in the page.
    assert.equal(result.data.recruitment?.apply_path, "/recruitment/volunteer-ab12/apply");
  }
});

test("getNotice accepts a detail from a not-yet-migrated ERP that omits share_image_url entirely", async () => {
  // §12 regression: this exact case broke `next build` against the live ERP
  // the moment share_image_url became a required field — production had not
  // been migrated yet, so the key was absent (not null), and the guard
  // rejected every notice production actually returned.
  globalThis.fetch = mock.fn(async () =>
    jsonResponse({
      data: without(
        { ...summary, body: "B", organization_unit: null, action: null, cover_image_url: null, attachment: null, updated_at: null, recruitment: null },
        "share_image_url",
      ),
    }),
  ) as unknown as typeof fetch;

  assert.equal((await getNotice("volunteer-team-call")).ok, true);
});

test("getNotice rejects a recruitment block that predates the application form", async () => {
  const recruitment = {
    slug: "volunteer-ab12", title: "স্বেচ্ছাসেবী", employment_type_label: "স্বেচ্ছাসেবী", is_volunteer: true,
    volunteer_note: null, salary_range: null, application_mode: "rolling", application_mode_label: "চলমান",
    opening_date: "2026-09-15", application_deadline: null, is_open: true,
  };
  globalThis.fetch = mock.fn(async () =>
    jsonResponse({
      data: {
        ...summary, body: "B", organization_unit: null, action: null, cover_image_url: null, share_image_url: null, attachment: null,
        updated_at: null, recruitment,
      },
    }),
  ) as unknown as typeof fetch;

  assert.equal((await getNotice("volunteer-team-call")).ok, false);
});

test("getNotice rejects a detail whose recruitment block is malformed", async () => {
  globalThis.fetch = mock.fn(async () =>
    jsonResponse({
      data: {
        ...summary, body: "B", organization_unit: null, action: null, cover_image_url: null, share_image_url: null, attachment: null, updated_at: null,
        recruitment: { title: "X" },
      },
    }),
  ) as unknown as typeof fetch;

  assert.equal((await getNotice("volunteer-team-call")).ok, false);
});

test("getJobPosting requires the volunteer/rolling contract fields", async () => {
  const job = {
    id: 1, title: "স্বেচ্ছাসেবী", slug: "volunteer-ab12", summary: null, department: "স্বেচ্ছাসেবী ও সাংগঠনিক উন্নয়ন",
    description: "D", requirements: null, organization_unit: "প্রভাতফেরী সাহিত্য ও সাংস্কৃতিক কেন্দ্র",
    employment_type: "volunteer", employment_type_label: "স্বেচ্ছাসেবী", is_volunteer: true,
    volunteer_note: "এটি একটি স্বেচ্ছাসেবী সুযোগ; বর্তমানে আর্থিক পারিশ্রমিকের প্রতিশ্রুতি নেই।", salary_range: null,
    application_mode: "rolling", application_mode_label: "চলমান", opening_date: "2026-09-15", application_deadline: null,
    published_at: "2026-09-15T04:00:00+00:00", notice_slug: "volunteer-team-call",
    accepts_applications: true, apply_path: "/recruitment/volunteer-ab12/apply",
    notice_action: { url: "https://chat.whatsapp.com/example", label: "স্বেচ্ছাসেবী হিসেবে যুক্ত হোন" },
    skill_options: [{ key: "fundraising", label: "Fundraising / Donation / Sponsorship" }],
    share_image_url: null,
  };
  globalThis.fetch = mock.fn(async () => jsonResponse({ data: job })) as unknown as typeof fetch;
  assert.equal((await getJobPosting("volunteer-ab12")).ok, true);

  // The pre-2026-09-15 shape (no is_volunteer) must be rejected, not half-rendered.
  globalThis.fetch = mock.fn(async () => jsonResponse({ data: without(job, "is_volunteer") })) as unknown as typeof fetch;
  assert.equal((await getJobPosting("volunteer-ab12")).ok, false);

  // So must a payload from before the application form existed.
  globalThis.fetch = mock.fn(async () => jsonResponse({ data: without(job, "accepts_applications") })) as unknown as typeof fetch;
  assert.equal((await getJobPosting("volunteer-ab12")).ok, false);

  // A closed form is a valid payload — it simply carries no route or catalogue.
  globalThis.fetch = mock.fn(async () =>
    jsonResponse({ data: { ...job, accepts_applications: false, apply_path: null, skill_options: null } }),
  ) as unknown as typeof fetch;
  assert.equal((await getJobPosting("volunteer-ab12")).ok, true);

  // §12 regression: a not-yet-migrated ERP omits share_image_url entirely
  // (not null) — this build's own log against the live ERP caught exactly
  // this, rejecting every posting production actually returned.
  globalThis.fetch = mock.fn(async () => jsonResponse({ data: without(job, "share_image_url") })) as unknown as typeof fetch;
  assert.equal((await getJobPosting("volunteer-ab12")).ok, true);
});

test("applicationWindowLabel never invents a deadline", () => {
  const format = (value: string | null) => (value ? `F(${value})` : null);
  assert.equal(applicationWindowLabel({ application_mode: "rolling", application_deadline: null }, format), "আবেদন চলমান");
  assert.equal(applicationWindowLabel({ application_mode: "fixed", application_deadline: "2026-12-31" }, format), "শেষ তারিখ F(2026-12-31)");
  assert.equal(applicationWindowLabel({ application_mode: "fixed", application_deadline: null }, format), "শেষ তারিখ এখনো ঘোষিত হয়নি");
  assert.equal(applicationWindowLabel({ application_mode: "rolling", application_deadline: null }, format, false), "আবেদন বন্ধ");
});
