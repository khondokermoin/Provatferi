import assert from "node:assert/strict";
import { existsSync, mkdtempSync, readdirSync, readFileSync, rmSync, statSync, writeFileSync } from "node:fs";
import { tmpdir } from "node:os";
import { join, relative } from "node:path";
import test from "node:test";
import { org } from "../lib/content.ts";
import { PUBLIC_ADDRESSES, scanClientBundle } from "../scripts/verify-client-bundle.mjs";

/**
 * The public site's side of the e-mail role architecture (admin-erp/docs/EMAIL_ARCHITECTURE.md). The site sends no
 * mail; what it can get wrong is which address it SHOWS. Only two may ever reach a browser: info@ (the public contact)
 * and support@ (help with a person's own application or account). admin@ is the SMTP login and internal mailbox and
 * no-reply@ is a sender only — neither may appear in a page, and no mail credential may reach a client bundle.
 */

const ROOT = new URL("..", import.meta.url).pathname.replace(/^\/([A-Za-z]:)/, "$1");
const SCAN = ["app", "components", "lib"];

function* sourceFiles(dir: string): Generator<string> {
  for (const entry of readdirSync(dir)) {
    const full = join(dir, entry);
    if (statSync(full).isDirectory()) {
      if (entry !== "__tests__") yield* sourceFiles(full);
    } else if (/\.(tsx?|mts|mjs|jsx?)$/.test(entry)) {
      yield full;
    }
  }
}

function scanSources(pattern: RegExp): { scanned: number; hits: string[] } {
  const hits: string[] = [];
  let scanned = 0;
  for (const base of SCAN) {
    for (const file of sourceFiles(join(ROOT, base))) {
      scanned++;
      for (const m of readFileSync(file, "utf8").matchAll(pattern)) hits.push(`${relative(ROOT, file)}: ${m[0]}`);
    }
  }
  return { scanned, hits };
}

test("the public contact is info@ and applicant help is support@", () => {
  assert.equal(org.email, "info@provatferi.org");
  assert.equal(org.supportEmail, "support@provatferi.org");
  assert.deepEqual([...PUBLIC_ADDRESSES].sort(), [org.email, org.supportEmail].sort(), "the build check and lib/content.ts must agree");
});

test("no source file names an institutional address other than the two public ones", () => {
  const { scanned, hits } = scanSources(/[A-Za-z0-9._%+-]+@provatferi\.org/gi);
  assert.ok(scanned > 50, `scanned only ${scanned} files — the path resolution is wrong, so this test is not protecting anything`);
  const offenders = hits.filter((hit) => !PUBLIC_ADDRESSES.includes(hit.split(": ")[1].toLowerCase()));
  assert.deepEqual(offenders, [], "only lib/content.ts's org.email / org.supportEmail may be shown");
  // And they are written once, in lib/content.ts — pages use org.email / org.supportEmail.
  assert.deepEqual(hits.map((hit) => hit.split(": ")[0]).filter((file) => !file.endsWith(join("lib", "content.ts"))), []);
});

test("applicant-facing help points at support@, general contact at info@", () => {
  const success = readFileSync(join(ROOT, "app", "[locale]", "(site)", "recruitment", "[slug]", "apply", "success", "page.tsx"), "utf8");
  assert.match(success, /mailto:\$\{org\.supportEmail\}/, "the after-applying page names support@ for questions about the application");
  assert.doesNotMatch(success, /org\.email\b/);

  const posting = readFileSync(join(ROOT, "app", "[locale]", "(site)", "recruitment", "[slug]", "page.tsx"), "utf8");
  assert.match(posting, /mailto:\$\{org\.supportEmail\}\?subject=/, "\"Contact About This Opportunity\" goes to support@");

  const footer = readFileSync(join(ROOT, "components", "Footer.tsx"), "utf8");
  assert.match(footer, /mailto:\$\{org\.email\}/, "the footer's contact is info@");
});

test("no source reads a mail credential, and nothing is exposed through NEXT_PUBLIC_ or next.config env", () => {
  const credentials = scanSources(/MAIL_(PASSWORD|USERNAME|HOST)|HOSTINGER_MAIL_API_TOKEN|smtp\.hostinger\.com|SMTP_/g);
  assert.deepEqual(credentials.hits, []);
  const publicVars = scanSources(/NEXT_PUBLIC_[A-Z0-9_]+/g);
  assert.deepEqual(publicVars.hits, [], "this site exposes no NEXT_PUBLIC_ variable");
  const config = readFileSync(join(ROOT, "next.config.ts"), "utf8");
  assert.doesNotMatch(config, /\benv\s*:|publicRuntimeConfig|serverRuntimeConfig/);
});

test("the build check catches a planted leak (positive control)", () => {
  const dir = mkdtempSync(join(tmpdir(), "bundle-check-"));
  try {
    writeFileSync(join(dir, "ok.js"), 'const a = "info@provatferi.org", b = "support@provatferi.org";');
    assert.deepEqual(scanClientBundle(dir).problems, []);

    // A stand-in for a secret value, built at run time (a secret-shaped literal here would be a secret-scanner finding).
    const planted = ["planted", "stand", "in", "value"].join("-").repeat(2);
    writeFileSync(join(dir, "leak.js"), `const x = "admin@provatferi.org"; const y = process.env.MAIL_PASSWORD; const z = "${planted}";`);
    const { problems } = scanClientBundle(dir, { REVALIDATE_SECRET: planted });
    assert.ok(problems.some((p: string) => p.includes("admin@provatferi.org")), "a private address must be reported");
    assert.ok(problems.some((p: string) => p.includes("MAIL_PASSWORD")), "a credential name must be reported");
    assert.ok(problems.some((p: string) => p.includes("value of REVALIDATE_SECRET")), "a secret value must be reported");
    assert.ok(problems.every((p: string) => !p.includes(planted)), "the secret value itself is never printed");
  } finally {
    rmSync(dir, { recursive: true, force: true });
  }
});

test("the built client bundle (when one exists) shows only public addresses and no credential", (t) => {
  const staticDir = join(ROOT, ".next", "static");
  if (!existsSync(staticDir)) {
    t.skip("no .next build here — `npm run build` runs the same check as its postbuild step");
    return;
  }
  const { scanned, problems } = scanClientBundle(staticDir, { REVALIDATE_SECRET: process.env.REVALIDATE_SECRET });
  assert.ok(scanned > 0);
  assert.deepEqual(problems, []);
});
