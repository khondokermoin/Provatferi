/**
 * Run with: npm run test:api (see package.json)
 *
 * The four forms that used to submit their photo through a Server Action — committee registration, committee
 * correction, membership application, member profile — as same-origin route handlers (lib/upload-handlers.ts).
 * The shared guard itself is pinned in upload-route.test.mts; here is what each form's handler adds on top of
 * it: which ONE upstream path it talks to, where its token comes from, what it forwards, what the form is told.
 */
import { test, before, after, beforeEach, mock } from "node:test";
import assert from "node:assert/strict";
import { handleCommitteeCorrectionPost, handleCommitteeRegistrationPost, handleMemberProfilePost, handleMembershipApplicationPost } from "../../upload-handlers.ts";
import { MAX_PHOTO_FORM_BYTES } from "../../upload-route.ts";
import { COMMITTEE_CORRECTION_FAILURE, COMMITTEE_REGISTRATION_FAILURE, MEMBER_PROFILE_FAILURE, MEMBERSHIP_FAILURE } from "../../form-messages.ts";
import { PHOTO_TOO_LARGE } from "../../form-state.ts";

const ORIGINAL_ENV = process.env.LARAVEL_API_URL;
const ORIGINAL_FETCH = globalThis.fetch;
const ORIGINAL_ERROR = console.error;

before(() => {
  process.env.LARAVEL_API_URL = "https://admin.example.test";
  console.error = () => undefined; // the API client logs every failure it turns into a state; the tests provoke them on purpose
});
after(() => {
  process.env.LARAVEL_API_URL = ORIGINAL_ENV;
  globalThis.fetch = ORIGINAL_FETCH;
  console.error = ORIGINAL_ERROR;
});

type Call = { url: string; init: RequestInit };
let calls: Call[] = [];

function laravelReplies(body: unknown, status: number) {
  calls = [];
  globalThis.fetch = mock.fn(async (url: string | URL | Request, init?: RequestInit) => {
    calls.push({ url: String(url), init: init ?? {} });
    return new Response(JSON.stringify(body), { status, headers: { "Content-Type": "application/json" } });
  }) as typeof fetch;
}
function laravelIsDown() {
  calls = [];
  globalThis.fetch = mock.fn(async (url: string | URL | Request, init?: RequestInit) => {
    calls.push({ url: String(url), init: init ?? {} });
    throw new TypeError("fetch failed");
  }) as typeof fetch;
}
beforeEach(() => {
  calls = [];
});

const SITE = "https://provatferi.org";
/** What a browser's fetch() sends from the site's own page. */
const BROWSER = { origin: SITE, "sec-fetch-site": "same-origin" };
const MEMBER_TOKEN = "1|memberSessionTokenFromTheHttpOnlyCookie";

/** A photo-shaped file whose bytes hold what Cloudflare's rule hunts for: `"$F` and `'$F`, early in the body. */
const TRIGGER_BYTES = new Uint8Array([0xff, 0xd8, 0xff, 0xe0, 0x22, 0x24, 0x46, 0x19, 0x27, 0x24, 0x46, 0x00, 0xff, 0xd9]);
/** File contents can only be read asynchronously; browserPost() needs them synchronously, so the bytes are remembered here. */
const FILE_BYTES = new WeakMap<File, Uint8Array>();
const photoFile = (bytes: Uint8Array<ArrayBuffer> = TRIGGER_BYTES) => {
  const file = new File([bytes], "me.jpg", { type: "image/jpeg" });
  FILE_BYTES.set(file, bytes);
  return file;
};

function committeeForm(): FormData {
  const form = new FormData();
  form.set("committee_position_id", "3");
  form.set("full_name", "Rahim Uddin");
  form.set("email", "rahim@example.test");
  form.set("phone", "01712345678");
  form.set("provatferi_comment", "Glad to help.");
  form.set("publishing_consent", "1");
  form.set("accuracy_declaration", "1");
  form.set("photo", photoFile());
  return form;
}
function membershipForm(): FormData {
  const form = new FormData();
  form.set("membership_season_id", "2");
  form.set("membership_type_id", "5");
  form.set("applicant_name", "Rahim Uddin");
  form.set("applicant_email", "rahim@example.test");
  form.set("applicant_phone", "01712345678");
  form.set("photo", photoFile());
  return form;
}
function profileForm(): FormData {
  const form = new FormData();
  form.set("public_profile_enabled", "1");
  form.set("profession", "Teacher");
  form.set("bio", "Hello.");
  form.set("photo", photoFile());
  return form;
}

const post = (path: string, body: FormData | string, headers: Record<string, string> = BROWSER) => new Request(`${SITE}${path}`, { method: "POST", body, headers });

