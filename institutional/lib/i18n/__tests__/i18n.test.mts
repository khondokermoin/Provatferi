import { test } from "node:test";
import assert from "node:assert/strict";
import { bn } from "../bn.ts";
import { en } from "../en.ts";
import { getStrings, isLocale } from "../index.ts";
import { pickText, pickOptionalText } from "../pick.ts";
import { splitLocaleFromPathname, localizeHref, canonicalUrl } from "../paths.ts";
import { noticeTypeLabel, employmentTypeLabel, applicationModeLabel, committeeTypeLabel, committeeStatusLabel } from "../enums.ts";

// ---------------------------------------------------------------------------
// Dictionary completeness — TypeScript enforces UiStrings' fixed keys at
// compile time, but the `enums` maps are typed as Record<string, string>, so
// a key present in bn.ts but forgotten in en.ts (or vice versa) would NOT be
// a compile error. This is the one place that gap is actually caught.
// ---------------------------------------------------------------------------

test("every enum key in bn.ts has a matching key in en.ts, and vice versa", () => {
  for (const group of ["noticeType", "employmentType", "applicationMode", "committeeType", "committeeStatus"] as const) {
    const bnKeys = Object.keys(bn.enums[group]).sort();
    const enKeys = Object.keys(en.enums[group]).sort();
    assert.deepEqual(enKeys, bnKeys, `enums.${group} keys differ between bn.ts and en.ts`);
  }
});

test("getStrings/isLocale resolve exactly bn and en, nothing else", () => {
  assert.equal(getStrings("bn"), bn);
  assert.equal(getStrings("en"), en);
  assert.equal(isLocale("bn"), true);
  assert.equal(isLocale("en"), true);
  assert.equal(isLocale("fr"), false);
  assert.equal(isLocale(""), false);
});

// ---------------------------------------------------------------------------
// pickText / pickOptionalText — the fallback policy (plan Section E)
// ---------------------------------------------------------------------------

test("pickText on bn always returns the bn value, never marked as fallback", () => {
  assert.deepEqual(pickText("bn", "বাংলা", "English"), { text: "বাংলা", isFallback: false });
  assert.deepEqual(pickText("bn", "বাংলা", null), { text: "বাংলা", isFallback: false });
  assert.deepEqual(pickText("bn", "বাংলা", undefined), { text: "বাংলা", isFallback: false });
});

test("pickText on en prefers a real English value", () => {
  assert.deepEqual(pickText("en", "বাংলা", "English"), { text: "English", isFallback: false });
});

test("pickText on en falls back to bn (marked) when English is null, undefined, or blank", () => {
  assert.deepEqual(pickText("en", "বাংলা", null), { text: "বাংলা", isFallback: true });
  assert.deepEqual(pickText("en", "বাংলা", undefined), { text: "বাংলা", isFallback: true });
  assert.deepEqual(pickText("en", "বাংলা", "   "), { text: "বাংলা", isFallback: true });
});

test("pickOptionalText returns null when there is nothing to show in either language", () => {
  assert.equal(pickOptionalText("en", null, "English"), null);
  assert.equal(pickOptionalText("en", undefined, "English"), null);
  assert.equal(pickOptionalText("en", "", "English"), null);
});

test("pickOptionalText behaves exactly like pickText once a bn value exists", () => {
  assert.deepEqual(pickOptionalText("en", "বাংলা", null), { text: "বাংলা", isFallback: true });
  assert.deepEqual(pickOptionalText("en", "বাংলা", "English"), { text: "English", isFallback: false });
});

// ---------------------------------------------------------------------------
// Locale path helpers — the routing contract every Link/redirect depends on
// ---------------------------------------------------------------------------

test("splitLocaleFromPathname strips the internal /bn or /en prefix middleware always adds", () => {
  assert.deepEqual(splitLocaleFromPathname("/bn"), { locale: "bn", path: "/" });
  assert.deepEqual(splitLocaleFromPathname("/bn/activities"), { locale: "bn", path: "/activities" });
  assert.deepEqual(splitLocaleFromPathname("/en"), { locale: "en", path: "/" });
  assert.deepEqual(splitLocaleFromPathname("/en/activities/foo"), { locale: "en", path: "/activities/foo" });
});

