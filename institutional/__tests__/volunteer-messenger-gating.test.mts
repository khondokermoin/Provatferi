import assert from "node:assert/strict";
import { existsSync, mkdtempSync, readdirSync, readFileSync, rmSync, statSync, writeFileSync } from "node:fs";
import { tmpdir } from "node:os";
import { join, relative } from "node:path";
import test from "node:test";
import { bn } from "../lib/i18n/bn.ts";
import { en } from "../lib/i18n/en.ts";
import { scanClientBundle } from "../scripts/verify-client-bundle.mjs";

/**
 * The central volunteer Messenger group is offered ONLY on the confirmation page after a verified application
 * (admin-erp/docs/VOLUNTEER_MESSENGER_GROUP.md). The runtime half (proof, cookie, verification call) is tested in
 * lib/api/__tests__/volunteer-success.test.mts; this file pins what a unit test of a function cannot: that nothing in the
 * SOURCE of the site can put the invite anywhere else. A pasted link in a template, a notice button rendered again, the card
 * imported by a second page, a "just show it" prop — each of these would quietly undo the rule, and none of them fails a build.
 */

const ROOT = new URL("..", import.meta.url).pathname.replace(/^\/([A-Za-z]:)/, "$1");
const read = (...parts: string[]) => readFileSync(join(ROOT, ...parts), "utf8").replace(/\r\n/g, "\n");
const SUCCESS_PAGE = join("app", "[locale]", "(site)", "recruitment", "[slug]", "apply", "success", "page.tsx");

/** Every text file under `dir` (skipping build output and tests, which are allowed to name made-up invites). */
function* textFiles(dir: string, extensions: RegExp): Generator<string> {
  if (!existsSync(dir)) return;
  for (const entry of readdirSync(dir)) {
    if (entry === "node_modules" || entry === ".next" || entry === "__tests__") continue;
    const full = join(dir, entry);
    if (statSync(full).isDirectory()) yield* textFiles(full, extensions);
    else if (extensions.test(entry)) yield full;
  }
}

