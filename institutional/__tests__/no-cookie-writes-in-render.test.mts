import assert from "node:assert/strict";
import { readdirSync, readFileSync, statSync } from "node:fs";
import { join, relative } from "node:path";
import test from "node:test";

/**
 * A page or a layout is a Server Component: Next.js lets it READ cookies, never write them — `cookies().set/delete`
 * there throws, and the visitor gets the error page. 2026-10-06 (Membership Registry task 2): the member dashboard
 * cleared the session cookie when the ERP refused the token; once suspending a member started revoking tokens, a
 * suspended member's next visit was a 500 instead of the login page. Writes belong in Server Actions and route
 * handlers (actions.ts, route.ts) only.
 */

const ROOT = new URL("..", import.meta.url).pathname.replace(/^\/([A-Za-z]:)/, "$1");

function* renderFiles(dir: string): Generator<string> {
  for (const entry of readdirSync(dir)) {
    const full = join(dir, entry);
    if (statSync(full).isDirectory()) {
      yield* renderFiles(full);
    } else if (/^(page|layout|template|not-found|error|loading|default)\.tsx?$/.test(entry)) {
      yield full;
    }
  }
}

test("no page or layout writes a cookie while rendering", () => {
  const offenders: string[] = [];
  let scanned = 0;
  for (const file of renderFiles(join(ROOT, "app"))) {
    scanned++;
    const source = readFileSync(file, "utf8");
    if (/clearMemberSessionCookie\s*\(|setMemberSessionCookie\s*\(|\.(set|delete)\(\s*["'`]member_session/.test(source) || /cookies\(\)\)?\.(set|delete)\(/.test(source)) {
      offenders.push(relative(ROOT, file));
    }
  }
  assert.ok(scanned > 20, `scanned only ${scanned} files — the path resolution is wrong, so this test is not protecting anything`);
  assert.deepEqual(offenders, [], `cookie writes during render (move them to a Server Action or route handler): ${offenders.join(", ")}`);
});
