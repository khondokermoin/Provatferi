import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { join } from "node:path";
import test from "node:test";

/**
 * 2026-10-04, measured on production: a request that carries a `Next-Action` header (every Server Action
 * call) is refused by Cloudflare's managed WAF rule "React - Leaking Server Functions" (CVE-2025-55183)
 * whenever the first 1 MiB of its body contains the bytes `"$F` or `'$F`. A JPEG or a PDF contains them
 * by chance about once per 8 MB, so roughly one photo upload in nine was refused with a bare 403 — always
 * the same photos: retrying never helped. Nothing in a build, a unit test or a local run can show it (the
 * rule lives at the edge), which is why this is a test of the source.
 *
 * The volunteer form's JavaScript therefore posts its multipart body to a plain route, and the Server
 * Action is kept only for the no-JavaScript path, where the post is native and carries no such header.
 */

const ROOT = new URL("..", import.meta.url).pathname.replace(/^\/([A-Za-z]:)/, "$1");
const read = (...parts: string[]) => readFileSync(join(ROOT, ...parts), "utf8");

test("the volunteer form's JavaScript submit posts to the plain route, not to the Server Action", () => {
  const form = read("components", "VolunteerApplicationForm.tsx");

  // The form hands the shared hook its endpoint; the hook's plain fetch() is pinned in no-file-upload-server-actions.test.mts.
  assert.match(form, /endpoint: `\/api\/recruitment\/\$\{encodeURIComponent\(slug\)\}\/apply`/, "the form must post to /api/recruitment/<slug>/apply");
  assert.doesNotMatch(
    form,
    /submitVolunteerApplication\(/,
    "calling the Server Action with the form's FormData sends a Next-Action request that Cloudflare may refuse when a photo or CV happens to contain the bytes `\"$F` — only .bind() it for the no-JavaScript path",
  );
});

test("the route exists and hands the request to the shared handler", () => {
  const route = read("app", "api", "recruitment", "[slug]", "apply", "route.ts");

  assert.match(route, /export async function POST/);
  assert.match(route, /handleApplicationPost\(request, slug\)/);
});

test("the route's proxy matcher exclusion still covers it (a rewrite to /bn/api/... would 404 it)", () => {
  const proxy = read("proxy.ts");

  assert.match(proxy, /matcher:\s*\["\/\(\(\?!api\//, "proxy.ts must keep excluding api/");
});
