/**
 * Run with: npm run test:api (see package.json)
 *
 * The member's receipt PDF as this site delivers it (lib/receipt-route.ts; the route file under app/api/member/receipts
 * is a thin wrapper). Whose receipt it is, is Laravel's decision — it looks the number up among that member's own
 * receipts, so someone else's number is the same 404 as one that does not exist. What is pinned here is everything this
 * site adds on top of that: nothing malformed ever goes upstream, a signed-out member is sent to the login page, the
 * PDF is passed through without ever being cached, and the file name is built here, not copied from upstream.
 */
import { test, before, after, beforeEach, mock } from "node:test";
import assert from "node:assert/strict";
import { handleMemberReceiptGet } from "../../receipt-route.ts";

const ORIGINAL_ENV = process.env.LARAVEL_API_URL;
const ORIGINAL_FETCH = globalThis.fetch;
const ORIGINAL_ERROR = console.error;

before(() => {
  process.env.LARAVEL_API_URL = "https://admin.example.test";
  console.error = () => undefined; // the API client logs every failure it turns into a result; the tests provoke them on purpose
});
after(() => {
  process.env.LARAVEL_API_URL = ORIGINAL_ENV;
  globalThis.fetch = ORIGINAL_FETCH;
  console.error = ORIGINAL_ERROR;
});

type Call = { url: string; init: RequestInit };
let calls: Call[] = [];

const NUMBER = "PLCC-RCT-2026-000001";
const PDF_BYTES = "%PDF-1.7\n% a stand-in for a real receipt\n";

function ask(path = `/api/member/receipts/${NUMBER}`): Request {
  return new Request(`https://provatferi.org${path}`);
}

function laravelReplies(response: () => Response) {
  globalThis.fetch = mock.fn(async (url: string | URL | Request, init?: RequestInit) => {
    calls.push({ url: String(url), init: init ?? {} });
    return response();
  }) as typeof fetch;
}

function laravelSendsPdf(extraHeaders: Record<string, string> = {}) {
  laravelReplies(
    () =>
      new Response(PDF_BYTES, {
        status: 200,
        headers: { "Content-Type": "application/pdf", "Content-Disposition": 'attachment; filename="something-else.pdf"', ...extraHeaders },
      }),
  );
}

beforeEach(() => {
  calls = [];
  globalThis.fetch = ORIGINAL_FETCH;
});

test("a string that is not a receipt number is a 404 and nothing is asked upstream", async () => {
  laravelSendsPdf();
  for (const bad of ["", "PLCC-RCT-2026-1", "../../etc/passwd", `${NUMBER}/../x`, `${NUMBER}%0d%0aX-Evil: 1`, "plcc-rct-2026-000001", "PLCC-LM-2026-0001"]) {
    const response = await handleMemberReceiptGet(ask(), bad, "1|token");
    assert.equal(response.status, 404, JSON.stringify(bad));
  }
  assert.equal(calls.length, 0, "no request ever left for a malformed number");
});

test("no session token: sent to the login page, nothing asked upstream", async () => {
  laravelSendsPdf();
  const response = await handleMemberReceiptGet(ask(), NUMBER, null);
  assert.equal(response.status, 303);
  assert.equal(response.headers.get("location"), "/member/login");
  assert.equal(response.headers.get("cache-control"), "private, no-store");
  assert.equal(calls.length, 0);
});

test("a revoked or suspended session (401/403 upstream) is sent to the login page", async () => {
  for (const status of [401, 403]) {
    laravelReplies(() => new Response("{}", { status, headers: { "Content-Type": "application/json" } }));
    const response = await handleMemberReceiptGet(ask(), NUMBER, "1|stale");
    assert.equal(response.status, 303, String(status));
    assert.equal(response.headers.get("location"), "/member/login");
  }
});

