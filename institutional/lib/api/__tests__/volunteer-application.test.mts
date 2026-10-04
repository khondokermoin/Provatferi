/**
 * Run with: npm run test:api (see package.json)
 *
 * The volunteer application's server half: what it forwards to Laravel, what state it hands the form back,
 * and the endpoint the form's JavaScript posts to (lib/volunteer-application-post.ts).
 */
import { test, before, after, beforeEach, mock } from "node:test";
import assert from "node:assert/strict";
import { forwardApplication } from "../../volunteer-application.ts";
import { handleApplicationPost, MAX_BODY_BYTES } from "../../volunteer-application-post.ts";

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

function laravelReplies(body: unknown, status: number, headers: Record<string, string> = {}) {
  calls = [];
  globalThis.fetch = mock.fn(async (url: string | URL | Request, init?: RequestInit) => {
    calls.push({ url: String(url), init: init ?? {} });
    return new Response(JSON.stringify(body), { status, headers: { "Content-Type": "application/json", ...headers } });
  }) as typeof fetch;
}
beforeEach(() => {
  calls = [];
});

const created = { data: { application_no: "VOL-2026-0042" } };

function sampleForm(): FormData {
  const form = new FormData();
  form.set("applicant_name", "Rahim Uddin");
  form.set("applicant_email", "rahim@example.test");
  form.set("applicant_phone", "01712345678");
  form.set("experience", "Ran the book fair desk.");
  form.append("skills[]", "writing");
  form.append("skills[]", "events");
  form.set("accuracy_declaration", "1");
  form.set("privacy_consent", "1");
  form.set("submission_token", "7c9e6679-7425-40de-944b-e07fc1f90ae7");
  form.set("photo", new File([new Uint8Array([0xff, 0xd8, 0xff, 0xe0, 1, 2, 3])], "me.jpg", { type: "image/jpeg" }));
  form.set("cv", new File([], "", { type: "application/octet-stream" })); // an untouched file input
  return form;
}

// ---------------------------------------------------------------------------
// forwardApplication
// ---------------------------------------------------------------------------

test("a 201 from Laravel becomes success; the posting slug is encoded into the path, never taken from the form", async () => {
  laravelReplies(created, 201);
  const form = sampleForm();
  form.set("slug", "someone-elses-posting"); // an editable field must not retarget the submission
  const { state, timing } = await forwardApplication("a b/c", form, false);

  assert.deepEqual(state, { status: "success" });
  assert.equal(timing, null, "no timing unless the caller opted in");
  assert.equal(calls.length, 1);
  assert.equal(calls[0].url, "https://admin.example.test/api/v1/public/recruitment/a%20b%2Fc/applications");
  assert.equal((calls[0].init.headers as Record<string, string>)["X-Pf-Timing"], undefined);
});

test("the multipart body goes on to Laravel with the token, the photo and no empty file part", async () => {
  laravelReplies(created, 201);
  await forwardApplication("posting", sampleForm(), false);

  const sent = calls[0].init.body as FormData;
  assert.equal(sent.get("submission_token"), "7c9e6679-7425-40de-944b-e07fc1f90ae7");
  assert.ok(sent.get("photo") instanceof File);
  assert.equal(sent.has("cv"), false, "an untouched CV input is dropped, not forwarded as an empty file");
});

test("a 422 becomes validation, with Laravel's messages and everything typed handed back (files excluded)", async () => {
  laravelReplies({ message: "invalid", errors: { applicant_phone: ["সঠিক মোবাইল নম্বর লিখুন।"] } }, 422);
  const { state } = await forwardApplication("posting", sampleForm(), false);

  assert.equal(state.status, "validation");
  if (state.status !== "validation") return;
  assert.deepEqual(state.errors, { applicant_phone: ["সঠিক মোবাইল নম্বর লিখুন।"] });
  assert.equal(state.values.applicant_name, "Rahim Uddin");
  assert.equal(state.values.experience, "Ran the book fair desk.");
  assert.deepEqual(state.skills, ["writing", "events"]);
  assert.deepEqual(state.consents, ["accuracy_declaration", "privacy_consent"]);
  assert.equal("photo" in state.values, false);
  assert.equal("submission_token" in state.values, false);
});

test("any other failure becomes a generic error that still hands the typed text back", async () => {
  laravelReplies({ message: "boom" }, 500);
  const { state } = await forwardApplication("posting", sampleForm(), false);

  assert.equal(state.status, "error");
  if (state.status !== "error") return;
  assert.match(state.message, /আবেদন জমা দেওয়া যায়নি/);
  assert.equal(state.values.applicant_email, "rahim@example.test");
  assert.doesNotMatch(JSON.stringify(state), /boom/, "an upstream error body never reaches the visitor");
});

test("an unreachable Laravel is an error state, not an exception", async () => {
  globalThis.fetch = mock.fn(async () => {
    throw new TypeError("fetch failed");
  }) as typeof fetch;
  const { state } = await forwardApplication("posting", sampleForm(), false);
  assert.equal(state.status, "error");
});

test("the timing flag is forwarded and the phases come back as durations only — no applicant data", async () => {
  laravelReplies(created, 201, { "Server-Timing": "boot;dur=12.0, db;dur=3.1, total;dur=40.2" });
  const { timing } = await forwardApplication("posting", sampleForm(), true);

  assert.equal((calls[0].init.headers as Record<string, string>)["X-Pf-Timing"], "1");
  assert.ok(timing);
  const parsed = JSON.parse(timing);
  assert.equal(parsed.laravel, "boot;dur=12.0, db;dur=3.1, total;dur=40.2");
  assert.equal(typeof parsed.actionMs, "number");
  assert.doesNotMatch(timing, /Rahim|rahim@example|01712345678|VOL-2026/);
});