test("splitLocaleFromPathname defaults to bn for a path with neither prefix (defensive — shouldn't happen given middleware)", () => {
  assert.deepEqual(splitLocaleFromPathname("/activities"), { locale: "bn", path: "/activities" });
});

test("localizeHref never prefixes bn (the real, browser-visible URL has no /bn segment)", () => {
  assert.equal(localizeHref("/", "bn"), "/");
  assert.equal(localizeHref("/activities", "bn"), "/activities");
  assert.equal(localizeHref("/notices/foo", "bn"), "/notices/foo");
});

test("localizeHref always prefixes en with /en", () => {
  assert.equal(localizeHref("/", "en"), "/en");
  assert.equal(localizeHref("/activities", "en"), "/en/activities");
  assert.equal(localizeHref("/notices/foo", "en"), "/en/notices/foo");
});

test("localizeHref and splitLocaleFromPathname round-trip for both locales", () => {
  for (const locale of ["bn", "en"] as const) {
    for (const path of ["/", "/activities", "/notices/some-slug", "/recruitment/foo/apply"]) {
      const href = localizeHref(path, locale);
      // The real, browser-visible URL has no internal prefix stripping to
      // undo for bn; for en, splitLocaleFromPathname strips exactly what
      // localizeHref added.
      const rebuilt = locale === "en" ? splitLocaleFromPathname(href) : { locale: "bn", path: href };
      assert.equal(rebuilt.locale, locale);
      assert.equal(rebuilt.path, path);
    }
  }
});

test("canonicalUrl joins the origin and path without a double slash at the root", () => {
  assert.equal(canonicalUrl("https://provatferi.org", "/"), "https://provatferi.org");
  assert.equal(canonicalUrl("https://provatferi.org", "/about"), "https://provatferi.org/about");
});

// ---------------------------------------------------------------------------
// Enum label helpers — bn is always byte-identical to the server's own
// label; en looks the key up and never returns an empty/undefined label.
// ---------------------------------------------------------------------------

test("noticeTypeLabel: bn always returns the server label untouched", () => {
  assert.equal(noticeTypeLabel("urgent", "জরুরি বিজ্ঞপ্তি", "bn"), "জরুরি বিজ্ঞপ্তি");
  assert.equal(noticeTypeLabel("some_future_key", "কিছু একটা", "bn"), "কিছু একটা");
});

test("noticeTypeLabel: en translates known keys and falls back to the server label for an unknown one", () => {
  assert.equal(noticeTypeLabel("urgent", "জরুরি বিজ্ঞপ্তি", "en"), "Urgent Notice");
  assert.equal(noticeTypeLabel("some_future_key", "কিছু একটা", "en"), "কিছু একটা");
});

test("employmentTypeLabel returns null only when the server label itself is null", () => {
  assert.equal(employmentTypeLabel("full_time", null, "en"), null);
  assert.equal(employmentTypeLabel(null, null, "bn"), null);
  assert.equal(employmentTypeLabel("full_time", "পূর্ণকালীন", "en"), "Full-time");
  assert.equal(employmentTypeLabel("full_time", "পূর্ণকালীন", "bn"), "পূর্ণকালীন");
});

test("applicationModeLabel translates fixed/rolling on en, passes bn through untouched", () => {
  assert.equal(applicationModeLabel("rolling", "চলমান", "en"), "Rolling basis");
  assert.equal(applicationModeLabel("rolling", "চলমান", "bn"), "চলমান");
});

test("committeeTypeLabel falls back to the caller-supplied default for a null/unknown key", () => {
  assert.equal(committeeTypeLabel("executive", "en", "Committee"), "Executive Committee");
  assert.equal(committeeTypeLabel(null, "en", "Committee"), "Committee");
  assert.equal(committeeTypeLabel("nonexistent", "en", "Committee"), "Committee");
});

test("committeeStatusLabel returns the dictionary's own label per locale, or null for an unknown key", () => {
  assert.equal(committeeStatusLabel("active", "bn"), "সক্রিয়");
  assert.equal(committeeStatusLabel("active", "en"), "Active");
  assert.equal(committeeStatusLabel("nonexistent", "en"), null);
});
