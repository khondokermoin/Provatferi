import assert from "node:assert/strict";
import { createHash } from "node:crypto";
import { readFileSync } from "node:fs";
import { join } from "node:path";
import test from "node:test";
import { copyText, type CopyEnvironment } from "../lib/copy-text.ts";
import { org } from "../lib/content.ts";
import { getStrings } from "../lib/i18n/index.ts";
import {
  SHARE_LINK_PREFIXES,
  buildShareLinks,
  buildShareTarget,
  canonicalProfileUrl,
  cleanShareText,
  isCanonicalProfileUrl,
  isProfileSlug,
  leaderActionLabels,
} from "../lib/leadership-share.ts";
import { nextMenuIndex, placeMenu } from "../lib/menu-navigation.ts";

/**
 * Share / copy-link on Leadership cards and profile pages (2026-10-23): which address is shared (always the canonical one,
 * never a guess), what travels with it (name and designation only), the fallback links, the copy paths, the menu's keyboard and
 * placement rules, and — because the visual design is FROZEN — that none of the approved Leadership CSS was touched.
 */

const ROOT = new URL("..", import.meta.url).pathname.replace(/^\/([A-Za-z]:)/, "$1");
const read = (path: string) => readFileSync(join(ROOT, path), "utf8");

// --- the canonical address -----------------------------------------------------------------------------------------------

test("the Bangla address is the bare canonical one, the English address exists only when the English page does", () => {
  assert.equal(canonicalProfileUrl("test-person", "bn", true), "https://provatferi.org/leadership/test-person");
  assert.equal(canonicalProfileUrl("test-person", "bn", false), "https://provatferi.org/leadership/test-person");
  assert.equal(canonicalProfileUrl("test-person", "en", true), "https://provatferi.org/en/leadership/test-person");
  // An English page that is only a fallback render has the Bangla address as its canonical: that is what is shared.
  assert.equal(canonicalProfileUrl("test-person", "en", false), "https://provatferi.org/leadership/test-person");
});

test("the origin is the site's own constant, not whatever page the visitor is on", () => {
  assert.ok(canonicalProfileUrl("test-person", "bn", true)?.startsWith(`${org.website}/`));
  assert.equal(org.website, "https://provatferi.org");
});

test("anything that is not a profile slug yields no address at all", () => {
  for (const bad of ["", "A", "Test-Person", "x y", "a/b", "../x", "x?y=1", "x#h", "javascript:alert(1)", "x\n", "a--b", "-a", "a-", "মেহেদী", "a".repeat(81), "%2e%2e", "x;y", "x\u0000y"]) {
    assert.equal(isProfileSlug(bad), false, JSON.stringify(bad));
    assert.equal(canonicalProfileUrl(bad, "bn", true), null, JSON.stringify(bad));
  }
  assert.equal(isProfileSlug("a".repeat(80)), true);
  assert.equal(isProfileSlug("mehedi-hasan-roney"), true);
  assert.equal(isProfileSlug(42), false);
});

test("the component's own check accepts only canonical profile addresses", () => {
  for (const good of ["https://provatferi.org/leadership/test-person", "https://provatferi.org/en/leadership/test-person", "https://provatferi.org/leadership/a1-b2"]) {
    assert.equal(isCanonicalProfileUrl(good), true, good);
  }
  for (const bad of [
    "http://provatferi.org/leadership/test-person",
    "https://www.provatferi.org/leadership/test-person",
    "https://admin.provatferi.org/leadership/test-person",
    "https://provatferi.org.evil.example/leadership/test-person",
    "https://evil.example/https://provatferi.org/leadership/test-person",
    "https://provatferi.org/leadership/test-person?utm_source=x",
    "https://provatferi.org/leadership/test-person#top",
    "https://provatferi.org/leadership/test-person/",
    "https://provatferi.org/bn/leadership/test-person",
    "https://provatferi.org/en/en/leadership/test-person",
    "https://provatferi.org/en/bn/leadership/test-person",
    "https://provatferi.org/api/v1/public/leadership/test-person",
    "https://provatferi.org/leadership/Test-Person",
    "https://provatferi.org/leadership/",
    "https://provatferi.org/leadership",
    "javascript:alert(1)",
    "data:text/html,<script>alert(1)</script>",
    "//provatferi.org/leadership/test-person",
    "/leadership/test-person",
    "",
    null,
    undefined,
    42,
  ]) {
    assert.equal(isCanonicalProfileUrl(bad), false, String(bad));
  }
});

