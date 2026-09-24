/**
 * Run with: npm run test:api (see package.json)
 *
 * Uses Node's built-in test runner — this project has no test framework
 * installed, and these tests don't need one. `--conditions=react-server` is
 * required so the `server-only` import in client.ts resolves to its harmless
 * empty.js instead of throwing (that's the exact condition Next.js's own
 * bundler sets when compiling Server Components).
 */
import { test, before, after, mock } from "node:test";
import assert from "node:assert/strict";
import { apiGet, apiPostForm, isRecord, isStringOrNull, isNumberOrNull } from "../client.ts";

const ORIGINAL_ENV = process.env.LARAVEL_API_URL;
const ORIGINAL_FETCH = globalThis.fetch;

before(() => {
  process.env.LARAVEL_API_URL = "https://admin.example.test";
});

after(() => {
  process.env.LARAVEL_API_URL = ORIGINAL_ENV;
  globalThis.fetch = ORIGINAL_FETCH;
});

function jsonResponse(body: unknown, status = 200): Response {
  return new Response(JSON.stringify(body), { status, headers: { "Content-Type": "application/json" } });
}

const isNumber = (v: unknown): v is { n: number } => isRecord(v) && typeof v.n === "number";

test("apiGet returns ok:true and the validated data on a 200 with a matching shape", async () => {
  globalThis.fetch = mock.fn(async () => jsonResponse({ n: 42 }));
  const result = await apiGet("/api/v1/whatever", { validate: isNumber, revalidateSeconds: 60 });
  assert.equal(result.ok, true);
  if (result.ok) assert.deepEqual(result.data, { n: 42 });
});

test("apiGet returns not_configured (never throws) when LARAVEL_API_URL is unset", async () => {
  delete process.env.LARAVEL_API_URL;
  const result = await apiGet("/api/v1/whatever", { validate: isNumber, revalidateSeconds: 60 });
  assert.equal(result.ok, false);
  if (!result.ok) assert.equal(result.error, "not_configured");
  process.env.LARAVEL_API_URL = "https://admin.example.test";
});

test("apiGet returns http_error on a non-2xx status, without throwing", async () => {
  globalThis.fetch = mock.fn(async () => jsonResponse({ message: "server error" }, 500));
  const result = await apiGet("/api/v1/whatever", { validate: isNumber, revalidateSeconds: 60 });
  assert.equal(result.ok, false);
  if (!result.ok) assert.equal(result.error, "http_error");
});

test("apiGet returns invalid_json when the body isn't parseable JSON", async () => {
  globalThis.fetch = mock.fn(async () => new Response("<html>not json</html>", { status: 200 }));
  const result = await apiGet("/api/v1/whatever", { validate: isNumber, revalidateSeconds: 60 });
  assert.equal(result.ok, false);
  if (!result.ok) assert.equal(result.error, "invalid_json");
});

test("apiGet returns invalid_shape when JSON parses but fails the validator — a malformed/incomplete response", async () => {
  globalThis.fetch = mock.fn(async () => jsonResponse({ wrong_field: "oops" }));
  const result = await apiGet("/api/v1/whatever", { validate: isNumber, revalidateSeconds: 60 });
  assert.equal(result.ok, false);
  if (!result.ok) assert.equal(result.error, "invalid_shape");
});

test("apiGet returns network_error when fetch rejects (DNS failure, connection refused, ERP fully down)", async () => {
  globalThis.fetch = mock.fn(async () => {
    throw new TypeError("fetch failed");
  });
  const result = await apiGet("/api/v1/whatever", { validate: isNumber, revalidateSeconds: 60 });
  assert.equal(result.ok, false);
  if (!result.ok) assert.equal(result.error, "network_error");
});

test("apiGet returns timeout when the request is aborted — the ERP hangs rather than erroring", async () => {
  globalThis.fetch = mock.fn((_url: RequestInfo | URL, init?: RequestInit): Promise<Response> => {
    return new Promise((_resolve, reject) => {
      init?.signal?.addEventListener("abort", () => reject(new DOMException("aborted", "AbortError")));
    });
  });
  const result = await apiGet("/api/v1/whatever", { validate: isNumber, revalidateSeconds: 60, timeoutMs: 20 });
  assert.equal(result.ok, false);
  if (!result.ok) assert.equal(result.error, "timeout");
});

test("isStringOrNull / isNumberOrNull accept null and the matching primitive, reject everything else", () => {
  assert.equal(isStringOrNull(null), true);
  assert.equal(isStringOrNull("x"), true);
  assert.equal(isStringOrNull(5), false);
  assert.equal(isStringOrNull(undefined), false);

  assert.equal(isNumberOrNull(null), true);
  assert.equal(isNumberOrNull(5), true);
  assert.equal(isNumberOrNull("5"), false);
});

