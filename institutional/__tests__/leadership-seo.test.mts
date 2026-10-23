import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { join } from "node:path";
import test from "node:test";
import type { LeadershipDetail } from "../lib/api/types.ts";
import { org } from "../lib/content.ts";
import { getStrings } from "../lib/i18n/index.ts";
import { safeJsonLd } from "../lib/json-ld.ts";
import { resolveLeaderDetail } from "../lib/leadership.ts";
import { PERSON_KEYS, buildLeaderBreadcrumbJsonLd, buildPersonJsonLd } from "../lib/leadership-seo.ts";
import { canonicalProfileUrl } from "../lib/leadership-share.ts";

/**
 * The search-result and share-card side of a Leadership profile (2026-10-23): the schema.org Person and BreadcrumbList, built
 * from stored data only. What must hold: nothing invented, nothing empty, no contact data, `sameAs` only the allow-listed
 * social profiles, `worksFor` the organisation under its canonical name, the right address and names for each language, and
 * every block surviving the script-tag-safe encoder.
 */

const ROOT = new URL("..", import.meta.url).pathname.replace(/^\/([A-Za-z]:)/, "$1");
const read = (path: string) => readFileSync(join(ROOT, path), "utf8");

function detail(overrides: Partial<LeadershipDetail> = {}): LeadershipDetail {
  return {
    slug: "test-person",
    name_bn: "পরীক্ষামূলক ব্যক্তি",
    name_en: "Test Person",
    designation_bn: "পরীক্ষামূলক পদবি",
    designation_en: "Test designation",
    short_bio_bn: "সংক্ষিপ্ত পরিচিতি",
    short_bio_en: "Short bio",
    expertise_bn: "শিক্ষা",
    expertise_en: "Education",
    featured: true,
    photo: { url: "https://admin.example.test/storage/leadership/a.jpg", sm_url: "https://admin.example.test/storage/leadership/a-sm.jpg", width: 960, height: 1200 },
    social: [
      { platform: "facebook", url: "https://www.facebook.com/test.person" },
      { platform: "linkedin", url: "https://www.linkedin.com/in/test-person" },
    ],
    website_url: "https://example.org",
    full_bio_bn: "প্রথম অনুচ্ছেদ",
    full_bio_en: "First paragraph",
    quote_bn: "একটি উক্তি",
    quote_en: "A quote",
    associated_since_year: 2019,
    public_email: "person@example.org",
    public_phone: "+880 1711 000000",
    ...overrides,
  };
}

function person(locale: "bn" | "en", overrides: Partial<LeadershipDetail> = {}, hasEnglish?: boolean) {
  const profile = detail(overrides);
  const view = resolveLeaderDetail(profile, locale);
  const url = canonicalProfileUrl(profile.slug, locale, hasEnglish ?? view.hasEnglish);
  assert.ok(url);
  return buildPersonJsonLd({ profile, view, locale, url });
}

// --- the Person -------------------------------------------------------------------------------------------------------------------

test("a full profile becomes a Person with its name, title, bio, photo, address, organisation and profiles", () => {
  const p = person("en");
  assert.equal(p["@context"], "https://schema.org");
  assert.equal(p["@type"], "Person");
  assert.equal(p.name, "Test Person");
  assert.equal(p.alternateName, "পরীক্ষামূলক ব্যক্তি");
  assert.equal(p.jobTitle, "Test designation");
  assert.equal(p.description, "Short bio");
  assert.deepEqual(p.image, { "@type": "ImageObject", url: "https://admin.example.test/storage/leadership/a.jpg", width: 960, height: 1200 });
  assert.equal(p.url, "https://provatferi.org/en/leadership/test-person");
  assert.deepEqual(p.sameAs, ["https://www.facebook.com/test.person", "https://www.linkedin.com/in/test-person"]);
});