test("someone else's receipt number and a number that does not exist answer exactly alike: 404", async () => {
  laravelReplies(() => new Response('{"message":"Not Found."}', { status: 404, headers: { "Content-Type": "application/json" } }));
  const response = await handleMemberReceiptGet(ask(), NUMBER, "1|token");
  assert.equal(response.status, 404);
  assert.equal(response.headers.get("content-type"), "text/plain; charset=utf-8");
  assert.equal(response.headers.get("cache-control"), "private, no-store");
  const body = await response.text();
  assert.ok(!body.includes(NUMBER), "the answer never echoes the number back");
});

test("an upstream failure or a timeout is a 502, never a half receipt", async () => {
  laravelReplies(() => new Response("boom", { status: 500 }));
  assert.equal((await handleMemberReceiptGet(ask(), NUMBER, "1|token")).status, 502);

  globalThis.fetch = mock.fn(async () => {
    throw new TypeError("fetch failed");
  }) as typeof fetch;
  const down = await handleMemberReceiptGet(ask(), NUMBER, "1|token");
  assert.equal(down.status, 502);
  assert.equal(down.headers.get("cache-control"), "private, no-store");
});

test("an upstream answer that is not a PDF is refused (502), not passed on", async () => {
  laravelReplies(() => new Response("<html>login</html>", { status: 200, headers: { "Content-Type": "text/html" } }));
  const response = await handleMemberReceiptGet(ask(), NUMBER, "1|token");
  assert.equal(response.status, 502);
  assert.ok(!(response.headers.get("content-type") ?? "").includes("html"));
});

test("success: the PDF is passed through, private, never cached, file name built locally", async () => {
  laravelSendsPdf();
  const response = await handleMemberReceiptGet(ask(), NUMBER, "1|token");

  assert.equal(response.status, 200);
  assert.equal(response.headers.get("content-type"), "application/pdf");
  assert.equal(response.headers.get("content-disposition"), `attachment; filename="${NUMBER}.pdf"`, "not the upstream header");
  assert.equal(response.headers.get("cache-control"), "private, no-store");
  assert.equal(response.headers.get("x-content-type-options"), "nosniff");
  assert.match(response.headers.get("x-robots-tag") ?? "", /noindex/);
  assert.equal(await response.text(), PDF_BYTES);

  assert.equal(calls.length, 1);
  assert.equal(calls[0]?.url, `https://admin.example.test/api/v1/member/receipts/${NUMBER}/pdf?lang=bn&disposition=attachment`);
  assert.equal(new Headers(calls[0]?.init.headers).get("authorization"), "Bearer 1|token");
  assert.equal(calls[0]?.init.cache, "no-store");
});

test("language and disposition are chosen from a closed list; anything else falls back to Bangla / attachment", async () => {
  laravelSendsPdf();

  const english = await handleMemberReceiptGet(ask(`/api/member/receipts/${NUMBER}?lang=en&disposition=inline`), NUMBER, "1|token");
  assert.equal(english.status, 200);
  assert.equal(english.headers.get("content-disposition"), `inline; filename="${NUMBER}.pdf"`);
  assert.match(calls[0]?.url ?? "", /\?lang=en&disposition=inline$/);

  calls = [];
  await handleMemberReceiptGet(ask(`/api/member/receipts/${NUMBER}?lang=fr&disposition=../../x`), NUMBER, "1|token");
  assert.match(calls[0]?.url ?? "", /\?lang=bn&disposition=attachment$/, "nothing from the query string is forwarded verbatim");
});

test("the member's token is never put in the URL, a header the browser sees, or the redirect", async () => {
  laravelSendsPdf();
  const ok = await handleMemberReceiptGet(ask(), NUMBER, "1|SECRET-TOKEN-VALUE");
  for (const [name, value] of ok.headers) assert.ok(!value.includes("SECRET-TOKEN-VALUE"), `header ${name}`);
  assert.ok(!(calls[0]?.url ?? "").includes("SECRET-TOKEN-VALUE"), "not in the upstream URL either");

  laravelReplies(() => new Response("{}", { status: 401 }));
  const redirect = await handleMemberReceiptGet(ask(), NUMBER, "1|SECRET-TOKEN-VALUE");
  for (const [name, value] of redirect.headers) assert.ok(!value.includes("SECRET-TOKEN-VALUE"), `redirect header ${name}`);
});