/**
 * A multipart request serialised the way a BROWSER does it — which is not how Node's FormData does it for an
 * untouched <input type="file">: Chrome and Firefox send that as a part with `filename=""`, an octet-stream type
 * and no bytes, and the server parses it as a File of size 0 (a Node-built empty File has no filename at all and
 * would parse as an empty string, which proves nothing). `untouched` names such inputs.
 */
function browserPost(path: string, form: FormData, untouched: string[] = [], headers: Record<string, string> = BROWSER): Request {
  const boundary = "----WebKitFormBoundaryQa9Zx3";
  const text = new TextEncoder();
  const chunks: Uint8Array[] = [];
  const part = (head: string, bytes: Uint8Array = new Uint8Array()) => chunks.push(text.encode(`--${boundary}\r\n${head}\r\n\r\n`), bytes, text.encode("\r\n"));
  for (const [name, value] of form.entries()) {
    if (typeof value === "string") part(`Content-Disposition: form-data; name="${name}"`, text.encode(value));
    else part(`Content-Disposition: form-data; name="${name}"; filename="${value.name}"\r\nContent-Type: ${value.type || "application/octet-stream"}`, FILE_BYTES.get(value) ?? new Uint8Array());
  }
  for (const name of untouched) part(`Content-Disposition: form-data; name="${name}"; filename=""\r\nContent-Type: application/octet-stream`);
  chunks.push(text.encode(`--${boundary}--\r\n`));
  return new Request(`${SITE}${path}`, { method: "POST", body: Buffer.concat(chunks), headers: { ...headers, "content-type": `multipart/form-data; boundary=${boundary}` } });
}

/** The one table every form is held to: its handler, its own endpoint, its own upstream path and generic failure. */
interface Case {
  name: string;
  endpoint: string;
  upstream: string;
  failure: string;
  form: () => FormData;
  created: unknown;
  handle: (request: Request) => Promise<Response>;
}
const TOKEN = "tok-AbC123";
const CASES: Case[] = [
  {
    name: "committee registration",
    endpoint: `/api/committee/register/${TOKEN}`,
    upstream: "https://admin.example.test/api/v1/public/committee-submissions",
    failure: COMMITTEE_REGISTRATION_FAILURE,
    form: committeeForm,
    created: { data: { id: 11 } },
    handle: (request) => handleCommitteeRegistrationPost(request, TOKEN),
  },
  {
    name: "committee correction",
    endpoint: `/api/committee/correct/${TOKEN}`,
    upstream: `https://admin.example.test/api/v1/public/committee-submissions/correction/${TOKEN}`,
    failure: COMMITTEE_CORRECTION_FAILURE,
    form: committeeForm,
    created: { data: { id: 11 } },
    handle: (request) => handleCommitteeCorrectionPost(request, TOKEN),
  },
  {
    name: "membership application",
    endpoint: "/api/membership/apply",
    upstream: "https://admin.example.test/api/v1/public/membership/applications",
    failure: MEMBERSHIP_FAILURE,
    form: membershipForm,
    created: { data: { application_no: "M-2026-0042" } },
    handle: (request) => handleMembershipApplicationPost(request),
  },
  {
    name: "member profile",
    endpoint: "/api/member/profile",
    upstream: "https://admin.example.test/api/v1/member/profile",
    failure: MEMBER_PROFILE_FAILURE,
    form: profileForm,
    created: { message: "Saved." },
    handle: (request) => handleMemberProfilePost(request, MEMBER_TOKEN),
  },
];

const photoOf = (call: Call) => (call.init.body as FormData).get("photo") as File;