test("the Bangla page is Bangla with the English name as the alternate name", () => {
  const p = person("bn");
  assert.equal(p.name, "পরীক্ষামূলক ব্যক্তি");
  assert.equal(p.alternateName, "Test Person");
  assert.equal(p.jobTitle, "পরীক্ষামূলক পদবি");
  assert.equal(p.description, "সংক্ষিপ্ত পরিচিতি");
  assert.equal(p.url, "https://provatferi.org/leadership/test-person");
});

test("worksFor is the organisation under its canonical name, its acronym and the home page — in both languages", () => {
  for (const locale of ["bn", "en"] as const) {
    assert.deepEqual(person(locale).worksFor, { "@type": "Organization", name: "Provatferi Literary and Cultural Center", alternateName: "PLCC", url: "https://provatferi.org" });
  }
  // The same facts the site's Organization block uses: one spelling of the name, everywhere.
  assert.equal(org.nameEn, "Provatferi Literary and Cultural Center");
  assert.equal(org.acronym, "PLCC");
  assert.doesNotMatch(JSON.stringify(person("en")), /Centre|&amp;| & /);
});

test("only the allowed keys can appear, and none of them is empty", () => {
  for (const locale of ["bn", "en"] as const) {
    const p = person(locale);
    for (const key of Object.keys(p)) assert.ok((PERSON_KEYS as readonly string[]).includes(key), `unexpected key ${key}`);
    for (const [key, value] of Object.entries(p)) {
      assert.notEqual(value, null, key);
      assert.notEqual(value, "", key);
      if (Array.isArray(value)) assert.ok(value.length > 0, key);
    }
  }
});

test("a person's e-mail, phone, website, expertise, years and quote never enter the structured data", () => {
  const text = JSON.stringify(person("en"));
  for (const hidden of ["person@example.org", "+880 1711 000000", "1711", "https://example.org", "mailto", "tel:", "Education", "A quote", "First paragraph"]) {
    assert.ok(!text.includes(hidden), `${hidden} is absent`);
  }
  assert.doesNotMatch(text, /"(email|telephone|birthDate|alumniOf|award|knowsAbout|affiliation|hasCredential|address|gender|nationality)"/);
});

test("optional data that is missing is omitted, not written empty", () => {
  const p = person("en", { photo: null, social: [], short_bio_en: null, short_bio_bn: null, name_bn: "পরীক্ষামূলক ব্যক্তি" });
  assert.equal("image" in p, false);
  assert.equal("sameAs" in p, false);
  assert.equal("description" in p, false);
  assert.equal(p.name, "Test Person");
  assert.ok("jobTitle" in p && "url" in p && "worksFor" in p);
});

test("the alternate name is left out when the other language has none or it is the same text", () => {
  assert.equal("alternateName" in person("bn", { name_en: null }), false);
  assert.equal("alternateName" in person("bn", { name_en: "   " }), false);
  assert.equal("alternateName" in person("en", { name_bn: "Test Person" }), false);
});

test("sameAs holds only social profiles that passed the allow-list: no other host, no http, no look-alike, no mailto", () => {
  const p = person("bn", {
    social: [
      { platform: "facebook", url: "https://www.facebook.com/ok.person" },
      { platform: "facebook", url: "https://www.facebook.com/second.one" }, // a platform appears once
      { platform: "linkedin", url: "http://www.linkedin.com/in/plain-http" },
      { platform: "youtube", url: "https://evil.example/youtube.com/@x" },
      { platform: "youtube", url: "https://www.youtube.com.evil.example/@x" },
      { platform: "twitter", url: "https://twitter.com/x" }, // not a platform the site knows
      { platform: "facebook", url: "javascript:alert(1)" },
    ],
  });
  assert.deepEqual(p.sameAs, ["https://www.facebook.com/ok.person"]);
});

test("the personal website is not a social profile and is not in sameAs", () => {
  assert.equal(JSON.stringify(person("bn")).includes("example.org"), false);
});