test("apiGet round-trips Bangla UTF-8 content unmodified", async () => {
  const bn = "প্রভাতফেরী সাহিত্য ও সাংস্কৃতিক কেন্দ্র";
  globalThis.fetch = mock.fn(async () => jsonResponse({ n: 1, name: bn } as unknown));
  const isNameShape = (v: unknown): v is { n: number; name: string } => isRecord(v) && typeof v.n === "number" && typeof v.name === "string";
  const result = await apiGet("/api/v1/whatever", { validate: isNameShape, revalidateSeconds: 60 });
  assert.equal(result.ok, true);
  if (result.ok) assert.equal(result.data.name, bn);
});

// ---------------------------------------------------------------------------
// apiPostForm
// ---------------------------------------------------------------------------

test("apiPostForm returns ok:true and the validated data on a 201/200 with a matching shape", async () => {
  globalThis.fetch = mock.fn(async () => jsonResponse({ n: 7 }, 201));
  const result = await apiPostForm("/api/v1/whatever", new FormData(), { validate: isNumber });
  assert.equal(result.ok, true);
  if (result.ok) assert.deepEqual(result.data, { n: 7 });
});

test("apiPostForm returns a validation branch with the field=>messages map on a 422", async () => {
  globalThis.fetch = mock.fn(async () =>
    jsonResponse({ message: "The given data was invalid.", errors: { applicant_email: ["ই-মেইল আবশ্যক।"] } }, 422),
  );
  const result = await apiPostForm("/api/v1/whatever", new FormData(), { validate: isNumber });
  assert.equal(result.ok, false);
  if (!result.ok && result.error === "validation") {
    assert.deepEqual(result.errors.applicant_email, ["ই-মেইল আবশ্যক।"]);
  } else {
    assert.fail("expected a validation result");
  }
});

test("apiPostForm returns http_error on a non-422 non-2xx status", async () => {
  globalThis.fetch = mock.fn(async () => jsonResponse({ message: "server error" }, 500));
  const result = await apiPostForm("/api/v1/whatever", new FormData(), { validate: isNumber });
  assert.equal(result.ok, false);
  if (!result.ok) assert.equal(result.error, "http_error");
});

test("apiPostForm returns not_configured (never throws) when LARAVEL_API_URL is unset", async () => {
  delete process.env.LARAVEL_API_URL;
  const result = await apiPostForm("/api/v1/whatever", new FormData(), { validate: isNumber });
  assert.equal(result.ok, false);
  if (!result.ok) assert.equal(result.error, "not_configured");
  process.env.LARAVEL_API_URL = "https://admin.example.test";
});

test("apiPostForm returns network_error when fetch rejects", async () => {
  globalThis.fetch = mock.fn(async () => {
    throw new TypeError("fetch failed");
  });
  const result = await apiPostForm("/api/v1/whatever", new FormData(), { validate: isNumber });
  assert.equal(result.ok, false);
  if (!result.ok) assert.equal(result.error, "network_error");
});

// 2026-09-24: an untouched <input type="file"> submits as a present key
// holding an empty File (size 0, name "") — never an absent key. Re-
// serializing that empty File into the outgoing fetch to Laravel was found
// to sometimes reach Laravel corrupted (reported as exceeding the 5MB
// limit) after a real browser -> Server Action round trip, even though the
// field was never touched — see stripEmptyFiles' docblock in client.ts.
// apiPostForm must never forward a File field with size 0.
test("apiPostForm strips an untouched (0-byte, empty-name) file field before sending", async () => {
  let sentBody: FormData | undefined;
  globalThis.fetch = mock.fn(async (_input, init) => {
    sentBody = init?.body as FormData;
    return jsonResponse({ n: 1 }, 201);
  });

  const formData = new FormData();
  formData.append("applicant_name", "Someone");
  formData.append("photo", new File(["real bytes"], "photo.jpg", { type: "image/jpeg" }));
  formData.append("cv", new File([], "", { type: "" })); // untouched CV input

  await apiPostForm("/api/v1/whatever", formData, { validate: isNumber });

  assert.ok(sentBody, "fetch must have been called with a body");
  assert.equal(sentBody!.get("applicant_name"), "Someone");
  assert.ok(sentBody!.get("photo") instanceof File, "a real file must still be sent");
  assert.equal(sentBody!.get("cv"), null, "an untouched empty file field must be stripped, not forwarded");
});

test("apiPostForm keeps a genuinely named 0-byte file (not the untouched-input shape)", async () => {
  // Narrow the strip to the exact untouched-input shape (size 0 AND name
  // "") so a real — if unusual — 0-byte file the visitor actually picked
  // is never silently dropped.
  let sentBody: FormData | undefined;
  globalThis.fetch = mock.fn(async (_input, init) => {
    sentBody = init?.body as FormData;
    return jsonResponse({ n: 1 }, 201);
  });

  const formData = new FormData();
  formData.append("cv", new File([], "empty-but-named.pdf", { type: "application/pdf" }));

  await apiPostForm("/api/v1/whatever", formData, { validate: isNumber });

  assert.ok(sentBody!.get("cv") instanceof File, "a named file must be forwarded even if 0 bytes");
});
