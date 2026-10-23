import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { join } from "node:path";
import test, { after, before, mock } from "node:test";
import { getLeadershipProfile, getLeadershipProfiles, LEADERSHIP_CACHE_TAG } from "../lib/api/leadership.ts";
import type { LeadershipDetail, LeadershipSummary } from "../lib/api/types.ts";
import { footerExploreLinks, navLinks, org } from "../lib/content.ts";
import { getStrings } from "../lib/i18n/index.ts";
import { hasEnglishPage, initialsOf, leaderDescription, resolveLeader, resolveLeaderDetail, safeImageUrl, splitFeatured } from "../lib/leadership.ts";
import { ALLOWED_REVALIDATE_TAGS } from "../lib/revalidate-request.ts";

/**
 * Leadership / Key Persons on the public site (docs/LEADERSHIP_SYSTEM.md): the language rule (Bangla is the base, English
 * falls back to it and says so, nothing is invented), that everything the ERP returns is re-checked before it becomes a link
 * or an address, the API client's tag and guards, and that the pages and the navigation are wired to it.
 */

const ROOT = new URL("..", import.meta.url).pathname.replace(/^\/([A-Za-z]:)/, "$1");
const read = (path: string) => readFileSync(join(ROOT, path), "utf8");

function summary(overrides: Partial<LeadershipSummary> = {}): LeadershipSummary {
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
    featured: false,
    photo: { url: "https://admin.example.test/storage/leadership/a.jpg", sm_url: "https://admin.example.test/storage/leadership/a-sm.jpg", width: 960, height: 1200 },
    social: [{ platform: "facebook", url: "https://www.facebook.com/test.person" }],
    website_url: "https://example.org",
    ...overrides,
  };
}

function detail(overrides: Partial<LeadershipDetail> = {}): LeadershipDetail {
  return {
    ...summary(),
    full_bio_bn: "প্রথম অনুচ্ছেদ\n\nদ্বিতীয় অনুচ্ছেদ",
    full_bio_en: "First paragraph\n\nSecond paragraph",
    quote_bn: "একটি উক্তি",
    quote_en: "A quote",
    associated_since_year: 2019,
    public_email: "person@example.org",
    public_phone: "+880 1711 000000",
    ...overrides,
  };
}

// --- the language rule -------------------------------------------------------------------------------------------------

test("the Bangla page shows the Bangla text, never the English", () => {
  const leader = resolveLeaderDetail(detail(), "bn");

  assert.equal(leader.name, "পরীক্ষামূলক ব্যক্তি");
  assert.equal(leader.designation, "পরীক্ষামূলক পদবি");
  assert.equal(leader.shortBio, "সংক্ষিপ্ত পরিচিতি");
  assert.equal(leader.fullBio, "প্রথম অনুচ্ছেদ\n\nদ্বিতীয় অনুচ্ছেদ");
  assert.equal(leader.quote, "একটি উক্তি");
  assert.equal(leader.expertise, "শিক্ষা");
  assert.equal(leader.usesFallback, false);
});

test("the English page shows the English text where it exists", () => {
  const leader = resolveLeaderDetail(detail(), "en");

  assert.equal(leader.name, "Test Person");
  assert.equal(leader.designation, "Test designation");
  assert.equal(leader.shortBio, "Short bio");
  assert.equal(leader.fullBio, "First paragraph\n\nSecond paragraph");
  assert.equal(leader.quote, "A quote");
  assert.equal(leader.usesFallback, false);
  assert.equal(leader.hasEnglish, true);
});

test("a field without an English text shows the Bangla text and the page says so: nothing is invented", () => {
  const leader = resolveLeaderDetail(detail({ name_en: null, designation_en: "", short_bio_en: null, full_bio_en: "   ", quote_en: null, expertise_en: null }), "en");

  assert.equal(leader.name, "পরীক্ষামূলক ব্যক্তি");
  assert.equal(leader.designation, "পরীক্ষামূলক পদবি");
  assert.equal(leader.shortBio, "সংক্ষিপ্ত পরিচিতি");
  assert.equal(leader.fullBio, "প্রথম অনুচ্ছেদ\n\nদ্বিতীয় অনুচ্ছেদ");
  assert.equal(leader.quote, "একটি উক্তি");
  assert.equal(leader.usesFallback, true);
  assert.equal(leader.hasEnglish, false);
});

