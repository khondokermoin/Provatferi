import { test, before, after, mock } from "node:test";
import assert from "node:assert/strict";
import { getMemberDashboard, getMemberProfileState, memberLogin, memberRequestPasswordReset, updateMemberProfile } from "../member.ts";

before(() => {
  process.env.LARAVEL_API_URL = "https://admin.example.test";
});

after(() => {
  globalThis.fetch = undefined as unknown as typeof fetch;
});

function jsonResponse(body: unknown, status = 200): Response {
  return new Response(JSON.stringify(body), { status, headers: { "Content-Type": "application/json" } });
}

test("memberLogin returns the token on success", async () => {
  globalThis.fetch = mock.fn(async () => jsonResponse({ data: { token: "1|abcdef" } }));

  const result = await memberLogin("member@example.com", "password");
  assert.equal(result.ok, true);
  if (result.ok) assert.equal(result.data.token, "1|abcdef");
});

test("memberLogin surfaces a validation error for bad credentials", async () => {
  globalThis.fetch = mock.fn(async () =>
    jsonResponse({ message: "The given data was invalid.", errors: { email: ["ই-মেইল অথবা পাসওয়ার্ড সঠিক নয়।"] } }, 422),
  );

  const result = await memberLogin("member@example.com", "wrong");
  assert.equal(result.ok, false);
  if (!result.ok && result.error === "validation") {
    assert.deepEqual(result.errors.email, ["ই-মেইল অথবা পাসওয়ার্ড সঠিক নয়।"]);
  } else {
    assert.fail("expected a validation result");
  }
});

test("memberRequestPasswordReset returns the enumeration-safe message regardless of outcome", async () => {
  globalThis.fetch = mock.fn(async () => jsonResponse({ message: "যদি এই ই-মেইলে অ্যাকাউন্ট থাকে..." }));

  const result = await memberRequestPasswordReset("nobody@example.com");
  assert.equal(result.ok, true);
});

const dashboardBody = {
  profile: {
    member_code: "PF-2026-0001", name: "নাসরিন আক্তার", email: "nasrin@example.com", phone: "01700000000",
    status: "active", public_profile_enabled: false, public_profile_approved: false, public_slug: null,
  },
  memberships: [{ member_code: "PF-2026-0001", status: "active", start_date: "2026-01-01", expiry_date: null, membership_type: "সাধারণ সদস্য" }],
  season_history: [{ season: "২০২৬ সিজন", joined_at: "2026-01-01" }],
  payments: [{ amount_received: "500.00", method: "cash", status: "paid", received_at: "2026-01-01" }],
  library: { transactions: [] },
};

test("getMemberDashboard accepts a fully populated dashboard payload", async () => {
  globalThis.fetch = mock.fn(async () => jsonResponse({ data: dashboardBody }));

  const result = await getMemberDashboard("1|abcdef");
  assert.equal(result.ok, true);
  if (result.ok) {
    assert.equal(result.data.profile.name, "নাসরিন আক্তার");
    assert.equal(result.data.memberships.length, 1);
  }
});

test("getMemberDashboard treats an expired/revoked token (401) as an error, never as an empty dashboard", async () => {
  globalThis.fetch = mock.fn(async () => jsonResponse({ message: "Unauthenticated." }, 401));

  const result = await getMemberDashboard("expired-token");
  assert.equal(result.ok, false);
  if (!result.ok) assert.equal(result.error, "http_error");
});

test("getMemberDashboard never caches — every call passes cache: 'no-store'", async () => {
  let capturedInit: RequestInit | undefined;
  globalThis.fetch = mock.fn(async (_url: RequestInfo | URL, init?: RequestInit) => {
    capturedInit = init;
    return jsonResponse({ data: dashboardBody });
  });

  await getMemberDashboard("1|abcdef");
  assert.equal(capturedInit?.cache, "no-store");
});

test("getMemberProfileState accepts a state with no live or pending version yet", async () => {
  globalThis.fetch = mock.fn(async () =>
    jsonResponse({ data: { public_profile_enabled: false, public_profile_approved: false, public_slug: null, live: null, pending: null } }),
  );

  const result = await getMemberProfileState("1|abcdef");
  assert.equal(result.ok, true);
  if (result.ok) {
    assert.equal(result.data.live, null);
    assert.equal(result.data.pending, null);
  }
});

test("getMemberProfileState accepts a live version alongside a pending edit", async () => {
  const version = {
    bio: "পরিচিতি", profession: "শিক্ষক", facebook_url: null, linkedin_url: null, website_url: null,
    photo_url: null, submitted_at: "2026-09-13T00:00:00+00:00",
  };
  globalThis.fetch = mock.fn(async () =>
    jsonResponse({ data: { public_profile_enabled: true, public_profile_approved: true, public_slug: "x-1", live: version, pending: { ...version, bio: "নতুন" } } }),
  );

  const result = await getMemberProfileState("1|abcdef");
  assert.equal(result.ok, true);
  if (result.ok) {
    assert.equal(result.data.live?.bio, "পরিচিতি");
    assert.equal(result.data.pending?.bio, "নতুন");
  }
});

test("updateMemberProfile surfaces field-level validation errors", async () => {
  globalThis.fetch = mock.fn(async () =>
    jsonResponse({ message: "The given data was invalid.", errors: { photo: ["ফাইলের আকার সর্বোচ্চ সীমার চেয়ে বড়।"] } }, 422),
  );

  const result = await updateMemberProfile("1|abcdef", new FormData());
  assert.equal(result.ok, false);
  if (!result.ok && result.error === "validation") {
    assert.deepEqual(result.errors.photo, ["ফাইলের আকার সর্বোচ্চ সীমার চেয়ে বড়।"]);
  } else {
    assert.fail("expected a validation result");
  }
});

test("updateMemberProfile sends the Authorization header", async () => {
  let capturedInit: RequestInit | undefined;
  globalThis.fetch = mock.fn(async (_url: RequestInfo | URL, init?: RequestInit) => {
    capturedInit = init;
    return jsonResponse({ message: "সংরক্ষণ করা হয়েছে।" });
  });

  await updateMemberProfile("1|abcdef", new FormData());
  const headers = new Headers(capturedInit?.headers);
  assert.equal(headers.get("Authorization"), "Bearer 1|abcdef");
});
