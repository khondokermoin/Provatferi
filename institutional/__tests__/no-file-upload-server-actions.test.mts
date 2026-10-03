import assert from "node:assert/strict";
import { readdirSync, readFileSync, statSync } from "node:fs";
import { join, relative } from "node:path";
import test from "node:test";

/**
 * A form with a file input must not submit through a Server Action (`<form action={fn}>` / useActionState).
 *
 * Measured on production 2026-10-04: every Server Action call carries a `Next-Action` header, and Cloudflare's
 * managed WAF rule "React - Leaking Server Functions" (CVE-2025-55183) refuses, with a bare 403 from the edge,
 * any such request whose first 1 MiB contains the bytes `"$F` or `'$F`. A photo or PDF is binary, so it
 * contains them by chance about once per 8 MB: roughly one upload in nine is refused — always the same file,
 * so a retry cannot help — and nothing in a build, a local run or this app's logs shows it. The volunteer
 * application form was moved to a plain route (app/api/recruitment/[slug]/apply, see
 * __tests__/volunteer-apply-transport.test.mts). Post a file with fetch() to a route handler instead.
 *
 * KNOWN_EXPOSED are the forms that still have the problem and have not been moved yet (they belong to the
 * membership / committee work, which was out of scope when this was found). The list may only shrink: this
 * test fails if a listed form no longer needs to be listed, so the debt stays visible and honest.
 */
const KNOWN_EXPOSED = new Set([
  join("components", "CommitteeCorrectionForm.tsx"),
  join("components", "CommitteeRegistrationForm.tsx"),
  join("components", "MemberProfileEditForm.tsx"),
  join("components", "MembershipApplicationForm.tsx"),
]);

/**
 * Deliberate hybrids: JavaScript posts the files with fetch() to a plain route, and the Server Action only
 * serves the no-JavaScript / not-yet-hydrated native post (which carries no Next-Action header). Their
 * transport is pinned by their own test (__tests__/volunteer-apply-transport.test.mts).
 */
const DELIBERATE_HYBRIDS = new Set([join("components", "VolunteerApplicationForm.tsx")]);

const ROOT = new URL("..", import.meta.url).pathname.replace(/^\/([A-Za-z]:)/, "$1");

function* tsxFiles(dir: string): Generator<string> {
  for (const entry of readdirSync(dir)) {
    const full = join(dir, entry);
    if (statSync(full).isDirectory()) yield* tsxFiles(full);
    else if (entry.endsWith(".tsx")) yield full;
  }
}

const hasFileInput = (source: string) => /type=["']file["']|type:\s*["']file["']/.test(source);
const usesServerAction = (source: string) => /useActionState|<form[^>]*\baction=\{/.test(source);

test("no NEW form posts a file through a Server Action", () => {
  const offenders: string[] = [];
  let scanned = 0;
  for (const base of ["app", "components"]) {
    for (const file of tsxFiles(join(ROOT, base))) {
      scanned++;
      const rel = relative(ROOT, file);
      const source = readFileSync(file, "utf8");
      if (hasFileInput(source) && usesServerAction(source) && !KNOWN_EXPOSED.has(rel) && !DELIBERATE_HYBRIDS.has(rel)) offenders.push(rel);
    }
  }
  assert.ok(scanned > 50, `scanned only ${scanned} files — the path resolution is wrong, so this test protects nothing`);
  assert.deepEqual(
    offenders,
    [],
    `${offenders.join(", ")} has a file input and submits through a Server Action: Cloudflare refuses ~1 in 9 such uploads (see this file's header). POST the FormData with fetch() to a route handler under app/api/ instead.`,
  );
});

test("the known-exposed list stays honest: every listed form still has a file input and still uses a Server Action", () => {
  for (const rel of KNOWN_EXPOSED) {
    const source = readFileSync(join(ROOT, rel), "utf8");
    assert.ok(
      hasFileInput(source) && usesServerAction(source),
      `${rel} no longer needs to be in KNOWN_EXPOSED (it was fixed or changed) — remove it from the list in __tests__/no-file-upload-server-actions.test.mts`,
    );
  }
});

test("a deliberate hybrid really posts its files with fetch() to an api route", () => {
  for (const rel of DELIBERATE_HYBRIDS) {
    const source = readFileSync(join(ROOT, rel), "utf8");
    assert.ok(source.includes("fetch(`/api/"), `${rel} is listed as a deliberate hybrid but no longer posts to an /api/ route`);
    assert.equal(KNOWN_EXPOSED.has(rel), false, `${rel} cannot be both exposed and a deliberate hybrid`);
  }
});
