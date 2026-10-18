/**
 * Run with: npm run test:api (see package.json)
 *
 * The site's half of "the central Messenger group is offered only after a verified application"
 * (admin-erp/docs/VOLUNTEER_MESSENGER_GROUP.md): carrying the proof Laravel issues from the submission to the confirmation
 * page, asking Laravel to verify it, and refusing to render anything that is not a Messenger group invite.
 *
 * Every token in this file is made up. The real invite is admin-managed data and is never written into the repository.
 */
import { test, before, after, beforeEach, mock } from "node:test";
import assert from "node:assert/strict";
import { randomUUID } from "node:crypto";
import { forwardApplication } from "../../volunteer-application.ts";
import { handleApplicationPost } from "../../volunteer-application-post.ts";
import {
  SUCCESS_COOKIE,
  isMessengerInvite,
  readSuccessProof,
  setSuccessCookie,
  successCookiePath,
  successCookiePaths,
  successSetCookieHeaders,
  verifyVolunteerSuccess,
} from "../../volunteer-success.ts";

const ORIGINAL_ENV = process.env.LARAVEL_API_URL;
const ORIGINAL_FETCH = globalThis.fetch;

before(() => {
  process.env.LARAVEL_API_URL = "https://admin.example.test";
});
after(() => {
  process.env.LARAVEL_API_URL = ORIGINAL_ENV;
  globalThis.fetch = ORIGINAL_FETCH;
});

type Call = { url: string; init: RequestInit };
let calls: Call[] = [];

function laravelReplies(body: unknown, status = 200) {
  calls = [];
  globalThis.fetch = mock.fn(async (url: string | URL | Request, init?: RequestInit) => {
    calls.push({ url: String(url), init: init ?? {} });
    return new Response(JSON.stringify(body), { status, headers: { "Content-Type": "application/json" } });
  }) as typeof fetch;
}
beforeEach(() => {
  calls = [];
});

/** What Laravel's encrypter produces looks like this: base64 of a small JSON envelope. */
const PROOF = "eyJpdiI6Im1hZGUtdXAtaXYtMTIzNDU2Iiwia2V5IjoibWFkZS11cCIsInRhZyI6Im1hZGUtdXAtdGFnLTEyMzQ1NiJ9";
const INVITE = "https://m.me/j/MadeUpToken_12-ab/";
const SLUG = "volunteer-posting";
const created = { data: { application_no: "VOL-2026-0042", success_proof: PROOF, success_proof_expires_in: 1800 } };

// ---------------------------------------------------------------------------
// The proof as Laravel hands it over
// ---------------------------------------------------------------------------

test("a proof shaped like Laravel's envelope is accepted, with its lifetime", () => {
  assert.deepEqual(readSuccessProof({ success_proof: PROOF, success_proof_expires_in: 1800 }), { value: PROOF, maxAge: 1800 });
});

test("a missing, mistyped or oddly shaped proof is dropped — the page then simply shows no group", () => {
  const bad: Record<string, unknown>[] = [
    {},
    { success_proof: PROOF },
    { success_proof_expires_in: 1800 },
    { success_proof: 12345, success_proof_expires_in: 1800 },
    { success_proof: null, success_proof_expires_in: 1800 },
    { success_proof: "short", success_proof_expires_in: 1800 },
    { success_proof: PROOF, success_proof_expires_in: "1800" },
    { success_proof: PROOF, success_proof_expires_in: Number.NaN },
    { success_proof: PROOF, success_proof_expires_in: Number.POSITIVE_INFINITY },
    { success_proof: `${PROOF};Path=/;Domain=.provatferi.org`, success_proof_expires_in: 1800 }, // would smuggle cookie attributes
    { success_proof: `${PROOF}\r\nSet-Cookie: x=y`, success_proof_expires_in: 1800 }, // header injection
    { success_proof: `${PROOF} ${PROOF}`, success_proof_expires_in: 1800 },
    { success_proof: "A".repeat(2049), success_proof_expires_in: 1800 },
  ];
  for (const data of bad) assert.equal(readSuccessProof(data), null, JSON.stringify(data).slice(0, 80));
});

test("the cookie's lifetime is clamped, whatever Laravel says", () => {
  assert.equal(readSuccessProof({ success_proof: PROOF, success_proof_expires_in: 1 })?.maxAge, 60);
  assert.equal(readSuccessProof({ success_proof: PROOF, success_proof_expires_in: -50 })?.maxAge, 60);
  assert.equal(readSuccessProof({ success_proof: PROOF, success_proof_expires_in: 86_400 })?.maxAge, 3600);
  assert.equal(readSuccessProof({ success_proof: PROOF, success_proof_expires_in: 1799.9 })?.maxAge, 1799);
});