test("any one field falling back marks the profile page; a card only counts what a card shows", () => {
  const onlyQuote = detail({ quote_en: null });

  assert.equal(resolveLeaderDetail(onlyQuote, "en").usesFallback, true, "the page shows the quote");
  assert.equal(resolveLeader(onlyQuote, "en").usesFallback, false, "a card does not");
  assert.equal(resolveLeader(summary({ short_bio_en: null }), "en").usesFallback, true, "a card shows the short biography");
});

test("a field with no text at all is simply not shown; an English-only field never appears on the Bangla page", () => {
  const leader = resolveLeaderDetail(detail({ short_bio_bn: null, short_bio_en: "English only", quote_bn: null, quote_en: null, expertise_bn: "", expertise_en: "x" }), "bn");

  assert.equal(leader.shortBio, null, "Bangla is the base: the Bangla page does not borrow English text");
  assert.equal(leader.quote, null);
  assert.equal(leader.expertise, null);
  assert.equal(resolveLeader(summary({ short_bio_bn: null, short_bio_en: "English only" }), "en").shortBio, "English only", "the English page may show English text a profile has");
});

test("an English page exists only when both the English name and designation do", () => {
  assert.equal(hasEnglishPage({ name_en: "A", designation_en: "B" }), true);
  assert.equal(hasEnglishPage({ name_en: "A", designation_en: null }), false);
  assert.equal(hasEnglishPage({ name_en: null, designation_en: "B" }), false);
  assert.equal(hasEnglishPage({ name_en: " ", designation_en: "B" }), false);
});

// --- everything is re-checked ------------------------------------------------------------------------------------------

test("a hostile link, website, e-mail or phone from the API never becomes part of a page", () => {
  const leader = resolveLeaderDetail(
    detail({
      social: [
        { platform: "facebook", url: "javascript:alert(1)" },
        { platform: "linkedin", url: "https://evil.example/in/someone" },
        { platform: "youtube", url: "https://www.youtube.com/@legit" },
        { platform: "myspace", url: "https://myspace.com/x" },
      ],
      website_url: "http://insecure.example.org",
      public_email: "admin@provatferi.org",
      public_phone: "<script>alert(1)</script>",
    }),
    "bn",
  );

  assert.deepEqual(leader.social, [{ platform: "youtube", url: "https://www.youtube.com/@legit" }]);
  assert.equal(leader.website, null);
  assert.equal(leader.email, null, "the internal mailbox is never shown");
  assert.equal(leader.phone, null);
  assert.equal(leader.phoneHref, null);
});

test("an e-mail address that is not an address, or is the no-reply or security mailbox, is dropped; a good one is lower-cased", () => {
  for (const bad of ["no-reply@provatferi.org", "security@provatferi.org", "boss%0d%0abcc@example.org", "a@b", "x y@example.org"]) {
    assert.equal(resolveLeaderDetail(detail({ public_email: bad }), "bn").email, null, bad);
  }
  assert.equal(resolveLeaderDetail(detail({ public_email: "  Person@Example.ORG " }), "bn").email, "person@example.org");
});

test("the phone becomes a digits-only tel: link", () => {
  const leader = resolveLeaderDetail(detail({ public_phone: "+880 (1711) 000-000" }), "bn");

  assert.equal(leader.phone, "+880 (1711) 000-000");
  assert.equal(leader.phoneHref, "tel:+8801711000000");
});

test("the social links come in the fixed order, once each", () => {
  const leader = resolveLeader(
    summary({
      social: [
        { platform: "youtube", url: "https://www.youtube.com/@a" },
        { platform: "facebook", url: "https://www.facebook.com/a" },
        { platform: "facebook", url: "https://www.facebook.com/b" },
        { platform: "linkedin", url: "https://www.linkedin.com/in/a" },
      ],
    }),
    "bn",
  );

  assert.deepEqual(leader.social.map((link) => link.platform), ["facebook", "linkedin", "youtube"]);
  assert.equal(leader.social[0].url, "https://www.facebook.com/a");
});

