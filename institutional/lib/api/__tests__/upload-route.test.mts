/**
 * Run with: npm run test:api (see package.json)
 *
 * The guard every file-upload route handler goes through (lib/upload-route.ts) and the form state it answers with
 * (lib/form-state.ts). The four forms moved off Server Actions (committee registration, committee correction,
 * membership application, member profile) and the volunteer form all depend on exactly these rules, so they are
 * pinned here once instead of once per form.
 */
import { test } from "node:test";
import assert from "node:assert/strict";
import { isFormState, isMemberProfileAnswer, isMembershipFormState, PHOTO_TOO_LARGE } from "../../form-state.ts";
import { handleUpload, isSameOrigin, jsonReply, MAX_PHOTO_FORM_BYTES, photoFormRefusal, readUploadForm, stateFromResult } from "../../upload-route.ts";

const SITE = "https://provatferi.org";
const ENDPOINT = `${SITE}/api/membership/apply`;
/** What a browser's fetch() sends from the site's own page. */
const BROWSER = { origin: SITE, "sec-fetch-site": "same-origin" };

function sampleForm(): FormData {
  const form = new FormData();
  form.set("applicant_name", "Rahim Uddin");
  form.set("photo", new File([new Uint8Array([0xff, 0xd8, 0xff, 0xe0, 1, 2, 3])], "me.jpg", { type: "image/jpeg" }));
  return form;
}
const post = (headers: Record<string, string> = BROWSER, body: BodyInit = sampleForm()) => new Request(ENDPOINT, { method: "POST", body, headers });

type S = { status: "ok" } | { status: "refused"; why: string };
const ok: { state: S } = { state: { status: "ok" } };
const refuse = (why: string): S => ({ status: "refused", why });

// ---------------------------------------------------------------------------
// isSameOrigin — what a browser can prove, and nothing a script can fake from another site
// ---------------------------------------------------------------------------

test("same-origin: Fetch Metadata alone, or an Origin that is this host, is enough", () => {
  assert.equal(isSameOrigin(post({ "sec-fetch-site": "same-origin" })), true, "Sec-Fetch-Site same-origin with no Origin header");
  assert.equal(isSameOrigin(post({ origin: SITE })), true, "an Origin that is this host, from a browser that sends no Fetch Metadata");
  assert.equal(isSameOrigin(post(BROWSER)), true);
});

test("same-origin: behind the reverse proxy the visitor's host (x-forwarded-host / host) counts too", () => {
  const internal = (headers: Record<string, string>) => new Request("http://127.0.0.1:3000/api/membership/apply", { method: "POST", body: sampleForm(), headers });
  assert.equal(isSameOrigin(internal({ origin: SITE, "x-forwarded-host": "provatferi.org" })), true);
  assert.equal(isSameOrigin(internal({ origin: SITE, host: "provatferi.org" })), true);
  assert.equal(isSameOrigin(internal({ origin: "https://evil.example", "x-forwarded-host": "provatferi.org" })), false);
});

test("same-origin: another site, a sibling subdomain, a typed URL, a mismatched or null Origin are all refused", () => {
  assert.equal(isSameOrigin(post({ "sec-fetch-site": "cross-site" })), false);
  assert.equal(isSameOrigin(post({ "sec-fetch-site": "same-site", origin: "https://admin.provatferi.org" })), false, "a sibling subdomain is not this origin");
  assert.equal(isSameOrigin(post({ "sec-fetch-site": "none" })), false);
  assert.equal(isSameOrigin(post({ origin: "https://evil.example" })), false);
  assert.equal(isSameOrigin(post({ "sec-fetch-site": "same-origin", origin: "https://evil.example" })), false, "both must agree: a forged Fetch-Metadata value does not excuse a foreign Origin");
  assert.equal(isSameOrigin(post({ origin: "null" })), false, "a sandboxed or privacy-stripped origin");
  assert.equal(isSameOrigin(post({ origin: "not a url" })), false);
  assert.equal(isSameOrigin(post({})), false, "a request carrying neither header comes from no browser page");
});

