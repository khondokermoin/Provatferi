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
import { apiGet, isRecord, isStringOrNull, isNumberOrNull } from "../client.ts";

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