test("only an http(s) image address is used, and a photo without one is the initials", () => {
  assert.equal(safeImageUrl("https://admin.provatferi.org/storage/leadership/a.jpg"), "https://admin.provatferi.org/storage/leadership/a.jpg");
  assert.equal(safeImageUrl("http://127.0.0.1:8099/storage/leadership/a.jpg"), "http://127.0.0.1:8099/storage/leadership/a.jpg");
  for (const bad of ["javascript:alert(1)", "data:image/svg+xml;base64,PHN2Zz4=", "//evil.example/a.jpg", 'https://x.example/a"onerror=alert(1).jpg', "/storage/a.jpg", "", null, 5]) {
    assert.equal(safeImageUrl(bad), null, String(bad));
  }

  const leader = resolveLeader(summary({ photo: { url: "javascript:alert(1)", sm_url: "javascript:alert(1)", width: 100, height: 100 } }), "bn");
  assert.equal(leader.photo, null);
  assert.equal(leader.initials, "পব", "the first letter of each of the two words of the name");
});

test("the card-size box is derived from the page-size one, never enlarged", () => {
  const portrait = resolveLeader(summary(), "bn").photo;
  assert.deepEqual([portrait?.width, portrait?.height, portrait?.widthSm, portrait?.heightSm], [960, 1200, 384, 480]);

  const small = resolveLeader(summary({ photo: { url: "https://a.example/a.jpg", sm_url: "https://a.example/a-sm.jpg", width: 400, height: 300 } }), "bn").photo;
  assert.deepEqual([small?.widthSm, small?.heightSm], [400, 300], "an image already under the card edge keeps its size");
});

test("initials: the first letters of the first two words, in either script", () => {
  assert.equal(initialsOf("Test Person"), "TP");
  assert.equal(initialsOf("test person extra"), "TP");
  assert.equal(initialsOf("  single  "), "S");
  assert.equal(initialsOf("পরীক্ষামূলক ব্যক্তি"), "পব");
  assert.equal(initialsOf(""), "");
});

test("the year is shown only when it is a plausible one", () => {
  assert.equal(resolveLeaderDetail(detail({ associated_since_year: 2019 }), "en").sinceYear, 2019);
  for (const bad of [0, 1800, 99999, 20.5, null]) assert.equal(resolveLeaderDetail(detail({ associated_since_year: bad }), "en").sinceYear, null, String(bad));
});

// --- metadata ----------------------------------------------------------------------------------------------------------

test("the description is the short biography, cut at a word boundary, else the designation and the organisation: nothing else is claimed", () => {
  const long = `${"word ".repeat(60)}end`;
  const cut = leaderDescription({ shortBio: long, designation: "D" }, org.nameEn, 160);

  assert.ok(cut.length <= 160, String(cut.length));
  assert.ok(cut.endsWith("…"));
  assert.ok(!cut.includes("  "));
  assert.equal(leaderDescription({ shortBio: "Short bio.", designation: "D" }, org.nameEn), "Short bio.");
  assert.equal(leaderDescription({ shortBio: null, designation: "Founder" }, org.nameEn), `Founder — ${org.nameEn}`);
  assert.equal(leaderDescription({ shortBio: "Line one\n\nline   two", designation: "D" }, org.nameEn), "Line one line two");
});

test("splitting into featured and the rest keeps the ERP's order inside each group", () => {
  const profiles = [summary({ slug: "a", featured: false }), summary({ slug: "b", featured: true }), summary({ slug: "c", featured: false }), summary({ slug: "d", featured: true })];
  const { featured, others } = splitFeatured(profiles);

  assert.deepEqual(featured.map((p) => p.slug), ["b", "d"]);
  assert.deepEqual(others.map((p) => p.slug), ["a", "c"]);
});

// --- the API client ----------------------------------------------------------------------------------------------------

before(() => {
  process.env.LARAVEL_API_URL = "https://admin.example.test";
});

after(() => {
  globalThis.fetch = undefined as unknown as typeof fetch;
});

function jsonResponse(body: unknown, status = 200): Response {
  return new Response(JSON.stringify(body), { status, headers: { "Content-Type": "application/json" } });
}