// ---------------------------------------------------------------------------
// readUploadForm — multipart only, a hard ceiling, parseable
// ---------------------------------------------------------------------------

test("a same-origin multipart post is parsed; the fields and the file come out intact", async () => {
  const result = await readUploadForm(post(), MAX_PHOTO_FORM_BYTES);
  assert.equal(result.ok, true);
  if (!result.ok) return;
  assert.equal(result.formData.get("applicant_name"), "Rahim Uddin");
  const photo = result.formData.get("photo");
  assert.ok(photo instanceof File);
  assert.deepEqual(new Uint8Array(await photo.arrayBuffer()), new Uint8Array([0xff, 0xd8, 0xff, 0xe0, 1, 2, 3]));
});

test("only multipart bodies are accepted — JSON, plain text and no content type are 'not-multipart'", async () => {
  for (const [type, body] of [["application/json", "{}"], ["text/plain", "hello"], ["application/x-www-form-urlencoded", "a=1"]] as const) {
    const result = await readUploadForm(new Request(ENDPOINT, { method: "POST", body, headers: { ...BROWSER, "content-type": type } }), MAX_PHOTO_FORM_BYTES);
    assert.deepEqual(result, { ok: false, refusal: "not-multipart" }, type);
  }
  const bare = await readUploadForm(new Request(ENDPOINT, { method: "POST", headers: BROWSER }), MAX_PHOTO_FORM_BYTES);
  assert.deepEqual(bare, { ok: false, refusal: "not-multipart" });
});

test("the content type is matched case-insensitively (a header is not case-sensitive)", async () => {
  const boundary = "XyZ";
  const body = `--${boundary}\r\nContent-Disposition: form-data; name="a"\r\n\r\n1\r\n--${boundary}--\r\n`;
  const result = await readUploadForm(new Request(ENDPOINT, { method: "POST", body, headers: { ...BROWSER, "content-type": `Multipart/Form-Data; boundary=${boundary}` } }), MAX_PHOTO_FORM_BYTES);
  assert.equal(result.ok, true);
  if (result.ok) assert.equal(result.formData.get("a"), "1");
});

test("a body bigger than the ceiling is refused on its declared size, without reading it", async () => {
  const request = new Request(ENDPOINT, { method: "POST", body: "x", headers: { ...BROWSER, "content-type": "multipart/form-data; boundary=b", "content-length": String(MAX_PHOTO_FORM_BYTES + 1) } });
  assert.deepEqual(await readUploadForm(request, MAX_PHOTO_FORM_BYTES), { ok: false, refusal: "too-large" });
  assert.equal(request.bodyUsed, false, "nothing was buffered for a request that declared itself too big");
});

test("...and a body that declares no size is cut off at the ceiling while streaming, not buffered to the end", async () => {
  const megabyte = new Uint8Array(1024 * 1024);
  let sent = 0;
  const stream = new ReadableStream<Uint8Array>({
    pull(controller) {
      if (sent >= 40) return controller.close();
      sent++;
      controller.enqueue(megabyte);
    },
  });
  const request = new Request(ENDPOINT, { method: "POST", body: stream, headers: { ...BROWSER, "content-type": "multipart/form-data; boundary=b" }, duplex: "half" } as RequestInit);
  assert.deepEqual(await readUploadForm(request, MAX_PHOTO_FORM_BYTES), { ok: false, refusal: "too-large" });
  assert.ok(sent < 40, `kept reading to the end (${sent} MB) instead of stopping at ${MAX_PHOTO_FORM_BYTES / 1048576} MB`);
});