// ---------------------------------------------------------------------------
// The cookie
// ---------------------------------------------------------------------------

test("the cookie goes to the confirmation page's own path in both languages, and to nothing else", () => {
  assert.deepEqual(successCookiePaths(SLUG), [`/recruitment/${SLUG}/apply/success`, `/en/recruitment/${SLUG}/apply/success`]);
  // A slug is encoded the way the browser sees it in the address, so a odd one cannot widen the Path.
  assert.deepEqual(successCookiePaths("a b/c"), ["/recruitment/a%20b%2Fc/apply/success", "/en/recruitment/a%20b%2Fc/apply/success"]);
  for (const path of successCookiePaths("../../x")) assert.doesNotMatch(path, /\.\.\//);
});

test("Set-Cookie lines: HttpOnly, Secure, SameSite=Lax, a short Max-Age, a narrow Path, no Domain", () => {
  const headers = successSetCookieHeaders(SLUG, { value: PROOF, maxAge: 1800 });

  assert.equal(headers.length, 2);
  for (const [name, line] of headers) {
    assert.equal(name, "Set-Cookie");
    assert.ok(line.startsWith(`${SUCCESS_COOKIE}=${encodeURIComponent(PROOF)}; `), line);
    assert.match(line, /; Max-Age=1800;/);
    assert.match(line, /; HttpOnly(;|$)/);
    assert.match(line, /; Secure(;|$)/);
    assert.match(line, /; SameSite=Lax(;|$)/);
    assert.match(line, /; Path=\/(en\/)?recruitment\/volunteer-posting\/apply\/success;/);
    assert.doesNotMatch(line, /Domain=/i, "a host-only cookie: never shared with a sibling subdomain");
    assert.doesNotMatch(line, /Path=\/;/, "never site-wide");
  }
  assert.notEqual(headers[0][1], headers[1][1], "one line per language path");
});

test("the Server Action path sets ONE cookie, for the address it redirects to, through Next's cookie store", () => {
  const set: { name: string; value: string; options: Record<string, unknown> }[] = [];
  setSuccessCookie({ set: (name, value, options) => set.push({ name, value, options }) }, SLUG, { value: PROOF, maxAge: 1800 });

  assert.equal(set.length, 1, "Next's store is keyed by name: a second set of pf_applied with another Path would REPLACE this one");
  const [call] = set;
  assert.equal(call.name, SUCCESS_COOKIE);
  assert.equal(call.value, PROOF);
  assert.equal(call.options.httpOnly, true);
  assert.equal(call.options.secure, true);
  assert.equal(call.options.sameSite, "lax");
  assert.equal(call.options.maxAge, 1800);
  assert.equal(call.options.path, successCookiePath(SLUG, "bn"));
  assert.equal(call.options.path, `/recruitment/${SLUG}/apply/success`, "the unprefixed address the action redirects to");

  const english: typeof set = [];
  setSuccessCookie({ set: (name, value, options) => english.push({ name, value, options }) }, SLUG, { value: PROOF, maxAge: 1800 }, "en");
  assert.equal(english[0].options.path, `/en/recruitment/${SLUG}/apply/success`);
});

test("a name-keyed store, as Next's is, still holds the cookie the redirect needs", () => {
  const store = new Map<string, { value: string; path: string }>();
  setSuccessCookie({ set: (name, value, options) => store.set(name, { value, path: options.path }) }, SLUG, { value: PROOF, maxAge: 1800 });

  assert.deepEqual([...store.keys()], [SUCCESS_COOKIE]);
  assert.equal(store.get(SUCCESS_COOKIE)?.path, `/recruitment/${SLUG}/apply/success`);
});

// ---------------------------------------------------------------------------
// What may become a link
// ---------------------------------------------------------------------------

test("only Laravel's canonical Messenger group address can become a link", () => {
  assert.equal(isMessengerInvite(INVITE), true);
  assert.equal(isMessengerInvite("https://m.me/j/abcdef/"), true);

  const refused: unknown[] = [
    null,
    undefined,
    42,
    {},
    "",
    "http://m.me/j/MadeUpToken_12-ab/",
    "https://m.me/j/MadeUpToken_12-ab", // not the canonical form
    "https://m.me/j/MadeUpToken_12-ab/?send_source=gc%3Acopy_invite_link_c", // the pasted form is normalised by Laravel, never rendered
    "https://m.me/j/MadeUpToken_12-ab/#frag",
    "https://www.messenger.com/j/MadeUpToken_12-ab/",
    "https://M.me/j/MadeUpToken_12-ab/",
    "https://m.me.evil.test/j/MadeUpToken_12-ab/",
    "https://evil.test/m.me/j/MadeUpToken_12-ab/",
    "https://m.me@evil.test/j/MadeUpToken_12-ab/",
    "https://evil.test@m.me/j/MadeUpToken_12-ab/",
    "https://m.me:8443/j/MadeUpToken_12-ab/",
    "https://m.me/j/short/",
    "https://m.me/j/has space/",
    `https://m.me/j/${"a".repeat(101)}/`,
    "https://m.me/MadeUpToken_12-ab/",
    "https://m.me/j/MadeUpToken_12-ab/extra/",
    "javascript:alert(1)",
    "data:text/html,<script>alert(1)</script>",
    "//m.me/j/MadeUpToken_12-ab/",
    "https://chat.whatsapp.com/MadeUpToken1234",
    ` ${INVITE}`,
    `${INVITE}\n`,
    `${INVITE}"><script>`,
  ];
  for (const value of refused) assert.equal(isMessengerInvite(value), false, String(value));
});

// ---------------------------------------------------------------------------
// Asking Laravel to verify
// ---------------------------------------------------------------------------

test("no proof means no question: the visitor is unverified and Laravel is not called", async () => {
  laravelReplies({ data: { verified: true, messenger: { url: INVITE } } });

  assert.deepEqual(await verifyVolunteerSuccess(SLUG, null), { kind: "unverified" });
  assert.deepEqual(await verifyVolunteerSuccess(SLUG, ""), { kind: "unverified" });
  assert.equal(calls.length, 0);
});

test("the proof travels in the request BODY — never in the address — and the answer is never cached", async () => {
  laravelReplies({ data: { verified: true, messenger: { url: INVITE } } });
  const outcome = await verifyVolunteerSuccess("a b/c", PROOF);

  assert.deepEqual(outcome, { kind: "verified", messengerUrl: INVITE });
  assert.equal(calls.length, 1);
  assert.equal(calls[0].url, "https://admin.example.test/api/v1/public/recruitment/a%20b%2Fc/application-success");
  assert.doesNotMatch(calls[0].url, new RegExp(PROOF.slice(0, 20)), "the proof must not be in the URL");
  assert.equal(calls[0].init.method, "POST");
  assert.equal(calls[0].init.cache, "no-store");
  assert.deepEqual(JSON.parse(String(calls[0].init.body)), { proof: PROOF });
});

test("a verified applicant with the feature off (or no valid invite) is verified, with no link", async () => {
  for (const messenger of [null, undefined]) {
    laravelReplies({ data: { verified: true, ...(messenger === undefined ? {} : { messenger }) } });
    assert.deepEqual(await verifyVolunteerSuccess(SLUG, PROOF), { kind: "verified", messengerUrl: null });
  }
});

test("the last gate: whatever address Laravel names, only a canonical Messenger invite is ever handed to the page", async () => {
  for (const url of ["https://evil.test/", "javascript:alert(1)", "https://chat.whatsapp.com/MadeUpToken1234", "https://m.me/j/MadeUpToken_12-ab/?x=1", "http://m.me/j/MadeUpToken_12-ab/", ""]) {
    laravelReplies({ data: { verified: true, messenger: { url } } });
    assert.deepEqual(await verifyVolunteerSuccess(SLUG, PROOF), { kind: "verified", messengerUrl: null }, url);
  }
});

test("an unverified answer never yields a link, even if the answer carries one", async () => {
  laravelReplies({ data: { verified: false, messenger: { url: INVITE } } });
  assert.deepEqual(await verifyVolunteerSuccess(SLUG, PROOF), { kind: "unverified" });
});

test("when Laravel cannot be asked the page says so and shows no link — it never guesses", async () => {
  laravelReplies({ message: "boom" }, 500);
  assert.deepEqual(await verifyVolunteerSuccess(SLUG, PROOF), { kind: "unavailable" });

  laravelReplies({ data: { verified: "yes" } });
  assert.deepEqual(await verifyVolunteerSuccess(SLUG, PROOF), { kind: "unavailable" }, "an answer of the wrong shape");

  laravelReplies({ data: { verified: true, messenger: { url: 5 } } });
  assert.deepEqual(await verifyVolunteerSuccess(SLUG, PROOF), { kind: "unavailable" }, "a link that is not even a string");

  globalThis.fetch = mock.fn(async () => {
    throw new TypeError("fetch failed");
  }) as typeof fetch;
  assert.deepEqual(await verifyVolunteerSuccess(SLUG, PROOF), { kind: "unavailable" });
});

// ---------------------------------------------------------------------------
// Keeping the proof away from the browser
// ---------------------------------------------------------------------------

function sampleForm(): FormData {
  const form = new FormData();
  form.set("applicant_name", "Rahim Uddin");
  form.set("applicant_email", "rahim@example.test");
  form.set("applicant_phone", "01712345678");
  form.set("accuracy_declaration", "1");
  form.set("privacy_consent", "1");
  form.set("submission_token", randomUUID());
  return form;
}

test("an accepted application hands the proof to the server only — it is not part of the state the browser is sent", async () => {
  laravelReplies(created, 201);
  const { state, proof } = await forwardApplication(SLUG, sampleForm(), false);

  assert.deepEqual(state, { status: "success" });
  assert.deepEqual(proof, { value: PROOF, maxAge: 1800 });
  assert.doesNotMatch(JSON.stringify(state), new RegExp(PROOF.slice(0, 20)));
});

test("an accepted application from a Laravel that issues no proof still succeeds, just without one", async () => {
  laravelReplies({ data: { application_no: "VOL-2026-0042" } }, 201);
  const { state, proof } = await forwardApplication(SLUG, sampleForm(), false);

  assert.deepEqual(state, { status: "success" });
  assert.equal(proof, null);
});

test("a rejected or failed submission carries no proof", async () => {
  laravelReplies({ message: "invalid", errors: { applicant_phone: ["x"] } }, 422);
  assert.equal((await forwardApplication(SLUG, sampleForm(), false)).proof, null);

  laravelReplies({ message: "boom" }, 500);
  assert.equal((await forwardApplication(SLUG, sampleForm(), false)).proof, null);
});

// ---------------------------------------------------------------------------
// The endpoint the form's JavaScript posts to
// ---------------------------------------------------------------------------

const ENDPOINT = "https://provatferi.org/api/recruitment/posting/apply";
const BROWSER = { origin: "https://provatferi.org", "sec-fetch-site": "same-origin" };
const multipart = () => new Request(ENDPOINT, { method: "POST", body: sampleForm(), headers: BROWSER });
const cookiesOf = (res: Response) => res.headers.getSetCookie().filter((line) => line.startsWith(`${SUCCESS_COOKIE}=`));

test("a successful submission sets the proof cookies on the answer — and the JSON body the browser reads has no proof in it", async () => {
  laravelReplies(created, 201);
  const res = await handleApplicationPost(multipart(), SLUG);

  assert.equal(res.status, 200);
  assert.equal(res.headers.get("cache-control"), "no-store");
  const lines = cookiesOf(res);
  assert.equal(lines.length, 2, "one cookie per language path");
  for (const line of lines) assert.match(line, /; HttpOnly/);

  const text = await res.text();
  assert.deepEqual(JSON.parse(text), { status: "success" });
  assert.doesNotMatch(text, new RegExp(PROOF.slice(0, 20)));
  assert.doesNotMatch(text, /VOL-2026-0042/, "not even the application number is echoed to the browser");
});

test("a rejected application sets no proof cookie", async () => {
  laravelReplies({ message: "invalid", errors: { applicant_phone: ["x"] } }, 422);
  const invalid = await handleApplicationPost(multipart(), SLUG);
  assert.equal((await invalid.json()).status, "validation");
  assert.equal(cookiesOf(invalid).length, 0);

  laravelReplies({ message: "boom" }, 500);
  const failed = await handleApplicationPost(multipart(), SLUG);
  assert.equal((await failed.json()).status, "error");
  assert.equal(cookiesOf(failed).length, 0);
});

test("a request the guard refuses never reaches Laravel and never gets a cookie", async () => {
  laravelReplies(created, 201);
  const res = await handleApplicationPost(new Request(ENDPOINT, { method: "POST", body: sampleForm(), headers: { ...BROWSER, "sec-fetch-site": "cross-site" } }), SLUG);

  assert.equal(res.status, 403);
  assert.equal(cookiesOf(res).length, 0);
  assert.equal(calls.length, 0);
});

test("a success without a proof from Laravel sets no cookie (the confirmation then shows no group)", async () => {
  laravelReplies({ data: { application_no: "VOL-2026-0042" } }, 201);
  const res = await handleApplicationPost(multipart(), SLUG);

  assert.equal((await res.json()).status, "success");
  assert.equal(cookiesOf(res).length, 0);
});
