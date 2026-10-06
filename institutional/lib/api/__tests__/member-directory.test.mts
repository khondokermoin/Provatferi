import { test, before, after, mock } from "node:test";
import assert from "node:assert/strict";
import { getMemberDirectory, getMemberProfile, MEMBERS_CACHE_TAG } from "../member-directory.ts";

before(() => {
  process.env.LARAVEL_API_URL = "https://admin.example.test";
});

after(() => {
  globalThis.fetch = undefined as unknown as typeof fetch;
});

function jsonResponse(body: unknown, status = 200): Response {
  return new Response(JSON.stringify(body), { status, headers: { "Content-Type": "application/json" } });
}

test("getMemberDirectory returns an empty list (not an error) when no one has opted in", async () => {
  globalThis.fetch = mock.fn(async () => jsonResponse({ data: [] }));

  const result = await getMemberDirectory();
  assert.equal(result.ok, true);
  if (result.ok) assert.deepEqual(result.data, []);
});

test("getMemberDirectory accepts a populated list and never expects email/phone fields", async () => {
  globalThis.fetch = mock.fn(async () =>
    jsonResponse({ data: [{ public_slug: "nasrin-ab12cd", name: "নাসরিন আক্তার", profession: "শিক্ষক", photo_url: null }] }),
  );

  const result = await getMemberDirectory();
  assert.equal(result.ok, true);
  if (result.ok) assert.equal(result.data[0]?.name, "নাসরিন আক্তার");
});

test("getMemberProfile accepts a full detail payload with socials", async () => {
  globalThis.fetch = mock.fn(async () =>
    jsonResponse({
      data: {
        public_slug: "nasrin-ab12cd", name: "নাসরিন আক্তার", profession: "শিক্ষক", photo_url: null,
        bio: "একজন লেখক।", facebook_url: "https://facebook.com/example", linkedin_url: null, website_url: null,
      },
    }),
  );

  const result = await getMemberProfile("nasrin-ab12cd");
  assert.equal(result.ok, true);
  if (result.ok) assert.equal(result.data.facebook_url, "https://facebook.com/example");
});

test("both directory lookups carry the members cache tag, so a registry action can drop them at once", async () => {
  const inits: RequestInit[] = [];
  globalThis.fetch = mock.fn(async (_url: RequestInfo | URL, init?: RequestInit) => {
    inits.push(init ?? {});
    return jsonResponse({ data: [] });
  });
  await getMemberDirectory();
  globalThis.fetch = mock.fn(async (_url: RequestInfo | URL, init?: RequestInit) => {
    inits.push(init ?? {});
    return jsonResponse({ data: { public_slug: "x", name: "x", profession: null, photo_url: null, bio: null, facebook_url: null, linkedin_url: null, website_url: null } });
  });
  await getMemberProfile("x");

  assert.equal(MEMBERS_CACHE_TAG, "members");
  for (const init of inits) {
    assert.deepEqual((init as RequestInit & { next?: { tags?: string[] } }).next?.tags, [MEMBERS_CACHE_TAG]);
  }
});

test("getMemberProfile treats a 404 (unknown, unapproved, or opted-out member) as an error", async () => {
  globalThis.fetch = mock.fn(async () => jsonResponse({ message: "Not Found" }, 404));

  const result = await getMemberProfile("does-not-exist");
  assert.equal(result.ok, false);
});