// --- what is shared ------------------------------------------------------------------------------------------------------------

test("what is shared is the name, the designation and the address — one clean line each", () => {
  const target = buildShareTarget({ slug: "test-person", name: "  Test \n Person ", designation: "Founder\tand  Director", hasEnglish: true, locale: "en" });
  assert.deepEqual(target, { url: "https://provatferi.org/en/leadership/test-person", title: "Test Person", text: "Founder and Director" });
});

test("control and bidirectional-override characters are dropped, the joiners Bangla needs are kept", () => {
  const zwnj = String.fromCharCode(0x200c);
  const zwj = String.fromCharCode(0x200d);
  const rlo = String.fromCharCode(0x202e);
  const line = String.fromCharCode(0x2028);
  const text = cleanShareText(`ক${zwj}ষ${zwnj}ম${rlo}a\u0007b${line}c​d`, 100);
  assert.ok(text.includes(zwj) && text.includes(zwnj), "ZWJ and ZWNJ stay");
  assert.ok(!text.includes(rlo) && !text.includes("\u0007") && !text.includes(line) && !text.includes("​"), "the unsafe characters are gone");
});

test("a very long designation is cut, counted in characters, with an ellipsis", () => {
  const cut = cleanShareText("পদবি ".repeat(100), 160);
  assert.ok(Array.from(cut).length <= 160);
  assert.ok(cut.endsWith("…"));
  assert.equal(cleanShareText("short", 160), "short");
});

test("a bad slug or an empty name means nothing is shared", () => {
  assert.equal(buildShareTarget({ slug: "../x", name: "A", designation: "B", hasEnglish: true, locale: "bn" }), null);
  assert.equal(buildShareTarget({ slug: "x", name: "   ", designation: "B", hasEnglish: true, locale: "bn" }), null);
  // A person without a designation can still be shared: the text is simply empty.
  assert.equal(buildShareTarget({ slug: "x", name: "A", designation: "", hasEnglish: true, locale: "bn" })?.text, "");
});

