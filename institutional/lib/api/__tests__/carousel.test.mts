import { test, before, after, mock } from "node:test";
import assert from "node:assert/strict";
import { getCarouselSlides } from "../carousel.ts";

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

const slide = {
  id: 1,
  image_url: "https://admin.example.test/storage/homepage-carousel/one.jpg",
  title: "প্রভাতফেরীতে স্বাগতম",
  title_en: "Welcome to Provatferi",
  alt_text: "সূর্যোদয়ের ছবি",
  alt_text_en: "A sunrise photo",
  link_url: "https://provatferi.org/activities",
  link_label: "আরও দেখুন",
  link_label_en: "See more",
};

test("getCarouselSlides accepts a full slide and requests the right URL", async () => {
  const fetchMock = mock.fn(async (input: RequestInfo | URL) => {
    void input;
    return jsonResponse({ data: [slide] });
  });
  globalThis.fetch = fetchMock as unknown as typeof fetch;

  const result = await getCarouselSlides();
  assert.equal(result.ok, true);
  if (result.ok) {
    assert.equal(result.data.length, 1);
    assert.equal(result.data[0]?.title_en, "Welcome to Provatferi");
  }
  assert.match(String(fetchMock.mock.calls[0]?.arguments[0]), /\/api\/v1\/public\/homepage-carousel$/);
});

test("getCarouselSlides accepts a slide with no link (both link fields null)", async () => {
  globalThis.fetch = mock.fn(async () =>
    jsonResponse({ data: [{ ...slide, link_url: null, link_label: null, link_label_en: null }] }),
  ) as unknown as typeof fetch;

  const result = await getCarouselSlides();
  assert.equal(result.ok, true);
  if (result.ok) {
    assert.equal(result.data[0]?.link_url, null);
  }
});

test("getCarouselSlides accepts an empty carousel", async () => {
  globalThis.fetch = mock.fn(async () => jsonResponse({ data: [] })) as unknown as typeof fetch;

  const result = await getCarouselSlides();
  assert.equal(result.ok, true);
  if (result.ok) {
    assert.deepEqual(result.data, []);
  }
});

test("getCarouselSlides rejects a payload missing a required field rather than rendering half a slide", async () => {
  globalThis.fetch = mock.fn(async () => jsonResponse({ data: [without(slide, "image_url")] })) as unknown as typeof fetch;

  const result = await getCarouselSlides();
  assert.equal(result.ok, false);
});

test("getCarouselSlides rejects a non-array data field", async () => {
  globalThis.fetch = mock.fn(async () => jsonResponse({ data: slide })) as unknown as typeof fetch;

  const result = await getCarouselSlides();
  assert.equal(result.ok, false);
});