// ---------------------------------------------------------------------------
// The endpoint the form's JavaScript posts to
// ---------------------------------------------------------------------------

const ENDPOINT = "https://provatferi.org/api/recruitment/posting/apply";
// What a browser's fetch() sends from the site's own page; the shared guard (lib/upload-route.ts) refuses a request without it.
const BROWSER = { origin: "https://provatferi.org", "sec-fetch-site": "same-origin" };
const multipart = (form: FormData, headers: Record<string, string> = {}) => new Request(ENDPOINT, { method: "POST", body: form, headers: { ...BROWSER, ...headers } });

test("the endpoint answers success as 200 JSON that must never be cached", async () => {
  laravelReplies(created, 201);
  const res = await handleApplicationPost(multipart(sampleForm()), "posting");

  assert.equal(res.status, 200);
  assert.equal(res.headers.get("cache-control"), "no-store");
  assert.match(res.headers.get("content-type") ?? "", /application\/json/);
  assert.deepEqual(await res.json(), { status: "success" });
  assert.equal((calls[0].init.body as FormData).get("applicant_name"), "Rahim Uddin", "the fields survive the trip through the endpoint");
  assert.ok((calls[0].init.body as FormData).get("photo") instanceof File);
});

test("a validation failure is a 200 the form can show, not a 4xx an edge layer might replace with its own page", async () => {
  laravelReplies({ message: "invalid", errors: { applicant_email: ["x"] } }, 422);
  const res = await handleApplicationPost(multipart(sampleForm()), "posting");

  assert.equal(res.status, 200);
  const state = await res.json();
  assert.equal(state.status, "validation");
  assert.deepEqual(state.errors, { applicant_email: ["x"] });
});

test("opting in to timing sets the short-lived pf_timing cookie; a normal visitor never gets it", async () => {
  laravelReplies(created, 201);
  const plain = await handleApplicationPost(multipart(sampleForm()), "posting");
  assert.equal(plain.headers.get("set-cookie"), null);

  laravelReplies(created, 201);
  const timed = await handleApplicationPost(multipart(sampleForm(), { "x-pf-timing": "1" }), "posting");
  const cookie = timed.headers.get("set-cookie") ?? "";
  assert.match(cookie, /^pf_timing=/);
  assert.match(cookie, /Max-Age=60/);
  assert.doesNotMatch(cookie, /Rahim|rahim@example/);
});

test("a request started by another site's page is refused before anything is forwarded", async () => {
  laravelReplies(created, 201);
  const res = await handleApplicationPost(multipart(sampleForm(), { "sec-fetch-site": "cross-site" }), "posting");

  assert.equal(res.status, 403);
  assert.equal((await res.json()).status, "error");
  assert.equal(calls.length, 0);
});

test("only multipart bodies are accepted", async () => {
  laravelReplies(created, 201);
  const res = await handleApplicationPost(new Request(ENDPOINT, { method: "POST", body: "{}", headers: { ...BROWSER, "content-type": "application/json" } }), "posting");

  assert.equal(res.status, 415);
  assert.equal(calls.length, 0);
});

test("a body larger than any legitimate submission is refused without being read — declared size", async () => {
  laravelReplies(created, 201);
  const res = await handleApplicationPost(
    new Request(ENDPOINT, { method: "POST", body: "x", headers: { ...BROWSER, "content-type": "multipart/form-data; boundary=b", "content-length": String(MAX_BODY_BYTES + 1) } }),
    "posting",
  );

  assert.equal(res.status, 413);
  assert.equal(calls.length, 0);
});

test("...and when no size is declared, the stream is cut off at the limit", async () => {
  laravelReplies(created, 201);
  const megabyte = new Uint8Array(1024 * 1024);
  let sent = 0;
  const stream = new ReadableStream<Uint8Array>({
    pull(controller) {
      if (sent >= 20) return controller.close();
      sent++;
      controller.enqueue(megabyte);
    },
  });
  const request = new Request(ENDPOINT, { method: "POST", body: stream, headers: { ...BROWSER, "content-type": "multipart/form-data; boundary=b" }, duplex: "half" } as RequestInit);
  const res = await handleApplicationPost(request, "posting");

  assert.equal(res.status, 413);
  assert.ok(sent < 20, `the endpoint kept reading to the end (${sent} MB) instead of stopping at ${MAX_BODY_BYTES / 1048576} MB`);
  assert.equal(calls.length, 0);
});

test("a body that is not valid multipart is a 400 error state, nothing forwarded", async () => {
  laravelReplies(created, 201);
  const res = await handleApplicationPost(
    new Request(ENDPOINT, { method: "POST", body: "this is not multipart", headers: { ...BROWSER, "content-type": "multipart/form-data; boundary=zzz" } }),
    "posting",
  );

  assert.equal(res.status, 400);
  assert.equal(calls.length, 0);
});

test("a photo whose bytes contain the sequence Cloudflare's rule hunts for still goes through the endpoint untouched", async () => {
  // `"$F` is what the managed WAF rule matches on a Server Action request. This endpoint carries no
  // Next-Action header, so the bytes must simply be forwarded, byte for byte.
  laravelReplies(created, 201);
  const bytes = new Uint8Array([0xff, 0xd8, 0x22, 0x24, 0x46, 0x19, 0x27, 0x24, 0x46, 0x00, 0xff, 0xd9]);
  const form = sampleForm();
  form.set("photo", new File([bytes], "tricky.jpg", { type: "image/jpeg" }));
  const res = await handleApplicationPost(multipart(form), "posting");

  assert.equal((await res.json()).status, "success");
  const forwarded = (calls[0].init.body as FormData).get("photo") as File;
  assert.deepEqual(new Uint8Array(await forwarded.arrayBuffer()), bytes);
});