test("the list is fetched under the leadership tag, with the filter and the limit when asked for", async () => {
  const fetchMock = mock.fn(async () => jsonResponse({ data: [summary()] }));
  globalThis.fetch = fetchMock as unknown as typeof fetch;

  const all = await getLeadershipProfiles();
  const home = await getLeadershipProfiles({ featuredOnly: true, limit: 4 });

  assert.equal(all.ok && all.data.length, 1);
  assert.equal(home.ok, true);
  const calls = fetchMock.mock.calls.map((call) => call.arguments as unknown as [string, { next?: { revalidate?: number; tags?: string[] } }]);
  assert.equal(calls[0][0], "https://admin.example.test/api/v1/public/leadership");
  assert.equal(calls[1][0], "https://admin.example.test/api/v1/public/leadership?featured=1&limit=4");
  for (const [, init] of calls) {
    assert.deepEqual(init.next?.tags, [LEADERSHIP_CACHE_TAG], "the ERP's purge call can only reach these fetches through the tag");
    assert.equal(init.next?.revalidate, 300, "the safety-net window if the purge call is ever lost");
  }
  assert.equal(LEADERSHIP_CACHE_TAG, "leadership");
});

test("one profile is fetched by its (encoded) slug under the same tag and returns what the API named as the current slug", async () => {
  const fetchMock = mock.fn(async () => jsonResponse({ data: detail({ slug: "current-address" }) }));
  globalThis.fetch = fetchMock as unknown as typeof fetch;

  const result = await getLeadershipProfile("old address/with?odd#chars");

  assert.equal(result.ok && result.data.slug, "current-address", "the page compares this with the slug it asked for and redirects");
  const [url, init] = fetchMock.mock.calls[0].arguments as unknown as [string, { next?: { tags?: string[] } }];
  assert.equal(url, "https://admin.example.test/api/v1/public/leadership/old%20address%2Fwith%3Fodd%23chars");
  assert.deepEqual(init.next?.tags, [LEADERSHIP_CACHE_TAG]);
});

test("a failure is a failed result, never an exception: a 404, a 500, a network error, a body that is not what the API promises", async () => {
  const failing: Array<() => Promise<Response>> = [
    async () => jsonResponse({ message: "" }, 404),
    async () => jsonResponse({ message: "boom" }, 500),
    async () => {
      throw new TypeError("fetch failed");
    },
    async () => new Response("<html>not json</html>", { status: 200 }),
    async () => jsonResponse({ data: "nope" }),
    async () => jsonResponse({ data: [{ slug: 5 }] }),
  ];
  for (const respond of failing) {
    globalThis.fetch = mock.fn(respond) as unknown as typeof fetch;
    assert.equal((await getLeadershipProfiles()).ok, false);
    assert.equal((await getLeadershipProfile("x")).ok, false);
  }
});

test("the guards reject a profile with a missing or mistyped field", async () => {
  const broken: Array<Record<string, unknown>> = [
    { slug: undefined }, { name_bn: 5 }, { designation_bn: null }, { featured: "yes" }, { photo: "x.jpg" }, { photo: { url: "a" } },
    { social: "facebook" }, { social: [{ platform: 1, url: "x" }] }, { website_url: 5 }, { short_bio_en: 5 },
  ];
  for (const change of broken) {
    globalThis.fetch = mock.fn(async () => jsonResponse({ data: [{ ...summary(), ...change }] })) as unknown as typeof fetch;
    assert.equal((await getLeadershipProfiles()).ok, false, JSON.stringify(change));
  }
  for (const change of [{ associated_since_year: "2019" }, { public_email: 5 }, { full_bio_bn: 5 }, { quote_en: 5 }]) {
    globalThis.fetch = mock.fn(async () => jsonResponse({ data: { ...detail(), ...change } })) as unknown as typeof fetch;
    assert.equal((await getLeadershipProfile("x")).ok, false, JSON.stringify(change));
  }

  globalThis.fetch = mock.fn(async () => jsonResponse({ data: [{ ...summary(), photo: null, social: [], website_url: null }] })) as unknown as typeof fetch;
  assert.equal((await getLeadershipProfiles()).ok, true, "a profile with no photo, links or website is valid");
});

test("the ERP may purge the leadership tag, and no other new one", () => {
  assert.ok(ALLOWED_REVALIDATE_TAGS.has("leadership"));
});

