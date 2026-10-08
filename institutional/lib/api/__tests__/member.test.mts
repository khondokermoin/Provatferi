import { test, before, after, mock } from "node:test";
import assert from "node:assert/strict";
import { getMemberDashboard, getMemberProfileState, getMemberReceiptPdf, memberLogin, memberRequestPasswordReset, RECEIPT_NUMBER_PATTERN, updateMemberProfile } from "../member.ts";

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

test("getMemberDashboard accepts a waived fee (no amount received) and the 2026-10-06 fee fields", async () => {
  // Before 2026-10-06 a waiver's null amount failed the guard, and the member was sent back to the login page on every
  // visit. The fee fields are optional: an ERP build that does not send them must still produce a dashboard.
  const body = {
    ...dashboardBody,
    memberships: [
      { ...dashboardBody.memberships[0], registration_fee: "500.00", payment_state: "waived" },
      { member_code: "PF-2026-0002", status: "active", start_date: "2026-10-06T00:00:00.000000Z", expiry_date: null, membership_type: "শিক্ষার্থী", registration_fee: "0.00", payment_state: "not_required" },
    ],
    payments: [{ amount_received: null, method: "cash", status: "waived", received_at: null }],
  };
  globalThis.fetch = mock.fn(async () => jsonResponse({ data: body }));

  const result = await getMemberDashboard("1|abcdef");
  assert.equal(result.ok, true);
  if (result.ok) {
    assert.equal(result.data.memberships[1]?.payment_state, "not_required");
    assert.equal(result.data.payments[0]?.amount_received, null);
  }

  globalThis.fetch = mock.fn(async () => jsonResponse({ data: { ...body, memberships: [{ ...body.memberships[1], payment_state: 7 }] } }));
  assert.equal((await getMemberDashboard("1|abcdef")).ok, false, "a malformed payment_state is still refused");
});

test("getMemberDashboard accepts the monthly contribution (2026-10-08) and refuses a malformed one", async () => {
  const monthly = {
    current_period: "2026-10", current_amount: "200.00", required: true, month_state: "partially_paid",
    outstanding: "300.00", overdue_count: 1, credit: "0.00",
    recent: [
      { period: "2026-10", amount: "200.00", paid: "100.00", waived: "0.00", outstanding: "100.00", state: "partially_paid" },
      { period: "2026-09", amount: "200.00", paid: "0.00", waived: "0.00", outstanding: "200.00", state: "overdue" },
    ],
  };
  const zero = { current_period: "2026-10", current_amount: "0.00", required: false, month_state: "not_required", outstanding: "0.00", overdue_count: 0, credit: "0.00", recent: [] };
  const body = { ...dashboardBody, memberships: [{ ...dashboardBody.memberships[0], monthly }, { ...dashboardBody.memberships[0], member_code: "PF-2026-0002", monthly: zero }] };
  globalThis.fetch = mock.fn(async () => jsonResponse({ data: body }));

  const result = await getMemberDashboard("1|abcdef");
  assert.equal(result.ok, true);
  if (result.ok) {
    assert.equal(result.data.memberships[0]?.monthly?.recent[1]?.state, "overdue");
    assert.equal(result.data.memberships[1]?.monthly?.required, false);
  }

  for (const broken of [{ ...monthly, overdue_count: "1" }, { ...monthly, required: "yes" }, { ...monthly, recent: [{ period: "2026-10", amount: 200 }] }, { ...monthly, recent: null }]) {
    globalThis.fetch = mock.fn(async () => jsonResponse({ data: { ...dashboardBody, memberships: [{ ...dashboardBody.memberships[0], monthly: broken }] } }));
    assert.equal((await getMemberDashboard("1|abcdef")).ok, false, `refused: ${JSON.stringify(broken).slice(0, 60)}`);
  }
});