for (const c of CASES) {
  // -------------------------------------------------------------------------
  // The same guarantees, for each of the four
  // -------------------------------------------------------------------------

  test(`${c.name}: success is 200 JSON that must never be cached, one upstream call, to its own path only`, async () => {
    laravelReplies(c.created, 201);
    const res = await c.handle(post(c.endpoint, c.form()));

    assert.equal(res.status, 200);
    assert.equal(res.headers.get("cache-control"), "no-store");
    assert.match(res.headers.get("content-type") ?? "", /application\/json/);
    assert.equal((await res.json()).status, "success");
    assert.equal(calls.length, 1, "exactly one request to Laravel per submission");
    assert.equal(calls[0].url, c.upstream);
    assert.equal(calls[0].init.method, "POST");
  });

  test(`${c.name}: nothing in the request can choose where it is forwarded (no open proxy)`, async () => {
    laravelReplies(c.created, 201);
    const form = c.form();
    for (const name of ["url", "path", "upstream", "target", "endpoint", "_next", "redirect", "callback"]) form.set(name, "https://evil.example/steal");
    const res = await c.handle(post(`${c.endpoint}?url=https://evil.example&path=/x`, form, { ...BROWSER, "x-forwarded-uri": "/somewhere/else", "x-original-url": "https://evil.example" }));

    assert.equal((await res.json()).status, "success");
    assert.equal(calls.length, 1);
    assert.equal(calls[0].url, c.upstream, "the upstream is fixed by the route, not by any field, query string or header");
  });

  test(`${c.name}: the photo — including bytes that hold \`"$F\` and \`'$F\` — reaches Laravel byte for byte; an untouched file input is dropped, not forwarded empty`, async () => {
    laravelReplies(c.created, 201);
    await c.handle(browserPost(c.endpoint, c.form(), ["cv_or_other_optional_file"]));

    const sent = calls[0].init.body as FormData;
    assert.deepEqual(new Uint8Array(await photoOf(calls[0]).arrayBuffer()), TRIGGER_BYTES);
    assert.equal(sent.has("cv_or_other_optional_file"), false, "an empty File is genuinely omitted before forwarding");
    assert.equal(sent.get(c.name === "member profile" ? "profession" : c.name === "membership application" ? "applicant_name" : "full_name"), c.name === "member profile" ? "Teacher" : "Rahim Uddin");
  });

  test(`${c.name}: an optional photo left untouched is omitted entirely`, async () => {
    laravelReplies(c.created, 201);
    const form = c.form();
    form.delete("photo");
    const res = await c.handle(browserPost(c.endpoint, form, ["photo"]));

    assert.equal((await res.json()).status, "success");
    assert.equal((calls[0].init.body as FormData).has("photo"), false, "an untouched optional file input reaches Laravel as a genuinely absent field");
  });

  test(`${c.name}: Laravel's 422 reaches the form as field messages, untouched, in a 200 an edge layer will not replace`, async () => {
    laravelReplies({ message: "invalid", errors: { photo: ["ছবিটি সঠিক নয়।"], phone: ["সঠিক মোবাইল নম্বর লিখুন।"] } }, 422);
    const res = await c.handle(post(c.endpoint, c.form()));

    assert.equal(res.status, 200);
    assert.deepEqual(await res.json(), { status: "validation", errors: { photo: ["ছবিটি সঠিক নয়।"], phone: ["সঠিক মোবাইল নম্বর লিখুন।"] } });
  });

  test(`${c.name}: any other failure is the form's own generic message and never an upstream body`, async () => {
    for (const status of [500, 404, 403]) {
      laravelReplies({ message: "SQLSTATE[42S02]: table missing — internal detail" }, status);
      const res = await c.handle(post(c.endpoint, c.form()));
      const text = await res.text();

      assert.equal(res.status, 200, String(status));
      assert.deepEqual(JSON.parse(text), { status: "error", message: c.failure }, String(status));
      assert.doesNotMatch(text, /SQLSTATE|internal detail/);
    }
  });

  test(`${c.name}: an unreachable Laravel is an error state, not an exception`, async () => {
    laravelIsDown();
    const res = await c.handle(post(c.endpoint, c.form()));
    assert.equal(res.status, 200);
    assert.deepEqual(await res.json(), { status: "error", message: c.failure });
  });

  test(`${c.name}: a cross-site request, a non-multipart body and an oversized body are refused before anything is forwarded`, async () => {
    laravelReplies(c.created, 201);
    const cross = await c.handle(post(c.endpoint, c.form(), { "sec-fetch-site": "cross-site", origin: "https://evil.example" }));
    assert.equal(cross.status, 403);
    const bare = await c.handle(post(c.endpoint, c.form(), {}));
    assert.equal(bare.status, 403, "no Origin and no Fetch Metadata: not a browser page");
    const json = await c.handle(new Request(`${SITE}${c.endpoint}`, { method: "POST", body: "{}", headers: { ...BROWSER, "content-type": "application/json" } }));
    assert.equal(json.status, 415);
    const huge = await c.handle(new Request(`${SITE}${c.endpoint}`, { method: "POST", body: "x", headers: { ...BROWSER, "content-type": "multipart/form-data; boundary=b", "content-length": String(MAX_PHOTO_FORM_BYTES + 1) } }));
    assert.equal(huge.status, 413);
    assert.deepEqual(await huge.json(), { status: "validation", errors: PHOTO_TOO_LARGE }, "too large shows as the photo's own problem");
    assert.equal(calls.length, 0, "none of those reached Laravel");
  });
}

// ---------------------------------------------------------------------------
// What is specific to each form
// ---------------------------------------------------------------------------

test("committee registration: the token is the path's own — a registration_token field in the body can neither add nor replace it", async () => {
  laravelReplies({ data: { id: 11 } }, 201);
  const form = committeeForm();
  form.set("registration_token", "someone-elses-link");
  form.append("registration_token", "another");
  await handleCommitteeRegistrationPost(post(`/api/committee/register/${TOKEN}`, form), TOKEN);

  const sent = calls[0].init.body as FormData;
  assert.deepEqual(sent.getAll("registration_token"), [TOKEN], "exactly one value, the path's");
});