test("a body that is not valid multipart is 'unreadable', never an exception", async () => {
  const request = new Request(ENDPOINT, { method: "POST", body: "this is not multipart", headers: { ...BROWSER, "content-type": "multipart/form-data; boundary=zzz" } });
  assert.deepEqual(await readUploadForm(request, MAX_PHOTO_FORM_BYTES), { ok: false, refusal: "unreadable" });
});

test("the guard checks the origin before anything else — a cross-site post is refused unread", async () => {
  const request = post({ "sec-fetch-site": "cross-site" });
  assert.deepEqual(await readUploadForm(request, MAX_PHOTO_FORM_BYTES), { ok: false, refusal: "cross-site" });
  assert.equal(request.bodyUsed, false);
});

// ---------------------------------------------------------------------------
// handleUpload — the order of the steps, and what each refusal answers
// ---------------------------------------------------------------------------

test("a refused request never reaches the route's own upstream call, and answers JSON that must not be cached", async () => {
  let ran = 0;
  const run = async () => {
    ran++;
    return ok;
  };
  const cases: Array<[string, Request, number, string]> = [
    ["cross-site", post({ "sec-fetch-site": "cross-site" }), 403, "cross-site"],
    ["not-multipart", new Request(ENDPOINT, { method: "POST", body: "{}", headers: { ...BROWSER, "content-type": "application/json" } }), 415, "not-multipart"],
    ["too-large", new Request(ENDPOINT, { method: "POST", body: "x", headers: { ...BROWSER, "content-type": "multipart/form-data; boundary=b", "content-length": "99999999" } }), 413, "too-large"],
    ["unreadable", new Request(ENDPOINT, { method: "POST", body: "nope", headers: { ...BROWSER, "content-type": "multipart/form-data; boundary=b" } }), 400, "unreadable"],
  ];
  for (const [name, request, status, why] of cases) {
    const res = await handleUpload<S>(request, { maxBytes: MAX_PHOTO_FORM_BYTES, refuse, run });
    assert.equal(res.status, status, name);
    assert.equal(res.headers.get("cache-control"), "no-store", name);
    assert.match(res.headers.get("content-type") ?? "", /application\/json/, name);
    assert.deepEqual(await res.json(), { status: "refused", why }, name);
  }
  assert.equal(ran, 0, "run() must never be reached by a refused request");
});

test("authorize runs after the origin check and BEFORE the body is read — a login-less request never gets an upload buffered", async () => {
  let ran = 0;
  const request = post();
  const res = await handleUpload<S>(request, {
    maxBytes: MAX_PHOTO_FORM_BYTES,
    refuse,
    authorize: () => ({ state: refuse("no session"), status: 401 }),
    run: async () => {
      ran++;
      return ok;
    },
  });
  assert.equal(res.status, 401);
  assert.deepEqual(await res.json(), { status: "refused", why: "no session" });
  assert.equal(request.bodyUsed, false, "the 6 MB body was not touched");
  assert.equal(ran, 0);

  // ...and a cross-site request is told "cross-site" even without a session: the origin check comes first.
  const foreign = await handleUpload<S>(post({ "sec-fetch-site": "cross-site" }), {
    maxBytes: MAX_PHOTO_FORM_BYTES,
    refuse,
    authorize: () => ({ state: refuse("no session"), status: 401 }),
    run: async () => ok,
  });
  assert.equal(foreign.status, 403);
});

test("an allowed request runs the route's call with the parsed form and answers 200 with its state and extra headers", async () => {
  let seen: FormData | null = null;
  const res = await handleUpload<S>(post(), {
    maxBytes: MAX_PHOTO_FORM_BYTES,
    refuse,
    authorize: () => null,
    run: async (formData) => {
      seen = formData;
      return { state: { status: "ok" }, headers: { "Set-Cookie": "pf_timing=1; Max-Age=60" } };
    },
  });
  assert.equal(res.status, 200);
  assert.equal(res.headers.get("cache-control"), "no-store");
  assert.equal(res.headers.get("set-cookie"), "pf_timing=1; Max-Age=60");
  assert.deepEqual(await res.json(), { status: "ok" });
  assert.equal((seen as FormData | null)?.get("applicant_name"), "Rahim Uddin");
});