test("the share payload has no room for a phone number or an e-mail address: it only ever holds the three public values", () => {
  const target = buildShareTarget({ slug: "test-person", name: "Test Person", designation: "Director", hasEnglish: true, locale: "bn" });
  assert.deepEqual(Object.keys(target ?? {}).sort(), ["text", "title", "url"]);
  const source = read("lib/leadership-share.ts");
  assert.doesNotMatch(source.replace(/\/\*[\s\S]*?\*\//g, ""), /public_email|public_phone|phoneHref|usablePublicEmail|safePhone|\.phone\b|leader\.email|LeaderDetailView/, "the share module never touches a person's contact data");
});

// --- the fallback links --------------------------------------------------------------------------------------------------------

const hostile = { url: "https://provatferi.org/leadership/test-person", title: `"><script>alert(1)</script> & Co`, text: "Line one\nLine two <b>x</b> 100%" };

test("the fallback links start with fixed https (or mailto) prefixes and carry only percent-encoded values", () => {
  const links = buildShareLinks(hostile);
  assert.deepEqual(links.map((l) => l.id), ["whatsapp", "facebook", "linkedin", "email"]);
  for (const link of links) {
    assert.ok(link.href.startsWith(SHARE_LINK_PREFIXES[link.id]), `${link.id} starts with its fixed prefix`);
    assert.match(link.href, /^(https:\/\/|mailto:)/);
    assert.doesNotMatch(link.href, /javascript:|data:|vbscript:/i);
    assert.doesNotMatch(link.href, /[<>"'\s]/, `${link.id} has no raw markup or whitespace`);
    assert.doesNotMatch(link.href.slice(link.href.indexOf("?")), /https?:\/\//, "the address inside the link is encoded");
  }
});

test("the encoded values decode back to exactly the three public values", () => {
  const [whatsapp, facebook, linkedin, email] = buildShareLinks(hostile);
  const text = (href: string, prefix: string) => decodeURIComponent(href.slice(prefix.length));
  assert.equal(text(facebook.href, SHARE_LINK_PREFIXES.facebook), hostile.url);
  assert.equal(text(linkedin.href, SHARE_LINK_PREFIXES.linkedin), hostile.url);
  assert.equal(text(whatsapp.href, SHARE_LINK_PREFIXES.whatsapp), `${hostile.title} — ${hostile.text}\n${hostile.url}`);
  const mail = new URLSearchParams(email.href.slice("mailto:?".length));
  assert.equal(mail.get("subject"), hostile.title);
  assert.equal(mail.get("body"), `${hostile.title} — ${hostile.text}\n${hostile.url}`);
});

test("only four fixed sharing hosts exist", () => {
  const hosts = Object.values(SHARE_LINK_PREFIXES).map((prefix) => (prefix.startsWith("mailto:") ? "mailto" : new URL(prefix).host));
  assert.deepEqual(hosts, ["wa.me", "www.facebook.com", "www.linkedin.com", "mailto"]);
});

// --- the words -------------------------------------------------------------------------------------------------------------------

test("the menu's words exist in both languages, in the right script, with the person's name in the button label", () => {
  const bn = leaderActionLabels(getStrings("bn"), "মেহেদী হাসান");
  const en = leaderActionLabels(getStrings("en"), "Mehedi Hasan");
  assert.equal(en.copied, "Link copied");
  assert.equal(bn.copied, "লিংক কপি হয়েছে");
  assert.ok(bn.actions.includes("মেহেদী হাসান") && en.actions.includes("Mehedi Hasan"));
  for (const key of Object.keys(bn) as Array<keyof typeof bn>) {
    assert.ok(bn[key].trim() !== "" && en[key].trim() !== "", `${key} is filled in both languages`);
    assert.match(bn[key], /[ঀ-৿]|^[A-Za-z ()-]+$/, `${key} (bn)`);
  }
  for (const key of ["actions", "share", "copy", "copied", "shareVia", "copyFailed"] as const) {
    assert.doesNotMatch(en[key], /[ঀ-৿]/, `${key} (en) has no Bangla`);
    assert.match(bn[key], /[ঀ-৿]/, `${key} (bn) is Bangla`);
  }
});

// --- copying -----------------------------------------------------------------------------------------------------------------------

const env = (over: Partial<CopyEnvironment> = {}): CopyEnvironment => ({ writeText: null, secure: true, legacyCopy: null, ...over });

test("copy: the Clipboard API is used in a secure context and its text is exactly the address", async () => {
  const written: string[] = [];
  const ok = await copyText("https://provatferi.org/leadership/x", env({ writeText: async (t) => void written.push(t) }));
  assert.equal(ok, true);
  assert.deepEqual(written, ["https://provatferi.org/leadership/x"]);
});

test("copy: when the Clipboard API refuses, the legacy path runs; when it is not a secure context it is not even tried", async () => {
  const legacy: string[] = [];
  const refused = await copyText("A", env({ writeText: async () => { throw new Error("denied"); }, legacyCopy: (t) => (legacy.push(t), true) }));
  assert.equal(refused, true);
  assert.deepEqual(legacy, ["A"]);

  let called = false;
  const insecure = await copyText("B", env({ secure: false, writeText: async () => void (called = true), legacyCopy: () => true }));
  assert.equal(insecure, true);
  assert.equal(called, false);
});

test("copy: when both ways fail the answer is false — never a pretended success", async () => {
  assert.equal(await copyText("A", env()), false);
  assert.equal(await copyText("A", env({ writeText: async () => { throw new Error("no"); }, legacyCopy: () => false })), false);
  assert.equal(await copyText("A", env({ legacyCopy: () => { throw new Error("no"); } })), false);
});

// --- the menu's keyboard and placement ---------------------------------------------------------------------------------------------

test("keyboard: Down and Up wrap around, Home and End jump, other keys are left alone", () => {
  assert.equal(nextMenuIndex("ArrowDown", -1, 2), 0);
  assert.equal(nextMenuIndex("ArrowDown", 0, 2), 1);
  assert.equal(nextMenuIndex("ArrowDown", 1, 2), 0);
  assert.equal(nextMenuIndex("ArrowUp", -1, 3), 2);
  assert.equal(nextMenuIndex("ArrowUp", 0, 3), 2);
  assert.equal(nextMenuIndex("ArrowUp", 2, 3), 1);
  assert.equal(nextMenuIndex("Home", 2, 3), 0);
  assert.equal(nextMenuIndex("End", 0, 3), 2);
  for (const key of ["Enter", " ", "Tab", "Escape", "a", "ArrowLeft"]) assert.equal(nextMenuIndex(key, 0, 3), null, key);
  assert.equal(nextMenuIndex("ArrowDown", 0, 0), null);
});

const box = (left: number, top: number, size = 32) => ({ left, top, right: left + size, bottom: top + size });

test("placement: the menu hangs under the button with its right edge on the button's and stays inside the viewport", () => {
  const viewport = { width: 1366, height: 900 };
  const menu = { width: 240, height: 100 };
  const t = box(900, 120);
  assert.deepEqual(placeMenu(t, menu, viewport), { top: t.bottom + 6, left: t.right - menu.width });
});

test("placement: on a 390 px phone a button near the right or left edge never pushes the menu off screen", () => {
  const viewport = { width: 390, height: 844 };
  const menu = { width: 240, height: 100 };
  for (const left of [4, 8, 50, 150, 300, 340, 350]) {
    const { left: x } = placeMenu(box(left, 100), menu, viewport);
    assert.ok(x >= 8 && x + menu.width <= viewport.width - 8, `left ${left}: menu at ${x}`);
  }
});

test("placement: with no room below the menu opens above; with no room anywhere it is pinned to the viewport", () => {
  const viewport = { width: 390, height: 844 };
  const menu = { width: 240, height: 150 };
  const low = placeMenu(box(300, 790), menu, viewport);
  assert.equal(low.top, 790 - 6 - menu.height);
  const tiny = placeMenu(box(300, 20), { width: 240, height: 820 }, viewport);
  assert.ok(tiny.top >= 8);
});

test("placement: for any button position and any menu that fits the viewport, the whole menu is inside it (sweep)", () => {
  let seed = 7;
  const rnd = (n: number) => ((seed = (seed * 1103515245 + 12345) % 2147483648) % n);
  for (let i = 0; i < 4000; i++) {
    const viewport = { width: 320 + rnd(1200), height: 400 + rnd(700) };
    const menu = { width: 200 + rnd(Math.min(120, viewport.width - 220)), height: 80 + rnd(Math.min(400, viewport.height - 100)) };
    if (menu.width > viewport.width - 16 || menu.height > viewport.height - 16) continue;
    const t = box(rnd(viewport.width - 32), rnd(viewport.height - 32));
    const { top, left } = placeMenu(t, menu, viewport);
    assert.ok(left >= 8 && left + menu.width <= viewport.width - 8, `x ${JSON.stringify({ viewport, menu, t, left })}`);
    assert.ok(top >= 8 && top + menu.height <= viewport.height - 8, `y ${JSON.stringify({ viewport, menu, t, top })}`);
  }
});

// --- the component and its wiring (source-level: no DOM in this runner; the real browser checks are scripts/leadership-seo-qa.mjs) ---

test("the menu is an ARIA menu button with a menu, items, a live region, Escape, outside-press and focus return", () => {
  const source = read("components/LeaderActions.tsx");
  for (const needle of ['aria-haspopup="menu"', "aria-expanded", "aria-controls", 'role="menu"', 'role="menuitem"', 'aria-live="polite"', '"Escape"', '"pointerdown"', "createPortal", "triggerRef.current?.focus()", '"ArrowDown"', "nextMenuIndex", "placeMenu"]) {
    assert.ok(source.includes(needle), `LeaderActions.tsx has ${needle}`);
  }
  assert.match(source, /target="_blank" rel="noopener noreferrer"/);
});

test("the component never reads the location, opens windows, writes HTML or builds an address of its own", () => {
  const source = read("components/LeaderActions.tsx").replace(/\/\*[\s\S]*?\*\//g, "").replace(/^\s*\/\/.*$/gm, "");
  for (const forbidden of [/\blocation\b/, /window\.open/, /dangerouslySetInnerHTML/, /innerHTML/, /document\.write/, /eval\(/, /new URL\(/, /org\.website/, /encodeURIComponent/]) {
    assert.doesNotMatch(source, forbidden, String(forbidden));
  }
  assert.match(source, /isCanonicalProfileUrl\(target\.url\)/, "the address is validated once more before it is used");
});

test("the card hands the menu to the PHOTO frame — never into the name link, the heading or the text column", () => {
  const source = read("components/LeadershipCard.tsx");
  const menuAt = source.indexOf("<LeaderActions");
  assert.ok(menuAt > 0 && menuAt < source.indexOf("return ("), "built before the markup, so it is a value, not a child of the text column");
  assert.match(source, /<LeaderPhoto [^>]*actions=\{actions\}/, "passed to the photo, which draws it inside its own frame");
  assert.equal(source.split("<LeaderActions").length - 1, 1, "drawn once");
  const markup = source.slice(source.indexOf("return ("));
  assert.ok(!markup.includes("<LeaderActions"), "no LeaderActions inside the markup: not in the link, the heading or the body");
  assert.match(source, /buildShareTarget\(\{ slug: leader\.slug/);
});

test("the photo frame holds the button in every form — card, page, initials — and a button is never inside an aria-hidden subtree", () => {
  const source = read("components/LeaderPhoto.tsx").replace(/\/\*[\s\S]*?\*\//g, "").replace(/^\s*\/\/.*$/gm, "");
  assert.equal(source.split("{actions}").length - 1, 3, "card photo, page photo and initials frame each draw it");
  assert.doesNotMatch(source, /leader-photo-actions/, "no wrapper box: the frame is the positioning context");
  // `aria-hidden` appears only on the decorative initials span; the frames and the wrapper around the button have none.
  const hidden = source.match(/aria-hidden[^>]*>/g) ?? [];
  assert.equal(hidden.length, 1);
  assert.match(source, /<span aria-hidden="true">\{initials\}<\/span>/);
  for (const frame of source.match(/<div className=[^>]*>/g) ?? []) assert.doesNotMatch(frame, /aria-hidden/, frame);
});

test("the profile page puts the menu over the portrait and the share address comes from the current slug, not the requested one", () => {
  const source = read("app/[locale]/(site)/leadership/[slug]/page.tsx");
  assert.match(source, /actions=\{share === null \? null : <LeaderActions/);
  assert.match(source, /canonicalProfileUrl\(result\.data\.slug, locale, leader\.hasEnglish\)/);
  assert.match(source, /buildShareTarget\(\{ slug: result\.data\.slug/);
});

test("the English strings and the Bangla strings both define every menu word (the dictionary type forces it)", () => {
  for (const locale of ["bn", "en"] as const) {
    const l = getStrings(locale).leadership;
    for (const key of ["shareProfile", "copyProfileLink", "linkCopied", "shareVia", "copyFailed"] as const) assert.ok(l[key].length > 3, `${locale}.${key}`);
    assert.ok(l.actionsLabel("X").includes("X"));
  }
});

// --- the frozen design ---------------------------------------------------------------------------------------------------------------

test("the approved Leadership CSS is untouched: every existing rule is byte-for-byte what it was before the share menu", () => {
  const ADDED = /leader-actions|leader-menu/;
  const lines = read("app/globals.css")
    .split(/\r?\n/)
    .map((line) => line.trim())
    .filter((line) => /\.leader|\.home-leadership|\.social-links-card/.test(line) && !ADDED.test(line));
  assert.equal(lines.length, 71);
  assert.equal(createHash("sha256").update(lines.join("\n")).digest("hex"), "29361f5e9e42187370a24015b862a6d771916b1f82c568b1de694929dedbcf07");
});

test("the new CSS only adds: the button is absolute (no layout), the menu is fixed, and nothing re-declares a frozen card property", () => {
  const css = read("app/globals.css");
  assert.match(css, /\.leader-actions \{ position: absolute; top: 10px; right: 10px; z-index: 2; \}/);
  assert.match(css, /\.leader-menu \{ position: fixed;/);
  for (const line of css.split(/\r?\n/).filter((l) => /leader-actions|leader-menu/.test(l) && /\{/.test(l))) {
    assert.doesNotMatch(line, /\.leader-card\s*\{|\.leader-card-body\s*\{|\.leader-photo-card\s*\{|\.leader-photo-page\s*\{|\.leader-profile-grid\s*\{/, "no frozen selector is restated");
  }
});

test("nothing in the share menu's CSS touches the text of a card: no gutter, padding or margin on a heading, designation, biography or line", () => {
  // The approved layout must not re-flow for the button's sake: every selector the menu adds begins with its own class, and
  // the only rules outside that block are inside the media query, again on its own class. A heading gutter (padding-inline-end
  // on `h3`) was tried and rejected on 2026-10-23: it made four existing Bangla and English names wrap onto another line.
  const css = read("app/globals.css");
  const start = css.indexOf("SHARE / COPY-LINK MENU");
  assert.ok(start > 0);
  const added = css.slice(start).split(/\r?\n/).map((l) => l.trim()).filter((l) => l.includes("leader-actions") || l.includes("leader-menu"));
  assert.ok(added.length >= 15);
  for (const line of added) assert.match(line, /^\.leader-(actions|menu)/, `starts with its own class: ${line}`);
  assert.doesNotMatch(css, /h3\s*\{[^}]*padding-inline-end/, "no heading gutter");
  assert.doesNotMatch(css, /\.leader-card[^{]*\bh3[^{]*\{[^}]*padding/, "no padding added to a card heading");
  assert.doesNotMatch(css, /leader-photo-actions/, "no wrapper that would add a box");
});