test("an English page that is only a fallback render describes the same person under the Bangla address", () => {
  const p = person("en", { designation_en: null }, false);
  assert.equal(p.url, "https://provatferi.org/leadership/test-person");
  assert.equal(p.name, "Test Person");
  assert.equal(p.jobTitle, "পরীক্ষামূলক পদবি");
});

test("every block is inert inside a script tag: the encoder escapes markup in the values", () => {
  const p = person("en", { name_en: `</script><img src=x onerror=alert(1)>`, short_bio_en: "A & B <b>bold</b>" });
  const out = safeJsonLd(p);
  assert.doesNotMatch(out, /[<>&]/);
  assert.equal(JSON.parse(out).name, "</script><img src=x onerror=alert(1)>");
});

// --- the breadcrumb ----------------------------------------------------------------------------------------------------------------

test("the breadcrumb is Home, Leadership, the person — in the page's language, with absolute canonical-style addresses", () => {
  const enT = getStrings("en");
  const bnT = getStrings("bn");
  const en = buildLeaderBreadcrumbJsonLd({ locale: "en", homeName: enT.common.home, indexName: enT.leadership.title, personName: "Test Person", personUrl: "https://provatferi.org/en/leadership/test-person" });
  assert.deepEqual(en.itemListElement, [
    { "@type": "ListItem", position: 1, name: "Home", item: "https://provatferi.org/en" },
    { "@type": "ListItem", position: 2, name: "Leadership & Key Persons", item: "https://provatferi.org/en/leadership" },
    { "@type": "ListItem", position: 3, name: "Test Person", item: "https://provatferi.org/en/leadership/test-person" },
  ]);
  const bn = buildLeaderBreadcrumbJsonLd({ locale: "bn", homeName: bnT.common.home, indexName: bnT.leadership.title, personName: "পরীক্ষামূলক ব্যক্তি", personUrl: "https://provatferi.org/leadership/test-person" });
  assert.deepEqual(bn.itemListElement.map((i) => i.item), ["https://provatferi.org", "https://provatferi.org/leadership", "https://provatferi.org/leadership/test-person"]);
  assert.deepEqual(bn.itemListElement.map((i) => i.position), [1, 2, 3]);
  assert.match(bn.itemListElement[1].name, /[ঀ-৿]/);
});

// --- the page wiring (source-level) -------------------------------------------------------------------------------------------------

test("the profile page prints both blocks through safeJsonLd, only after the profile was found, and sets the share-card type", () => {
  const source = read("app/[locale]/(site)/leadership/[slug]/page.tsx");
  assert.match(source, /safeJsonLd\(person\)/);
  assert.match(source, /safeJsonLd\(breadcrumb\)/);
  assert.doesNotMatch(source, /JSON\.stringify\(/, "never a bare JSON.stringify inside a script element");
  const notFoundAt = source.indexOf("if (!result.ok) notFound();");
  assert.ok(notFoundAt > 0 && notFoundAt < source.indexOf("buildPersonJsonLd("), "an unknown or inactive profile 404s before any structured data is built");
  assert.match(source, /type: "profile"/);
  assert.match(source, /siteName: locale === "bn" \? org\.nameBn : org\.nameEn/);
  assert.match(source, /type: "image\/jpeg"/);
  assert.match(source, /twitter: \{ \.\.\.metadata\.twitter, images: \[\{ url: leader\.photo\.src, alt: photoAlt \}\] \}/);
  assert.match(source, /alternateLocale/, "the other language is advertised only when it exists");
});

test("the sitemap lists only the profiles the public API returned (active ones) and the English address only when it exists", () => {
  const source = read("app/sitemap.ts");
  assert.match(source, /getLeadershipProfiles\(\)/);
  assert.match(source, /hasTranslation: hasEnglishPage\(leader\)/);
  assert.match(source, /leaders\.length === 0/);
});

test("the Leadership index keeps its empty-state behaviour: no profiles means noindex", () => {
  const source = read("app/[locale]/(site)/leadership/page.tsx");
  assert.match(source, /robots: \{ index: false, follow: true \}/);
});