test("getMemberDashboard accepts the member's receipts (2026-10-08) and refuses a malformed one", async () => {
  const receipts = [
    { receipt_no: "PLCC-RCT-2026-000002", purpose: "monthly_contribution", amount: "600.00", credit: "0.00", payment_date: "2026-10-08", periods: ["2026-01", "2026-02", "2026-03"] },
    { receipt_no: "PLCC-RCT-2026-000001", purpose: "registration", amount: "500.00", credit: "0.00", payment_date: "2026-10-07", periods: [] },
  ];
  globalThis.fetch = mock.fn(async () => jsonResponse({ data: { ...dashboardBody, receipts } }));

  const result = await getMemberDashboard("1|abcdef");
  assert.equal(result.ok, true);
  if (result.ok) {
    assert.equal(result.data.receipts?.length, 2);
    assert.deepEqual(result.data.receipts?.[0]?.periods, ["2026-01", "2026-02", "2026-03"]);
  }

  // An ERP build that does not send receipts yet still produces a dashboard (the section simply does not render).
  globalThis.fetch = mock.fn(async () => jsonResponse({ data: dashboardBody }));
  const without = await getMemberDashboard("1|abcdef");
  assert.equal(without.ok, true);
  if (without.ok) assert.equal(without.data.receipts, undefined);

  // A receipt number that is not one of ours never reaches a link: the whole payload is refused.
  const good = receipts[0];
  const broken: unknown[] = [
    { ...good, receipt_no: "PLCC-RCT-2026-1" },
    { ...good, receipt_no: "../../etc/passwd" },
    { ...good, receipt_no: "PLCC-RCT-2026-000001/../x" },
    { ...good, amount: 600 },
    { ...good, periods: "2026-01" },
    { ...good, periods: [202601] },
    { ...good, payment_date: null },
  ];
  for (const receipt of broken) {
    globalThis.fetch = mock.fn(async () => jsonResponse({ data: { ...dashboardBody, receipts: [receipt] } }));
    assert.equal((await getMemberDashboard("1|abcdef")).ok, false, `refused: ${JSON.stringify(receipt).slice(0, 70)}`);
  }
  globalThis.fetch = mock.fn(async () => jsonResponse({ data: { ...dashboardBody, receipts: "none" } }));
  assert.equal((await getMemberDashboard("1|abcdef")).ok, false, "receipts must be a list");
});

test("getMemberReceiptPdf asks for exactly one PDF with the bearer token, never cached, number encoded", async () => {
  let captured: { url: string; init: RequestInit | undefined } | undefined;
  globalThis.fetch = mock.fn(async (url: RequestInfo | URL, init?: RequestInit) => {
    captured = { url: String(url), init };
    return new Response("%PDF-1.7 test", { status: 200, headers: { "Content-Type": "application/pdf" } });
  });

  const result = await getMemberReceiptPdf("1|abcdef", "PLCC-RCT-2026-000001", { lang: "en", disposition: "inline" });
  assert.equal(result.ok, true);
  assert.equal(captured?.url, "https://admin.example.test/api/v1/member/receipts/PLCC-RCT-2026-000001/pdf?lang=en&disposition=inline");
  assert.equal(captured?.init?.cache, "no-store");
  const headers = new Headers(captured?.init?.headers);
  assert.equal(headers.get("authorization"), "Bearer 1|abcdef");
  assert.equal(headers.get("accept"), "application/pdf");
  if (result.ok) assert.equal(await result.response.text(), "%PDF-1.7 test");
});

test("RECEIPT_NUMBER_PATTERN accepts only a real receipt number", () => {
  for (const ok of ["PLCC-RCT-2026-000001", "PLCC-RCT-2027-123456", "PLCC-RCT-2026-1000000"]) assert.equal(RECEIPT_NUMBER_PATTERN.test(ok), true, ok);
  for (const bad of ["", "PLCC-RCT-2026-0001", "PLCC-RCT-26-000001", "plcc-rct-2026-000001", "PLCC-RCT-2026-000001 ", " PLCC-RCT-2026-000001", "PLCC-RCT-2026-000001\n", "PLCC-LM-2026-0001", "PLCC-RCT-2026-00000a", "PLCC-RCT-2026-000001/pdf", "..%2F"]) {
    assert.equal(RECEIPT_NUMBER_PATTERN.test(bad), false, JSON.stringify(bad));
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