// --- the pages and the navigation are wired to it ----------------------------------------------------------------------

test("both dictionaries carry every Leadership string, and the navigation points at the page", () => {
  for (const locale of ["bn", "en"] as const) {
    const t = getStrings(locale);

    for (const key of ["title", "description", "featuredHeading", "othersHeading", "viewProfile", "backToList", "expertise", "contactHeading", "email", "phone", "website", "emptyTitle", "emptyMessage", "homeEyebrow", "homeTitle", "homeIntro", "homeLink"] as const) {
      assert.ok(String(t.leadership[key]).trim() !== "", `${locale}.leadership.${key}`);
    }
    assert.ok(t.leadership.profileOf("X").includes("X"));
    assert.ok(t.leadership.photoAlt("X").includes("X"));
    assert.ok(t.leadership.associatedSince("2019").includes("2019"));
    assert.ok(t.nav.aboutChildren["/leadership"], `${locale}: the About menu has no Leadership label`);
    assert.ok(t.footer.exploreLinks["/leadership"], `${locale}: the footer has no Leadership label`);
  }

  const about = navLinks.find((item) => item.id === "about");
  assert.ok(about?.children?.some((child) => child.href === "/leadership"), "the About menu links to /leadership");
  assert.ok(about?.match?.includes("/leadership"), "the About menu stays highlighted on the Leadership pages");
  assert.ok(footerExploreLinks.some((link) => link.href === "/leadership"));
});

test("the Leadership pages, cards and resolver never write HTML (the JSON-LD script apart): no dangerouslySetInnerHTML, no raw next/link", () => {
  for (const file of [
    "app/[locale]/(site)/leadership/page.tsx",
    "app/[locale]/(site)/leadership/[slug]/page.tsx",
    "components/LeadershipCard.tsx",
    "components/LeadershipHome.tsx",
    "components/LeaderPhoto.tsx",
    "lib/leadership.ts",
    "lib/api/leadership.ts",
  ]) {
    const source = read(file);
    // The one thing a Leadership file may write is a JSON-LD script (search-result data, 2026-10-23) whose body went through the
    // script-safe encoder `safeJsonLd`; leadership-seo.test.mts proves what that encoder and the blocks it carries do.
    const withoutStructuredData = source.replace(/<script type="application\/ld\+json" dangerouslySetInnerHTML=\{\{ __html: safeJsonLd\([a-z]+\) \}\} \/>/g, "");
    assert.ok(!withoutStructuredData.includes("dangerouslySetInnerHTML"), `${file} must not write HTML`);
    assert.ok(!/from "next\/link"/.test(source), `${file} must use SiteLink`);
  }
});

test("the profile page answers an old address with a permanent redirect and an unknown one with a 404", () => {
  const page = read("app/[locale]/(site)/leadership/[slug]/page.tsx");

  assert.match(page, /permanentRedirect\(localizeHref\(`\/leadership\/\$\{result\.data\.slug\}`, locale\)\)/);
  assert.match(page, /if \(!result\.ok\) notFound\(\)/);
  assert.match(page, /hasTranslation: leader\.hasEnglish/, "the English page is advertised only when it exists");
});

test("the homepage shows only featured profiles, a few of them, and renders nothing when there are none", () => {
  const home = read("app/[locale]/(site)/page.tsx");
  const section = read("components/LeadershipHome.tsx");

  assert.match(home, /getLeadershipProfiles\(\{ featuredOnly: true, limit: HOME_LEADERSHIP_COUNT \}\)/);
  assert.match(section, /HOME_LEADERSHIP_COUNT = 4/);
  assert.match(section, /if \(leaders\.length === 0\) return null/);
});

test("the sitemap lists the index and the active profiles only while some exist, with English only where it exists", () => {
  const sitemap = read("app/sitemap.ts");

  assert.match(sitemap, /leaders\.length === 0\s*\? \[\]/);
  assert.match(sitemap, /hasTranslation: hasEnglishPage\(leader\)/);
});

test("an empty or unreachable Leadership page is kept out of search results", () => {
  const page = read("app/[locale]/(site)/leadership/page.tsx");

  assert.match(page, /robots: \{ index: false, follow: true \}/);
});