test("committee correction: the token goes into the upstream PATH, percent-encoded; it is never read from the body", async () => {
  laravelReplies({ data: { id: 11 } }, 200);
  const form = committeeForm();
  form.set("registration_token", "from-the-body");
  await handleCommitteeCorrectionPost(post("/api/committee/correct/x", form), "a b/c?d#e");

  assert.equal(calls[0].url, "https://admin.example.test/api/v1/public/committee-submissions/correction/a%20b%2Fc%3Fd%23e");
});

test("committee correction: a spent or unknown single-use token (Laravel's 422/404) is shown, not turned into a success", async () => {
  laravelReplies({ message: "invalid", errors: { token: ["লিংকটি আর বৈধ নয়।"] } }, 422);
  const res = await handleCommitteeCorrectionPost(post(`/api/committee/correct/${TOKEN}`, committeeForm()), TOKEN);
  assert.equal((await res.json()).status, "validation");

  laravelReplies({ message: "Not found" }, 404);
  const gone = await handleCommitteeCorrectionPost(post(`/api/committee/correct/${TOKEN}`, committeeForm()), TOKEN);
  assert.deepEqual(await gone.json(), { status: "error", message: COMMITTEE_CORRECTION_FAILURE });
});

test("membership application: success carries the application number Laravel issued; a 2xx of the wrong shape is a failure, not a success", async () => {
  laravelReplies({ data: { application_no: "M-2026-0042" } }, 201);
  const ok = await handleMembershipApplicationPost(post("/api/membership/apply", membershipForm()));
  assert.deepEqual(await ok.json(), { status: "success", applicationNo: "M-2026-0042" });

  laravelReplies({ data: {} }, 201);
  const odd = await handleMembershipApplicationPost(post("/api/membership/apply", membershipForm()));
  assert.deepEqual(await odd.json(), { status: "error", message: MEMBERSHIP_FAILURE });
});

test("membership application: the honeypot field is forwarded as-is — Laravel, not this hop, decides it is spam", async () => {
  laravelReplies({ message: "invalid", errors: { website: ["spam"] } }, 422);
  const form = membershipForm();
  form.set("website", "http://bot.example");
  const res = await handleMembershipApplicationPost(post("/api/membership/apply", form));

  assert.equal(((calls[0].init.body as FormData).get("website")), "http://bot.example");
  assert.equal((await res.json()).status, "validation");
});

test("member profile: no session is a 401 'unauthenticated' BEFORE the upload is read or anything is forwarded", async () => {
  laravelReplies({ message: "Saved." }, 200);
  const request = post("/api/member/profile", profileForm());
  const res = await handleMemberProfilePost(request, null);

  assert.equal(res.status, 401);
  assert.equal(res.headers.get("cache-control"), "no-store");
  assert.deepEqual(await res.json(), { status: "unauthenticated" });
  assert.equal(request.bodyUsed, false, "a request with no session never has its upload buffered");
  assert.equal(calls.length, 0);
});

test("member profile: the Bearer is the cookie's token — a client-supplied Authorization header or token field cannot replace it", async () => {
  laravelReplies({ message: "Saved." }, 200);
  const form = profileForm();
  form.set("token", "attacker-token");
  form.set("authorization", "Bearer attacker-token");
  await handleMemberProfilePost(post("/api/member/profile", form, { ...BROWSER, authorization: "Bearer attacker-token", cookie: "member_session=attacker-token" }), MEMBER_TOKEN);

  const headers = calls[0].init.headers as Record<string, string>;
  assert.equal(headers.Authorization, `Bearer ${MEMBER_TOKEN}`);
  assert.equal(calls[0].url, "https://admin.example.test/api/v1/member/profile");
});

test("member profile: Laravel refusing the session (401) is the form's generic error here, never a leaked body", async () => {
  laravelReplies({ message: "Unauthenticated." }, 401);
  const res = await handleMemberProfilePost(post("/api/member/profile", profileForm()), MEMBER_TOKEN);
  assert.deepEqual(await res.json(), { status: "error", message: MEMBER_PROFILE_FAILURE });
});

test("member profile: a request from another site is refused even WITH a valid session", async () => {
  laravelReplies({ message: "Saved." }, 200);
  const res = await handleMemberProfilePost(post("/api/member/profile", profileForm(), { "sec-fetch-site": "cross-site", origin: "https://evil.example" }), MEMBER_TOKEN);

  assert.equal(res.status, 403);
  assert.equal(calls.length, 0);
});