test("if the route's own call throws, the answer is a generic 500 state — no stack, no message, no hang", async () => {
  const res = await handleUpload<S>(post(), {
    maxBytes: MAX_PHOTO_FORM_BYTES,
    refuse,
    run: async () => {
      throw new Error("secret internal detail: db password");
    },
  });
  assert.equal(res.status, 500);
  const text = await res.text();
  assert.deepEqual(JSON.parse(text), { status: "refused", why: "unreadable" });
  assert.doesNotMatch(text, /secret internal detail|password/);
});

test("jsonReply is never cacheable, whatever headers the caller adds", async () => {
  const res = jsonReply({ a: 1 }, { status: 201, headers: { "Cache-Control": "public, max-age=600", "X-Extra": "1" } });
  assert.equal(res.status, 201);
  assert.equal(res.headers.get("cache-control"), "no-store");
  assert.equal(res.headers.get("x-extra"), "1");
});

// ---------------------------------------------------------------------------
// What a refusal and an upstream answer look like to the form
// ---------------------------------------------------------------------------

test("a too-large body shows as a photo field error; every other refusal is the form's generic message", () => {
  const refusal = photoFormRefusal("generic");
  assert.deepEqual(refusal("too-large"), { status: "validation", errors: PHOTO_TOO_LARGE });
  for (const why of ["cross-site", "not-multipart", "unreadable"] as const) assert.deepEqual(refusal(why), { status: "error", message: "generic" }, why);
});

test("Laravel's answer becomes the form's state: created -> success (+ its own payload), 422 -> field errors, anything else -> generic", () => {
  const options = { success: (data: { n: number }) => ({ applicationNo: `N${data.n}` }), failure: "generic" };
  assert.deepEqual(stateFromResult({ ok: true, data: { n: 7 } }, options), { status: "success", applicationNo: "N7" });
  assert.deepEqual(stateFromResult({ ok: false, error: "validation", errors: { photo: ["bad"] } }, options), { status: "validation", errors: { photo: ["bad"] } });
  for (const error of ["http_error", "network_error", "timeout", "invalid_json", "invalid_shape", "not_configured"] as const) {
    assert.deepEqual(stateFromResult({ ok: false, error }, options), { status: "error", message: "generic" }, error);
  }
});

// ---------------------------------------------------------------------------
// The state's own guards — what the browser trusts from the route's JSON
// ---------------------------------------------------------------------------

test("isFormState accepts exactly the shapes a route answers with, and nothing an edge page or proxy could produce", () => {
  assert.equal(isFormState({ status: "success" }), true);
  assert.equal(isFormState({ status: "validation", errors: { photo: ["x"] } }), true);
  assert.equal(isFormState({ status: "error", message: "m" }), true);
  for (const bad of [null, undefined, "success", 7, [], {}, { status: "idle" }, { status: "validation" }, { status: "validation", errors: null }, { status: "validation", errors: [] }, { status: "error" }, { status: "error", message: 3 }, { status: "weird" }]) {
    assert.equal(isFormState(bad), false, JSON.stringify(bad));
  }
});

test("a membership success must carry its application number; a profile answer may also be 'unauthenticated'", () => {
  assert.equal(isMembershipFormState({ status: "success", applicationNo: "M-1" }), true);
  assert.equal(isMembershipFormState({ status: "success" }), false);
  assert.equal(isMembershipFormState({ status: "success", applicationNo: 5 }), false);
  assert.equal(isMembershipFormState({ status: "error", message: "m" }), true);

  assert.equal(isMemberProfileAnswer({ status: "unauthenticated" }), true);
  assert.equal(isMemberProfileAnswer({ status: "success" }), true);
  assert.equal(isMemberProfileAnswer({ status: "nope" }), false);
});