const SOURCE = /\.(tsx?|mts|mjs|cjs|jsx?|css|json|txt|xml|html|svg|webmanifest|md)$/;
const SHIPPED_DIRS = ["app", "components", "lib", "public"];
const siteFiles = () => [
  ...SHIPPED_DIRS.flatMap((dir) => [...textFiles(join(ROOT, dir), SOURCE)]),
  ...["proxy.ts", "next.config.ts"].map((name) => join(ROOT, name)).filter(existsSync),
];
/** The QA scripts are not shipped to a browser, but they run against production: they may name MADE-UP invites (to test refusal) and nothing else. */
const scriptFiles = () => [...textFiles(join(ROOT, "scripts"), SOURCE)];
const MADE_UP_TOKEN = /^(?:QaFake|QaOther|AbCdEf|MadeUp|TestInvite|LocalReal|short$|\$\{)/;
const inviteTokens = (text: string) =>
  [...text.matchAll(/(?:\bm\.me\/j\/|messenger\.com\/j\/|chat\.whatsapp\.com\/)([^\s"'\x60/?#)<>\\]*)/gi)].map((match) => match[1]);

/** `// …` and `/* … *​/` removed, so a comment that explains a retired field is not mistaken for a use of it. */
const withoutComments = (source: string) => source.replace(/\/\*[\s\S]*?\*\//g, "").replace(/(^|[^:"'`])\/\/[^\n]*/g, "$1");

test("no group invite address is written anywhere the site ships — templates, scripts, public files, dictionaries", () => {
  // The invite is admin-managed data (Admin → Recruitment → Messenger group). A literal here would be a second, public copy.
  const GROUP_ADDRESS = /chat\.whatsapp\.com|\bm\.me\/j\/|messenger\.com\/j\//i;
  const files = siteFiles();
  assert.ok(files.length > 100, `scanned only ${files.length} files — the path resolution is wrong, so this test is not protecting anything`);

  const offenders = files.filter((file) => GROUP_ADDRESS.test(readFileSync(file, "utf8"))).map((file) => relative(ROOT, file));
  assert.deepEqual(offenders, [], `a group invite address is written in: ${offenders.join(", ")}`);
});

test("the QA scripts name only made-up invites (the real one comes from an env file and is never in the repository)", () => {
  const scripts = scriptFiles();
  assert.ok(scripts.length > 10, "found too few scripts — the scan is not protecting anything");

  const offenders = scripts.flatMap((file) => inviteTokens(readFileSync(file, "utf8")).filter((token) => !MADE_UP_TOKEN.test(token)).map((token) => `${relative(ROOT, file)}: ${token.slice(0, 3)}…`));
  assert.deepEqual(offenders, [], "an invite that does not look made up is written in a script");
  // The positive control: a real-looking token IS caught, so the empty list above means something.
  assert.deepEqual(inviteTokens("see https://m.me/j/Zq81xLmN0pRs/ and https://chat.whatsapp.com/Kd93LsPq02MzXc").filter((token) => !MADE_UP_TOKEN.test(token)), ["Zq81xLmN0pRs", "Kd93LsPq02MzXc"]);
});

test("the card is imported by exactly one page: the confirmation, and nothing else can render it", () => {
  const importers = [...textFiles(join(ROOT, "app"), /\.tsx?$/), ...textFiles(join(ROOT, "components"), /\.tsx?$/), ...textFiles(join(ROOT, "lib"), /\.tsx?$/)]
    .filter((file) => /from\s+["'][^"']*MessengerGroupCard["']/.test(readFileSync(file, "utf8")))
    .map((file) => relative(ROOT, file));

  assert.deepEqual(importers, [SUCCESS_PAGE]);
});

test("the confirmation page asks Laravel to verify the proof it was given, and shows the card only inside the verified branch", () => {
  const page = read(SUCCESS_PAGE);

  assert.match(page, /export const dynamic = "force-dynamic";/, "it reads a cookie, so it is rendered per request and never from a cached copy");
  assert.match(page, /\(await cookies\(\)\)\.get\(SUCCESS_COOKIE\)/);
  assert.match(page, /verifyVolunteerSuccess\(slug, proof\)/);
  assert.doesNotMatch(page, /searchParams/, "the page takes no query: a typed address can never switch anything on");
  assert.doesNotMatch(page, /\.(set|delete)\(/, "a Server Component never writes a cookie");

  // The link comes from the verification and from nothing else...
  assert.match(page, /const messengerUrl = verification\.kind === "verified" \? verification\.messengerUrl : null;/);
  // ...and the card sits between the `verified ? (` branch and its `) : (` else.
  const verifiedBranch = page.slice(page.indexOf("{verified ? ("), page.indexOf(") : ("));
  assert.ok(verifiedBranch.includes("<MessengerGroupCard"), "the card must be inside the verified branch");
  assert.equal(page.split("<MessengerGroupCard").length - 1, 1, "the card is rendered exactly once");
  assert.ok(!page.slice(page.indexOf(") : (")).includes("MessengerGroupCard"), "the unverified branch must not mention the card");
});

test("the confirmation page's metadata is the same for everyone and tells crawlers to stay away", () => {
  const page = read(SUCCESS_PAGE);
  const metadata = page.slice(page.indexOf("export async function generateMetadata"), page.indexOf("export default async function"));

  assert.match(metadata, /robots: \{ index: false, follow: false \}/);
  assert.doesNotMatch(withoutComments(metadata), /verif|messenger|cookies|proof/i, "metadata is built outside the verified branch: it must not know about the invite");
  assert.doesNotMatch(read("app", "sitemap.ts"), /apply\/success/, "the confirmation is never listed in the sitemap");
});

test("the proof cookie is read by exactly the confirmation page and written only by the two submission paths", () => {
  const users = [...textFiles(join(ROOT, "app"), /\.tsx?$/), ...textFiles(join(ROOT, "components"), /\.tsx?$/), ...textFiles(join(ROOT, "lib"), /\.tsx?$/)]
    .filter((file) => /SUCCESS_COOKIE|pf_applied|setSuccessCookie|successSetCookieHeaders|readSuccessProof/.test(readFileSync(file, "utf8")))
    .map((file) => relative(ROOT, file).split("\\").join("/"))
    .sort();

  assert.deepEqual(users, [
    "app/[locale]/(site)/recruitment/[slug]/apply/actions.ts", // Server Action: the no-JavaScript path
    "app/[locale]/(site)/recruitment/[slug]/apply/success/page.tsx", // reads it, never writes
    "lib/volunteer-application-post.ts", // route handler: the JavaScript path
    "lib/volunteer-application.ts", // pulls the proof out of Laravel's answer, keeps it out of `state`
    "lib/volunteer-success.ts",
  ]);
  assert.match(read("lib", "volunteer-success.ts"), /^import "server-only";/, "the proof handling can never be bundled for the browser");
});

test("no client component can reach the proof, the verification or the cookie", () => {
  const clientFiles = [...textFiles(join(ROOT, "app"), /\.tsx?$/), ...textFiles(join(ROOT, "components"), /\.tsx?$/)].filter((file) =>
    /^["']use client["']/.test(readFileSync(file, "utf8").trimStart()),
  );
  assert.ok(clientFiles.length > 10, "found too few client components — the scan is not protecting anything");

  const offenders = clientFiles.filter((file) => /volunteer-success|volunteer-application["']|verifyVolunteerSuccess|pf_applied/.test(readFileSync(file, "utf8"))).map((file) => relative(ROOT, file));
  assert.deepEqual(offenders, []);
});

test("the card keeps the address on the page it was rendered into: no network, no storage, no log", () => {
  const card = withoutComments(read("components", "MessengerGroupCard.tsx"));

  assert.doesNotMatch(card, /\bfetch\s*\(|XMLHttpRequest|sendBeacon|WebSocket/, "the address is never sent anywhere");
  assert.doesNotMatch(card, /localStorage|sessionStorage|indexedDB|document\.cookie/, "the address is never kept in the browser");
  assert.doesNotMatch(card, /console\./, "the address is never logged");
  assert.match(card, /rel="noopener noreferrer"/, "the group opens in a new tab without handing it a reference to this page");
  assert.doesNotMatch(card, /dangerouslySetInnerHTML/);
});

test("the site never reads a retired or hidden source of a group link", () => {
  // The ERP's old `notice_action` carried the WhatsApp invite and `action` on a recruitment-linked notice a button: neither is read.
  const readers = [...textFiles(join(ROOT, "app"), /\.tsx?$/), ...textFiles(join(ROOT, "components"), /\.tsx?$/), ...textFiles(join(ROOT, "lib"), /\.tsx?$/)]
    .filter((file) => /notice_action|noticeAction|joinCommunityGroup|communityCtaLabel|isNoticeAction/.test(withoutComments(readFileSync(file, "utf8"))))
    .map((file) => relative(ROOT, file));
  assert.deepEqual(readers, []);

  const notice = read("app", "[locale]", "(site)", "notices", "[slug]", "page.tsx");
  assert.match(notice, /notice\.action && !notice\.recruitment/, "a recruitment-linked notice shows no button of its own — its route is the website form");
});

test("the Server Action and the route set the cookie before they answer; a rejected submission never does", () => {
  const action = read("app", "[locale]", "(site)", "recruitment", "[slug]", "apply", "actions.ts");
  assert.match(action, /if \(state\.status === "success" && proof\) setSuccessCookie\(store, slug, proof\);/);
  assert.ok(action.indexOf("setSuccessCookie(store, slug, proof)") < action.indexOf("redirect(`/recruitment/"), "the cookie must be set before the redirect that throws");
  // Next's cookie store keeps one cookie per name: setting pf_applied twice (two paths) would silently drop the first, and the
  // action redirects to the unprefixed address, so that is the one path it must set.
  assert.equal((action.match(/setSuccessCookie\(/g) ?? []).length, 1, "the action sets the proof cookie exactly once");
  assert.match(action, /redirect\(`\/recruitment\/\$\{encodeURIComponent\(slug\)\}\/apply\/success`\)/, "...and redirects to the unprefixed (Bangla) confirmation, which is the cookie's default path");

  const route = read("lib", "volunteer-application-post.ts");
  assert.match(route, /if \(state\.status === "success" && proof\) headers\.push\(\.\.\.successSetCookieHeaders\(slug, proof\)\);/);
});

// ---------------------------------------------------------------------------
// The build's own check of what the browser downloads
// ---------------------------------------------------------------------------

test("the build check fails a client bundle that carries a group invite or the proof's names (positive control)", () => {
  const dir = mkdtempSync(join(tmpdir(), "bundle-invite-"));
  try {
    writeFileSync(join(dir, "clean.js"), 'console.log("https://wa.me/?text=share", "m.me is just text", "https://provatferi.org");');
    assert.deepEqual(scanClientBundle(dir).problems, [], "ordinary share links and prose are not flagged");

    const leaks: [string, string][] = [
      ["messenger.js", 'const g="https://m.me/j/MadeUpToken_12-ab/";'],
      ["messenger-www.js", 'const g="https://www.messenger.com/j/MadeUpToken_12-ab/?send_source=x";'],
      ["whatsapp.js", 'const g="https://chat.whatsapp.com/MadeUpGroupCode123456";'],
      ["cookie.js", 'document.cookie="pf_applied=x"'],
      ["proof.js", "const p=data.success_proof"],
    ];
    for (const [name, body] of leaks) {
      writeFileSync(join(dir, name), body);
      const problems = scanClientBundle(dir).problems.filter((problem) => problem.startsWith(`${name}:`));
      assert.equal(problems.length, 1, `${name} must be flagged exactly once: ${JSON.stringify(problems)}`);
      assert.doesNotMatch(problems[0], /MadeUp/, "the report names the file, never the value");
    }
  } finally {
    rmSync(dir, { recursive: true, force: true });
  }
});

// ---------------------------------------------------------------------------
// The words on the page
// ---------------------------------------------------------------------------

const BENGALI = /[\u0980-\u09FF]/;
const strings = (value: unknown): string[] =>
  typeof value === "string" ? [value] : value && typeof value === "object" ? Object.values(value).flatMap(strings) : [];

test("the confirmation and group card are fully written in both languages, with no address in the words", () => {
  for (const [name, dictionary] of [["bn", bn], ["en", en]] as const) {
    for (const block of ["applyConfirmation", "messengerGroup"] as const) {
      const entries = Object.entries(dictionary[block]);
      assert.ok(entries.length >= 7, `${name}.${block} has only ${entries.length} entries`);
      for (const [key, value] of entries) {
        assert.equal(typeof value, "string", `${name}.${block}.${key}`);
        assert.ok((value as string).trim().length > 0, `${name}.${block}.${key} is empty`);
        assert.doesNotMatch(value as string, /https?:|www\.|\.com|m\.me/i, `${name}.${block}.${key} must not contain an address`);
      }
    }
  }

  const keys = (dictionary: typeof bn) => ({ confirmation: Object.keys(dictionary.applyConfirmation).sort(), group: Object.keys(dictionary.messengerGroup).sort() });
  assert.deepEqual(keys(en), keys(bn), "bn and en must define the same keys");

  // The wording of the group card is the right language: Bengali in bn, no Bengali in en.
  assert.ok(strings(bn.messengerGroup).every((s) => BENGALI.test(s) || /^[\s\S]*Messenger[\s\S]*$/.test(s)), "bn.messengerGroup must be Bengali");
  assert.ok(strings(en.messengerGroup).every((s) => !BENGALI.test(s)), "en.messengerGroup must be English");
  assert.ok(strings(en.applyConfirmation).every((s) => !BENGALI.test(s)), "en.applyConfirmation must be English");
});

test("the confirmation UI draws its icons as SVG: no emoji and no check-mark or retry glyph in the page, the card or the words", () => {
  const GLYPH = /\p{Extended_Pictographic}|[✓✔↻⟳]/u;
  for (const file of [SUCCESS_PAGE, join("components", "MessengerGroupCard.tsx")]) {
    const source = withoutComments(readFileSync(join(ROOT, file), "utf8"));
    assert.doesNotMatch(source, GLYPH, `${file} uses a text glyph or an emoji where an SVG belongs`);
    assert.match(source, /<svg /, `${file} has no inline SVG icon at all`);
  }
  for (const dictionary of [bn, en]) {
    assert.doesNotMatch(strings(dictionary.applyConfirmation).concat(strings(dictionary.messengerGroup)).join("\n"), GLYPH);
  }
  // Positive control: the check would catch the glyphs it exists to keep out.
  for (const bad of ["✓ done", "retry ↻", "ok ✅", "chat \u{1F4AC}"]) assert.match(bad, GLYPH);
});

test("the retired WhatsApp wording is gone from both dictionaries", () => {
  for (const dictionary of [bn, en]) {
    assert.equal("joinCommunityGroup" in dictionary.common, false);
    assert.doesNotMatch(strings(dictionary).join("\n"), /whatsapp/i, "no dictionary string points at WhatsApp");
  }
});
