// Production-grade check of the Leadership share menu and search/share-card data (admin-erp/docs/LEADERSHIP_SYSTEM.md,
// "Share and search results"). READ-ONLY: it only GETs public pages and the public API; it creates, edits and deletes nothing, and
// it needs no login. It works for every profile the API lists — nothing is hard-coded to a person.
//
//   node scripts/leadership-seo-qa.mjs --phase seo|baseline|compare|verify --site https://provatferi.org --api https://admin.provatferi.org --out <dir>
//
//   seo       HTTP only: for every active profile in both languages — title, description, canonical, hreflang, Open Graph, Twitter,
//             JSON-LD (Person, BreadcrumbList), privacy, image, sitemap, internal links, 404 for an unknown profile.
//   baseline  real Chrome: screenshots and a measurement file (rectangles, fonts) of the Leadership pages — run it BEFORE a change.
//   compare   real Chrome: the same again with the new button hidden; every measurement and every pixel must equal the baseline.
//   verify    real Chrome: the share menu itself — open, outside click, Escape, keyboard, share (native and fallback), copy
//             (exact clipboard text), Bangla/English, 390 px phone and desktop, light and dark, touch.
//
// `--local` marks a site that is not production (its canonical addresses still name the production origin: the site builds
// them from lib/content.ts, so the checks stay valid). Exit code 1 when a check fails.
import { createRequire } from "node:module";
import { existsSync, mkdirSync, readFileSync, writeFileSync } from "node:fs";
import { join } from "node:path";

const arg = (name, fallback) => {
  const i = process.argv.indexOf(`--${name}`);
  return i > -1 ? process.argv[i + 1] : fallback;
};
const PHASE = arg("phase", "seo");
const SITE = (arg("site", "https://provatferi.org") ?? "").replace(/\/+$/, "");
const API = (arg("api", "https://admin.provatferi.org") ?? "").replace(/\/+$/, "");
const OUT = arg("out");
const ORIGIN = "https://provatferi.org"; // what the site writes into canonical addresses (lib/content.ts: org.website)
const LABEL = arg("label", PHASE);
if (!OUT) {
  console.error("usage: --phase seo|baseline|compare|verify --site <url> --api <url> --out <dir> [--label <name>] [--only <slug>]");
  process.exit(2);
}
mkdirSync(OUT, { recursive: true });

// ---------------------------------------------------------------------------------------------------------- reporting
const checks = [];
const notes = [];
const check = (id, description, ok, detail = "") => {
  const row = { id, description, ok: Boolean(ok), detail: String(detail).slice(0, 500) };
  checks.push(row);
  console.log(`${row.ok ? "PASS" : "FAIL"}  ${id.padEnd(5)} ${description}${row.ok || !row.detail ? "" : `  -> ${row.detail.slice(0, 300)}`}`);
};
const note = (text) => { notes.push(String(text)); console.log(`       ${text}`); };
function finish() {
  const failed = checks.filter((c) => !c.ok);
  writeFileSync(join(OUT, `report-${LABEL}.json`), JSON.stringify({ phase: PHASE, site: SITE, passed: checks.length - failed.length, failed: failed.length, checks, notes }, null, 2));
  console.log(`\n${checks.length - failed.length}/${checks.length} checks passed${failed.length ? `; FAILED: ${[...new Set(failed.map((c) => c.id))].join(", ")}` : ""}`);
  process.exit(failed.length ? 1 : 0);
}

// ---------------------------------------------------------------------------------------------------------- data
const decode = (s) => String(s).replace(/&quot;/g, '"').replace(/&#x27;/g, "'").replace(/&#39;/g, "'").replace(/&lt;/g, "<").replace(/&gt;/g, ">").replace(/&amp;/g, "&");
const getJson = async (url) => {
  const res = await fetch(url, { headers: { accept: "application/json" } });
  return { status: res.status, json: res.status === 200 ? await res.json() : null };
};

/** Every active profile with its full detail, exactly as the public API gives it (the same data the pages are built from). */
async function loadProfiles() {
  const list = await getJson(`${API}/api/v1/public/leadership`);
  if (list.status !== 200) throw new Error(`the public Leadership API answered ${list.status}`);
  const only = arg("only");
  const profiles = [];
  for (const item of list.json.data) {
    if (only && item.slug !== only) continue;
    const detail = await getJson(`${API}/api/v1/public/leadership/${item.slug}`);
    profiles.push({ ...item, ...(detail.json?.data ?? {}) });
  }
  return profiles;
}
const hasEnglish = (p) => Boolean(p.name_en?.trim() && p.designation_en?.trim());
const pageUrl = (slug, locale) => `${SITE}${locale === "en" ? "/en" : ""}/leadership/${slug}`;
const canonicalOf = (p, locale) => `${ORIGIN}${locale === "en" && hasEnglish(p) ? "/en" : ""}/leadership/${p.slug}`;

/** The head of a page as a machine sees it. */
function parsePage(html) {
  const attrs = (raw) => Object.fromEntries([...raw.matchAll(/([a-zA-Z:-]+)="([^"]*)"/g)].map((a) => [a[1].toLowerCase(), decode(a[2])]));
  const head = (html.match(/<head[\s\S]*?<\/head>/i) ?? [""])[0];
  const metas = [...html.matchAll(/<meta\s+([^>]*?)\/?>/gi)].map((m) => attrs(m[1]));
  const links = [...html.matchAll(/<link\s+([^>]*?)\/?>/gi)].map((m) => attrs(m[1]));
  const meta = (key) => metas.filter((m) => (m.name ?? m.property) === key).map((m) => m.content ?? "");
  const ld = [...html.matchAll(/<script[^>]*type="application\/ld\+json"[^>]*>([\s\S]*?)<\/script>/gi)].map((m) => {
    try { return { ok: true, value: JSON.parse(m[1]), raw: m[1] }; } catch (error) { return { ok: false, error: String(error), raw: m[1] }; }
  });
  // Whole document, not just <head>: Next streams a page's metadata into the body for a visitor it does not take for a crawler.
  const titles = [...html.matchAll(/<title[^>]*>([\s\S]*?)<\/title>/gi)].map((m) => decode(m[1]));
  const text = (re) => { const m = html.match(re); return m ? decode(m[1].replace(/<[^>]+>/g, "").replace(/\s+/g, " ").trim()) : null; };
  return {
    head, metas, links, meta, ld, titles,
    canonicals: links.filter((l) => l.rel === "canonical").map((l) => l.href),
    alternates: links.filter((l) => l.rel === "alternate" && l.hreflang).map((l) => [l.hreflang, l.href]),
    h1: text(/<h1[^>]*>([\s\S]*?)<\/h1>/i),
    designation: text(/<div class="page-header[^"]*"[\s\S]*?<\/h1>\s*<p>([\s\S]*?)<\/p>/i),
  };
}
async function fetchPage(url) {
  // What a share-card crawler (Facebook, WhatsApp, X, LinkedIn) is given: Next sends such a client its metadata in <head> before
  // anything else, and so it does search crawlers. A browser may get the same tags streamed into the body and moved by script.
  const res = await fetch(url, { redirect: "manual", headers: { "user-agent": "facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)" } });
  const html = await res.text();
  return { url, status: res.status, location: res.headers.get("location"), type: res.headers.get("content-type") ?? "", robotsHeader: res.headers.get("x-robots-tag") ?? "", html, page: parsePage(html) };
}

const looksLikePrivate = (text, profile) => {
  const secrets = [profile.public_email, profile.public_phone].filter((v) => typeof v === "string" && v.trim() !== "");
  return secrets.filter((s) => text.includes(s) || text.includes(s.replace(/\s+/g, "")));
};
const sameSet = (a, b) => JSON.stringify([...a].sort()) === JSON.stringify([...b].sort());
const ALLOWED_PERSON_KEYS = ["@context", "@type", "name", "alternateName", "jobTitle", "description", "image", "url", "worksFor", "sameAs"];
const stripSuffix = (title) => title.replace(/\s*\|\s*Provatferi$/, "");

// ============================================================================================================ SEO (HTTP only)
async function seoPhase() {
  const profiles = await loadProfiles();
  note(`${profiles.length} active profile(s) in the public API: ${profiles.map((p) => p.slug).join(", ")}`);
  const sitemap = await (await fetch(`${SITE}/sitemap.xml`)).text();
  const sitemapEntries = [...sitemap.matchAll(/<url>([\s\S]*?)<\/url>/g)].map((m) => ({ loc: (m[1].match(/<loc>(.*?)<\/loc>/) ?? [])[1], alternates: [...m[1].matchAll(/hreflang="([^"]+)" href="([^"]+)"/g)].map((a) => [a[1], a[2]]) }));

  for (const p of profiles) {
    for (const locale of ["bn", "en"]) {
      const tag = `${p.slug} ${locale}`;
      const r = await fetchPage(pageUrl(p.slug, locale));
      const pg = r.page;
      const english = hasEnglish(p);
      const canonical = canonicalOf(p, locale);
      const name = locale === "en" && english ? p.name_en : p.name_bn;
      const designation = locale === "en" && english ? p.designation_en : p.designation_bn;
      const photoAlt = locale === "en" ? `Photo of ${name}` : `${name}-এর ছবি`;

      check("S1", `${tag}: HTTP 200, an HTML page, indexable (no noindex in a meta tag or the X-Robots-Tag header)`, r.status === 200 && /text\/html/.test(r.type) && !/noindex/i.test(r.robotsHeader) && !pg.meta("robots").some((v) => /noindex/i.test(v)), `${r.status} ${r.type} ${r.robotsHeader}`);
      check("S2", `${tag}: exactly one <title>, "${locale === "en" && english ? "name — designation | Provatferi" : "name — designation | Provatferi"}"`, pg.titles.length === 1 && pg.titles[0] === `${name} — ${designation} | Provatferi`, pg.titles.join(" || "));
      const description = pg.meta("description");
      check("S3", `${tag}: exactly one meta description, 40-320 characters, no e-mail or phone`, description.length === 1 && description[0].length >= 40 && description[0].length <= 320 && !/@|\+?\d[\d\s-]{8,}/.test(description[0]), `${description.length}: ${description[0]?.slice(0, 80)}`);
      check("S4", `${tag}: exactly one canonical, and it is ${canonical.replace(ORIGIN, "")}`, pg.canonicals.length === 1 && pg.canonicals[0] === canonical, pg.canonicals.join(" "));
      const expectedAlternates = [["bn", `${ORIGIN}/leadership/${p.slug}`], ["x-default", `${ORIGIN}/leadership/${p.slug}`], ...(english ? [["en", `${ORIGIN}/en/leadership/${p.slug}`]] : [])];
      check("S5", `${tag}: hreflang is bn, x-default${english ? ", en" : " (no en: the English page does not exist)"}, each once, pointing at the right address`, sameSet(pg.alternates.map((a) => a.join("=")), expectedAlternates.map((a) => a.join("="))) && pg.alternates.length === expectedAlternates.length, JSON.stringify(pg.alternates));

      // Open Graph
      const one = (key) => (pg.meta(key).length === 1 ? pg.meta(key)[0] : null);
      const og = {
        type: one("og:type"), title: one("og:title"), description: one("og:description"), url: one("og:url"), siteName: one("og:site_name"), locale: one("og:locale"),
        image: one("og:image"), imageAlt: one("og:image:alt"), imageWidth: one("og:image:width"), imageHeight: one("og:image:height"), imageType: one("og:image:type"),
        alternate: pg.meta("og:locale:alternate"),
      };
      check("S6a", `${tag}: og:type is "profile" (the page is about one person)`, og.type === "profile", String(og.type));
      check("S6b", `${tag}: og:title is the page title, og:description the meta description, og:url the canonical address, og:site_name present`, og.title === stripSuffix(pg.titles[0] ?? "") && og.description === description[0] && og.url === canonical && Boolean(og.siteName), JSON.stringify({ title: og.title, url: og.url, siteName: og.siteName }));
      check("S6c", `${tag}: og:locale is ${locale === "en" ? "en_US" : "bn_BD"}, og:locale:alternate ${english ? `names ${locale === "en" ? "bn_BD" : "en_US"}` : "is absent (no other-language page)"}`, og.locale === (locale === "en" ? "en_US" : "bn_BD") && (english ? sameSet(og.alternate, [locale === "en" ? "bn_BD" : "en_US"]) : og.alternate.length === 0), JSON.stringify({ locale: og.locale, alternate: og.alternate }));
      if (p.photo) {
        check("S6d", `${tag}: og:image is the public optimised photo (https, /storage/leadership/, not a private path), with its real width and height, alt text and type`, og.image === p.photo.url && /^https:\/\/[^/]+\/storage\/leadership\/[0-9a-f-]+\.jpg$/.test(og.image ?? "") && og.imageWidth === String(p.photo.width) && og.imageHeight === String(p.photo.height) && og.imageAlt === photoAlt && og.imageType === "image/jpeg", JSON.stringify({ image: og.image, w: og.imageWidth, h: og.imageHeight, alt: og.imageAlt, type: og.imageType }));
      }
      // Twitter / social card
      const twitter = { card: one("twitter:card"), title: one("twitter:title"), description: one("twitter:description"), image: one("twitter:image"), alt: one("twitter:image:alt") };
      check("S7", `${tag}: social card: summary_large_image with its own title, description${p.photo ? ", photo and photo alt" : ""}`, twitter.card === "summary_large_image" && twitter.title === og.title && twitter.description === description[0] && (!p.photo || (twitter.image === p.photo.url && twitter.alt === photoAlt)), JSON.stringify(twitter));

      // Structured data
      check("S8a", `${tag}: every JSON-LD block parses`, pg.ld.length > 0 && pg.ld.every((b) => b.ok), pg.ld.filter((b) => !b.ok).map((b) => b.error).join(" | "));
      const blocks = pg.ld.filter((b) => b.ok).map((b) => b.value);
      const ofType = (type) => blocks.filter((b) => b["@type"] === type);
      check("S8b", `${tag}: the page carries Organization, WebSite, Person and BreadcrumbList, each once`, ["Organization", "WebSite", "Person", "BreadcrumbList"].every((t) => ofType(t).length === 1), blocks.map((b) => b["@type"]).join(","));
      const person = ofType("Person")[0];
      if (person) {
        check("S9a", `${tag}: Person name and jobTitle are what the page shows (name = the heading, jobTitle = the designation under it)`, person.name === pg.h1 && person.jobTitle === pg.designation && person.name === name && person.jobTitle === designation, JSON.stringify({ name: person.name, h1: pg.h1, jobTitle: person.jobTitle, shown: pg.designation }));
        check("S9b", `${tag}: Person url is the canonical address`, person.url === canonical, String(person.url));
        check("S9c", `${tag}: worksFor is Organization "Provatferi Literary and Cultural Center", alternateName "PLCC", url ${ORIGIN}`, JSON.stringify(person.worksFor) === JSON.stringify({ "@type": "Organization", name: "Provatferi Literary and Cultural Center", alternateName: "PLCC", url: ORIGIN }), JSON.stringify(person.worksFor));
        const expectedSameAs = (p.social ?? []).map((s) => s.url);
        check("S9d", `${tag}: sameAs lists the person's stored social profiles and nothing else${expectedSameAs.length ? "" : " (none stored: the key is absent)"}`, expectedSameAs.length ? JSON.stringify(person.sameAs) === JSON.stringify(expectedSameAs) : !("sameAs" in person), JSON.stringify(person.sameAs));
        check("S9e", `${tag}: Person image is the profile photo${p.photo ? "" : " (no photo stored: the key is absent)"}`, p.photo ? person.image?.url === p.photo.url && person.image?.width === p.photo.width && person.image?.height === p.photo.height : !("image" in person), JSON.stringify(person.image));
        check("S9f", `${tag}: only the allowed Person keys, none empty, no e-mail, phone, birth date, education, awards or credentials`, Object.keys(person).every((k) => ALLOWED_PERSON_KEYS.includes(k)) && Object.values(person).every((v) => v !== "" && v !== null && !(Array.isArray(v) && v.length === 0)), Object.keys(person).join(","));
        const alt = person.alternateName;
        const otherName = locale === "bn" ? p.name_en : p.name_bn;
        check("S9g", `${tag}: alternateName is the same person's name in the other language, only when stored`, otherName?.trim() && otherName.trim() !== name ? alt === otherName.trim() : !("alternateName" in person), String(alt));
        check("S9h", `${tag}: description is the stored short biography (or absent)`, (locale === "en" && p.short_bio_en ? p.short_bio_en : p.short_bio_bn) ? typeof person.description === "string" && person.description.length > 0 : !("description" in person), String(person.description).slice(0, 60));
      } else {
        check("S9", `${tag}: a Person block exists`, false);
      }
      const crumbs = ofType("BreadcrumbList")[0];
      const root = locale === "en" ? `${ORIGIN}/en` : ORIGIN;
      check("S10", `${tag}: BreadcrumbList is Home → Leadership → the person, positions 1-3, absolute addresses in this language`, Boolean(crumbs) && JSON.stringify((crumbs?.itemListElement ?? []).map((i) => [i.position, i.item])) === JSON.stringify([[1, root], [2, `${root}/leadership`], [3, canonical]]) && crumbs.itemListElement[2].name === name && crumbs.itemListElement.every((i) => typeof i.name === "string" && i.name.trim() !== ""), JSON.stringify(crumbs?.itemListElement));

      // Privacy: the intentionally public contact lines appear in the visible contact block only — never in a meta tag or structured data.
      const machine = pg.head + pg.ld.map((b) => b.raw).join(" ");
      check("S11a", `${tag}: no e-mail or phone number in the <head> or any structured data`, looksLikePrivate(machine, p).length === 0, looksLikePrivate(machine, p).length + " leaked");
      check("S11b", `${tag}: no admin address, API address or private storage path anywhere in the page except the public photo address`, !/uploads_private|\/api\/v1\/|\/admin\/(?!storage\/)|admin\.provatferi\.org\/(?!storage\/leadership\/)/.test(r.html.replace(/https:\/\/admin\.provatferi\.org\/storage\/leadership\/[0-9a-f-]+\.jpg/g, "")), "");
      // Image
      if (p.photo) {
        const img = r.html.match(new RegExp(`<img[^>]*src="${p.photo.url.replace(/[.*+?^${}()|[\]\\]/g, "\\$&")}"[^>]*>`));
        const imgAttrs = img ? Object.fromEntries([...img[0].matchAll(/([a-zA-Z:-]+)="([^"]*)"/g)].map((a) => [a[1].toLowerCase(), decode(a[2])])) : {};
        check("S12", `${tag}: the portrait has the person's name in its alt text and its real width and height written in the markup`, Boolean(img) && imgAttrs.alt === photoAlt && imgAttrs.width === String(p.photo.width) && imgAttrs.height === String(p.photo.height), JSON.stringify(imgAttrs));
      }
      // The share button is in the server-rendered page
      check("S13", `${tag}: the share / copy-link menu button is in the page (a menu button with an accessible name)`, /class="leader-actions[^"]*"[^>]*><button[^>]*aria-haspopup="menu"[^>]*aria-label="[^"]+"|<button[^>]*aria-label="[^"]+"[^>]*aria-haspopup="menu"/.test(r.html), "");
    }

    // Sitemap
    const entry = sitemapEntries.find((e) => e.loc === `${ORIGIN}/leadership/${p.slug}`);
    check("S14", `${p.slug}: it is in the sitemap with hreflang bn${hasEnglish(p) ? " and en" : " only"}`, Boolean(entry) && sameSet((entry?.alternates ?? []).map((a) => a.join("=")), [`bn=${ORIGIN}/leadership/${p.slug}`, ...(hasEnglish(p) ? [`en=${ORIGIN}/en/leadership/${p.slug}`] : [])]), JSON.stringify(entry));
  }
  const leadershipLocs = sitemapEntries.map((e) => e.loc).filter((loc) => /\/leadership\//.test(loc));
  check("S14", "the sitemap lists exactly the active profiles (no inactive or archived one) plus the index", sameSet(leadershipLocs, profiles.map((p) => `${ORIGIN}/leadership/${p.slug}`)) && sitemapEntries.some((e) => e.loc === `${ORIGIN}/leadership`), leadershipLocs.join(" "));

  // Internal links
  for (const locale of ["bn", "en"]) {
    const index = await fetchPage(`${SITE}${locale === "en" ? "/en" : ""}/leadership`);
    const missing = profiles.filter((p) => {
      const href = `${locale === "en" && hasEnglish(p) ? "/en" : ""}/leadership/${p.slug}`;
      const name = locale === "en" && hasEnglish(p) ? p.name_en : p.name_bn;
      return !new RegExp(`<a[^>]*href="${href}"[^>]*>[^<]*${name.replace(/[.*+?^${}()|[\]\\]/g, "\\$&")}`).test(index.html);
    });
    check("S15", `${locale} index: every active profile is a crawlable text link (the name is the link text, not an image)`, missing.length === 0, missing.map((p) => p.slug).join(","));
    check("S15", `${locale} index: BreadcrumbList present, one canonical, indexable`, index.page.ld.some((b) => b.ok && b.value["@type"] === "BreadcrumbList") && index.page.canonicals.length === 1 && !index.page.meta("robots").some((v) => /noindex/i.test(v)), "");
    const home = await fetchPage(locale === "en" ? `${SITE}/en` : `${SITE}/`); // "/en/" is a 308 to "/en"
    const featured = profiles.filter((p) => p.featured).slice(0, 4);
    const missingHome = featured.filter((p) => !home.html.includes(`href="${locale === "en" && hasEnglish(p) ? "/en" : ""}/leadership/${p.slug}"`));
    check("S15", `${locale} homepage: the featured profiles (first ${featured.length}) are linked`, missingHome.length === 0, missingHome.map((p) => p.slug).join(","));
  }

  // Unknown / inactive profiles are not pages at all
  for (const locale of ["bn", "en"]) {
    const gone = await fetchPage(pageUrl("zz-no-such-person-qa", locale));
    check("S16", `${locale}: an address that is not an active profile answers 404 with no Person data`, gone.status === 404 && !gone.page.ld.some((b) => b.ok && b.value["@type"] === "Person"), `${gone.status}`);
  }
  const api404 = await getJson(`${API}/api/v1/public/leadership/zz-no-such-person-qa`);
  check("S16", "the public API answers 404 for it too", api404.status === 404, `${api404.status}`);
}

// ============================================================================================================ browser helpers
const require = createRequire(import.meta.url);
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const CHROME = [process.env.CHROME_PATH, "C:/Program Files/Google/Chrome/Application/chrome.exe", "C:/Program Files (x86)/Google/Chrome/Application/chrome.exe", "/usr/bin/google-chrome", "/usr/bin/chromium"].filter(Boolean).find(existsSync);

const VIEWPORTS = {
  desktop: { width: 1366, height: 900, deviceScaleFactor: 1 },
  tablet: { width: 820, height: 1000, deviceScaleFactor: 1 },
  mobile: { width: 390, height: 844, deviceScaleFactor: 1, isMobile: true, hasTouch: true },
};

async function launch() {
  if (!CHROME) throw new Error("No Chrome found; set CHROME_PATH");
  const puppeteer = require("puppeteer-core");
  // The three "background" switches keep a page's timers and animation frames running while other pages of the run are open.
  return puppeteer.launch({
    executablePath: CHROME, headless: true, protocolTimeout: 60000,
    args: ["--no-sandbox", "--disable-dev-shm-usage", "--hide-scrollbars", "--disable-background-timer-throttling", "--disable-renderer-backgrounding", "--disable-backgrounding-occluded-windows"],
  });
}

/** A fresh browser context + page in a viewport and theme (the site reads its theme from localStorage, then the OS preference). */
async function openPage(browser, { viewport, theme = "light", grant = false }) {
  const context = await browser.createBrowserContext();
  if (grant) await context.overridePermissions(new URL(SITE).origin, ["clipboard-read", "clipboard-write"]).catch(() => {});
  const page = await context.newPage();
  await page.setViewport(VIEWPORTS[viewport]);
  await page.emulateMediaFeatures([{ name: "prefers-color-scheme", value: theme }, { name: "prefers-reduced-motion", value: "reduce" }]);
  await page.evaluateOnNewDocument((t) => { try { localStorage.setItem("provatferi-theme", t); } catch { /* ignore */ } }, theme);
  const problems = [];
  page.on("console", (m) => { if (m.type() === "error") problems.push(`console: ${m.text().slice(0, 160)}`); });
  page.on("pageerror", (e) => problems.push(`pageerror: ${String(e).slice(0, 160)}`));
  // A request the browser itself cancelled (ERR_ABORTED: the harness navigated away while Next was still fetching a payload) is not a
  // failure; a refused, reset, blocked or timed-out one is, and so is any answer with status 400 or more.
  page.on("requestfailed", (r) => {
    if (process.env.QA_TRACE === "1") console.log(`       . request failed: ${r.failure()?.errorText} ${r.url().slice(0, 140)}`);
    if (r.failure()?.errorText !== "net::ERR_ABORTED" && !/\/cdn-cgi\/|favicon|rum/.test(r.url())) problems.push(`requestfailed: ${r.url().slice(0, 120)} ${r.failure()?.errorText ?? ""}`);
  });
  page.on("response", (res) => { if (res.status() >= 400 && !/\/cdn-cgi\/|favicon|rum/.test(res.url())) problems.push(`http ${res.status()}: ${res.url().slice(0, 120)}`); });
  return { page, context, problems };
}

/** Everything is loaded and nothing moves: fonts, lazy images (scrolled through once), no animation, pointer parked in a corner. */
async function settle(page) {
  const TRACE = process.env.QA_TRACE === "1";
  const step = async (name, fn, arg) => { if (TRACE) console.log(`       . ${name}`); return page.evaluate(fn, arg); };
  await page.addStyleTag({ content: "*,*::before,*::after{animation:none!important;transition:none!important;caret-color:transparent!important;scroll-behavior:auto!important}" }).catch(() => {});
  // Every wait below has its own deadline, so a page that never finishes loading something cannot hang the run.
  await step("fonts", () => Promise.race([document.fonts.ready, new Promise((r) => setTimeout(r, 10000))]));
  await step("scroll through (lazy images)", async () => {
    const stepPx = Math.max(300, Math.floor(window.innerHeight * 0.7));
    for (let y = 0; y < document.documentElement.scrollHeight; y += stepPx) { window.scrollTo(0, y); await new Promise((r) => setTimeout(r, 60)); }
    window.scrollTo(0, 0);
  });
  // Loaded is not painted: a progressive JPEG can be on screen in an early scan while `complete` is already true, which made two
  // identical pages photograph differently. Every image is loaded AND decoded, then two frames are painted, before anything is read.
  await step("images", () => Promise.all([...document.images].map(async (img) => {
    if (!img.complete) await new Promise((r) => { img.addEventListener("load", r, { once: true }); img.addEventListener("error", r, { once: true }); setTimeout(r, 8000); });
    try { await Promise.race([img.decode(), new Promise((r) => setTimeout(r, 8000))]); } catch { /* a broken image is reported by the checks, not here */ }
  })));
  await step("frames", () => Promise.race([new Promise((r) => requestAnimationFrame(() => requestAnimationFrame(r))), new Promise((r) => setTimeout(r, 2000))]));
  await page.mouse.move(0, 0);
  await sleep(500);
}

async function go(page, url) {
  const response = await page.goto(url, { waitUntil: "networkidle2", timeout: 90000 });
  await settle(page);
  return response;
}

/** The measurements the design guard compares: every box, the lines of every heading, the fonts. Rounded to a tenth of a pixel. */
const measure = (page) => page.evaluate(() => {
  const round = (n) => Math.round(n * 10) / 10;
  const rect = (el) => { if (!el) return null; const b = el.getBoundingClientRect(); return [round(b.x + scrollX), round(b.y + scrollY), round(b.width), round(b.height)]; };
  const lines = (el) => { if (!el) return null; const range = document.createRange(); range.selectNodeContents(el); return [...range.getClientRects()].map((b) => [round(b.x + scrollX), round(b.y + scrollY), round(b.width), round(b.height)]); };
  const style = (el, props) => { if (!el) return null; const cs = getComputedStyle(el); return Object.fromEntries(props.map((p) => [p, cs[p]])); };
  const TEXT = ["fontFamily", "fontSize", "fontWeight", "lineHeight", "letterSpacing", "color", "textAlign"];
  const BOX = ["paddingTop", "paddingRight", "paddingBottom", "paddingLeft", "marginTop", "marginBottom", "borderTopWidth", "borderRadius", "backgroundColor", "display", "flexDirection", "overflow"];
  return {
    scroll: [document.documentElement.scrollWidth, document.documentElement.scrollHeight],
    header: { block: rect(document.querySelector(".page-header")), h1: rect(document.querySelector(".page-header h1")), h1Lines: lines(document.querySelector(".page-header h1")), lead: rect(document.querySelector(".page-header p")), breadcrumb: lines(document.querySelector(".breadcrumb")) },
    sections: [...document.querySelectorAll("main section, .content-section, .home-leadership")].map((s) => ({ box: rect(s), style: style(s, BOX) })),
    cards: [...document.querySelectorAll(".leader-card")].map((card) => ({
      card: rect(card), style: style(card, BOX),
      photo: rect(card.querySelector(".leader-photo-frame")), img: rect(card.querySelector("img")),
      h3: rect(card.querySelector("h3")), h3Lines: lines(card.querySelector("h3")), h3Style: style(card.querySelector("h3"), TEXT),
      designation: rect(card.querySelector(".leader-designation")), designationLines: lines(card.querySelector(".leader-designation")), designationStyle: style(card.querySelector(".leader-designation"), TEXT),
      bio: rect(card.querySelector(".leader-bio")), social: rect(card.querySelector(".social-links")), cta: rect(card.querySelector(".leader-card-cta")), body: rect(card.querySelector(".leader-card-body")),
    })),
    profile: document.querySelector(".leader-profile-grid") ? {
      grid: rect(document.querySelector(".leader-profile-grid")), aside: rect(document.querySelector(".leader-profile-aside")),
      photo: rect(document.querySelector(".leader-photo-frame")), img: rect(document.querySelector(".leader-photo-frame img")), facts: rect(document.querySelector(".leader-facts")),
      contact: rect(document.querySelector(".leader-contact")), main: rect(document.querySelector(".leader-profile-main")), quote: rect(document.querySelector(".leader-quote")),
      bio: rect(document.querySelector(".leader-bio-text")), bioLines: lines(document.querySelector(".leader-bio-text")), bioStyle: style(document.querySelector(".leader-bio-text p"), TEXT),
    } : null,
  };
});

/** Which pages and sizes the design guard looks at. Profiles come from the API: no person is named in this script. */
async function designCases() {
  const profiles = await loadProfiles();
  const cases = [];
  for (const locale of ["bn", "en"]) {
    for (const viewport of ["desktop", "tablet", "mobile"]) {
      for (const theme of ["light", "dark"]) {
        cases.push({ key: `index|${locale}|${viewport}|${theme}`, url: `${SITE}${locale === "en" ? "/en" : ""}/leadership`, viewport, theme, selector: null });
        cases.push({ key: `home|${locale}|${viewport}|${theme}`, url: locale === "en" ? `${SITE}/en` : `${SITE}/`, viewport, theme, selector: ".home-leadership" });
      }
    }
    for (const p of profiles) {
      for (const viewport of ["desktop", "mobile"]) {
        for (const theme of ["light", "dark"]) cases.push({ key: `profile-${p.slug}|${locale}|${viewport}|${theme}`, url: pageUrl(p.slug, locale), viewport, theme, selector: null });
      }
    }
  }
  return cases;
}

/**
 * Two screenshots of the same page, pixel by pixel. A decoded photograph is not bit-identical from one load to the next (Chrome may
 * decode a JPEG at a reduced scale or in full): production compared with itself differs by 1 of 255 over whole photographs, and by
 * up to ~20 on their edges. So inside the photographs ("images": [x, y, w, h] in screenshot pixels) a pixel counts as different when
 * a channel differs by more than IMAGE_TOLERANCE, and everywhere else by more than TEXT_TOLERANCE (2 of 255: rounding only). A moved
 * or recoloured glyph, a border, a shadow, a different wrap are far above that, and every box, line, font and colour is compared
 * exactly by D1 as well.
 */
const TEXT_TOLERANCE = 2;
const IMAGE_TOLERANCE = 32;
const decodeAndDiff = (page, a, b, images = []) => page.evaluate(async (aB64, bB64, rects, tolText, tolImage) => {
  const bitmap = async (b64) => createImageBitmap(await (await fetch("data:image/png;base64," + b64)).blob());
  const [x, y] = [await bitmap(aB64), await bitmap(bB64)];
  if (x.width !== y.width || x.height !== y.height) return { same: false, size: [[x.width, x.height], [y.width, y.height]], differing: null, box: null };
  const read = (bm) => { const c = new OffscreenCanvas(bm.width, bm.height); const g = c.getContext("2d"); g.drawImage(bm, 0, 0); return g.getImageData(0, 0, bm.width, bm.height).data; };
  const [da, db] = [read(x), read(y)];
  const w = x.width, h = x.height;
  const photo = new Uint8Array(w * h);
  for (const [rx, ry, rw, rh] of rects) {
    for (let py = Math.max(0, Math.floor(ry)); py < Math.min(h, Math.ceil(ry + rh)); py++) for (let px = Math.max(0, Math.floor(rx)); px < Math.min(w, Math.ceil(rx + rw)); px++) photo[py * w + px] = 1;
  }
  let differing = 0, minX = 1e9, minY = 1e9, maxX = -1, maxY = -1, maxInside = 0, maxOutside = 0;
  for (let i = 0; i < da.length; i += 4) {
    const d = Math.max(Math.abs(da[i] - db[i]), Math.abs(da[i + 1] - db[i + 1]), Math.abs(da[i + 2] - db[i + 2]), Math.abs(da[i + 3] - db[i + 3]));
    if (d === 0) continue;
    const idx = i / 4;
    const inPhoto = photo[idx] === 1;
    if (inPhoto) { if (d > maxInside) maxInside = d; } else if (d > maxOutside) maxOutside = d;
    if (d > (inPhoto ? tolImage : tolText)) {
      differing++;
      const px = idx % w, py = Math.floor(idx / w);
      if (px < minX) minX = px; if (px > maxX) maxX = px; if (py < minY) minY = py; if (py > maxY) maxY = py;
    }
  }
  return { same: differing === 0, size: [[w, h], [w, h]], differing, box: differing ? [minX, minY, maxX, maxY] : null, maxInside, maxOutside };
}, a.toString("base64"), b.toString("base64"), images, TEXT_TOLERANCE, IMAGE_TOLERANCE);

// ============================================================================================================ baseline / compare
async function designPhase(mode) {
  let browser = await launch();
  const shots = join(OUT, "shots");
  mkdirSync(shots, { recursive: true });
  const baselineDir = arg("baseline");
  const cases = await designCases();
  const only = arg("filter");
  const measurements = {};
  const baseline = mode === "compare" ? JSON.parse(readFileSync(join(baselineDir, "measure.json"), "utf8")) : null;
  // The page that decodes two screenshots lives in its own browser context, which is opened again whenever the browser is.
  let diffPage = null;
  const prepare = async () => { diffPage = mode === "compare" ? (await openPage(browser, { viewport: "desktop" })).page : null; };
  await prepare();
  note(`${cases.length} case(s) (${mode})`);

  const attempts = Number(arg("attempts", "2"));
  for (const c of cases) {
    if (only && !c.key.includes(only)) continue;
    // A page that hangs or a browser that dies (a laptop that slept) costs one case at most: the browser is replaced and the case is
    // taken again before it counts as a failure.
    for (let attempt = 1; attempt <= attempts; attempt++) {
      if (!browser.connected) { browser = await launch(); await prepare(); }
      if (await captureCase(c, attempt === attempts)) break;
    }
  }
  writeFileSync(join(OUT, "measure.json"), JSON.stringify(measurements));
  if (mode === "baseline") note(`baseline written: ${Object.keys(measurements).length} measurement set(s), ${Object.keys(measurements).length} screenshot(s)`);
  await browser.close().catch(() => {});

  /** One page, one size, one theme. Returns false when it failed and another attempt is still allowed. */
  async function captureCase(c, last) {
    const { page, context, problems } = await openPage(browser, c);
    try {
      const response = await go(page, c.url);
      // "compare" looks at the page as it is, measured with the new button present, then photographs it with the button hidden.
      measurements[c.key] = { status: response?.status(), ...(await measure(page)), buttons: await page.$$eval(".leader-actions", (els) => els.length) };
      if (mode === "compare") await page.addStyleTag({ content: ".leader-actions{visibility:hidden!important}" });
      // Where the photographs are, in the screenshot's own pixels (an element screenshot starts at the element's corner).
      const images = mode !== "compare" ? [] : await page.evaluate((selector) => {
        const origin = selector ? document.querySelector(selector).getBoundingClientRect() : { x: -scrollX, y: -scrollY };
        return [...document.images].map((el) => { const r = el.getBoundingClientRect(); return [r.x - origin.x, r.y - origin.y, r.width, r.height]; }).filter((r) => r[2] > 0 && r[3] > 0);
      }, c.selector);
      const file = join(shots, `${c.key.replace(/[|]/g, "__")}.png`);
      const png = c.selector ? await (await page.$(c.selector)).screenshot({ type: "png" }) : await page.screenshot({ type: "png", fullPage: true });
      writeFileSync(file, png);

      if (mode === "compare") {
        const before = baseline[c.key];
        const boxes = JSON.stringify({ ...measurements[c.key], buttons: undefined, status: undefined }) === JSON.stringify({ ...before, buttons: undefined, status: undefined });
        const beforePng = readFileSync(join(baselineDir, "shots", `${c.key.replace(/[|]/g, "__")}.png`));
        const same = Buffer.compare(beforePng, png) === 0;
        const diff = same ? { same: true, differing: 0, maxInside: 0, maxOutside: 0 } : await decodeAndDiff(diffPage, beforePng, png, images);
        check("D1", `${c.key}: every measured box, line of text and font is identical to before`, boxes, boxes ? "" : firstDifference(before, measurements[c.key]));
        check("D2", `${c.key}: with the new button hidden, every pixel is identical to before (rounding of 2/255, and of ${IMAGE_TOLERANCE}/255 inside photographs, aside)`, diff.same, `${diff.differing} pixel(s) differ in ${JSON.stringify(diff.box)}; sizes ${JSON.stringify(diff.size)}`);
        if (!same && diff.same) note(`${c.key}: only rounding noise (largest difference ${diff.maxOutside} outside photographs, ${diff.maxInside} inside)`);
        check("D3", `${c.key}: the new button is present on every card / the portrait (${measurements[c.key].buttons})`, /profile-/.test(c.key) ? measurements[c.key].buttons === 1 : measurements[c.key].buttons === measurements[c.key].cards.length && measurements[c.key].cards.length > 0, "");
      }
      if (problems.length) note(`${c.key}: ${problems.slice(0, 2).join(" | ")}`);
      return true;
    } catch (error) {
      if (last) check("D!", `${c.key}: the page was captured`, false, String(error).slice(0, 200));
      else note(`${c.key}: attempt failed (${String(error).slice(0, 100)}); trying again`);
      return false;
    } finally {
      await context.close().catch(() => {});
    }
  }
}

/** The first path at which two measurement sets differ, for a readable failure. */
function firstDifference(a, b, path = "") {
  if (JSON.stringify(a) === JSON.stringify(b)) return "";
  if (a && b && typeof a === "object" && typeof b === "object") {
    for (const key of new Set([...Object.keys(a), ...Object.keys(b)])) {
      const found = firstDifference(a[key], b[key], `${path}.${key}`);
      if (found) return found;
    }
  }
  return `${path}: ${JSON.stringify(a)?.slice(0, 120)} -> ${JSON.stringify(b)?.slice(0, 120)}`;
}

// ============================================================================================================ verify (the share menu itself)
const TRIGGER = ".leader-actions.is-ready .leader-actions-trigger";
const WORDS = {
  bn: { copied: "লিংক কপি হয়েছে", share: "প্রোফাইল শেয়ার করুন", copy: "প্রোফাইলের লিংক কপি করুন", copyFailed: "স্বয়ংক্রিয়ভাবে কপি করা যায়নি। নিচের ঘর থেকে লিংকটি নিজে কপি করুন।", actions: (n) => `${n}-এর শেয়ার ও লিংক অপশন` },
  en: { copied: "Link copied", share: "Share profile", copy: "Copy profile link", copyFailed: "The link could not be copied automatically. Copy it from the box below.", actions: (n) => `Share and link options for ${n}` },
};
const oneLine = (s) => String(s ?? "").replace(/\s+/g, " ").trim();
const namesOf = (p, locale) => {
  const en = locale === "en" && hasEnglish(p);
  return { name: oneLine(en ? p.name_en : p.name_bn), designation: oneLine(en ? p.designation_en : p.designation_bn), url: canonicalOf(p, locale) };
};
/** The links the fallback list must show, derived here independently of the site's code. */
function expectedShareLinks({ name, designation, url }) {
  const q = encodeURIComponent;
  const line = designation === "" ? name : `${name} — ${designation}`;
  return {
    whatsapp: `https://wa.me/?text=${q(`${line}\n${url}`)}`,
    facebook: `https://www.facebook.com/sharer/sharer.php?u=${q(url)}`,
    linkedin: `https://www.linkedin.com/sharing/share-offsite/?url=${q(url)}`,
    email: `mailto:?subject=${q(name)}&body=${q(`${line}\n${url}`)}`,
  };
}

const STUBS = {
  ok: () => { navigator.canShare = () => true; navigator.share = async (data) => { window.__shared = JSON.parse(JSON.stringify(data)); }; },
  abort: () => { navigator.canShare = () => true; navigator.share = async () => { window.__shareCalls = (window.__shareCalls ?? 0) + 1; throw Object.assign(new Error("closed"), { name: "AbortError" }); }; },
  denied: () => { navigator.canShare = () => true; navigator.share = async () => { window.__shareCalls = (window.__shareCalls ?? 0) + 1; throw Object.assign(new Error("not allowed"), { name: "NotAllowedError" }); }; },
  none: () => { Object.defineProperty(navigator, "share", { value: undefined, configurable: true }); Object.defineProperty(navigator, "canShare", { value: undefined, configurable: true }); },
  legacyCopy: () => { Object.defineProperty(navigator, "clipboard", { value: undefined, configurable: true }); document.execCommand = (cmd) => { if (cmd === "copy") { window.__legacy = [...document.querySelectorAll("textarea")].map((t) => t.value); return true; } return false; }; },
  noCopy: () => { Object.defineProperty(navigator, "clipboard", { value: undefined, configurable: true }); document.execCommand = () => false; },
};

async function stubbed(browser, options, stub, url) {
  const opened = await openPage(browser, options);
  if (stub) await opened.page.evaluateOnNewDocument(STUBS[stub]);
  await go(opened.page, url);
  await opened.page.waitForSelector(TRIGGER, { visible: true, timeout: 30000 });
  return opened;
}

const state = (page) => page.evaluate(() => {
  const trigger = document.querySelector('.leader-actions-trigger[aria-expanded="true"]');
  const menu = document.querySelector(".leader-menu");
  const items = menu ? [...menu.querySelectorAll('[role="menuitem"]')] : [];
  const r = menu ? menu.getBoundingClientRect() : null;
  const active = document.activeElement;
  return {
    open: Boolean(menu), expanded: Boolean(trigger),
    rect: r ? [r.left, r.top, r.right, r.bottom] : null, viewport: [document.documentElement.clientWidth, document.documentElement.clientHeight],
    items: items.map((el) => ({ text: el.textContent.replace(/\s+/g, " ").trim(), tag: el.tagName, href: el.getAttribute("href"), target: el.getAttribute("target"), rel: el.getAttribute("rel") })),
    activeIndex: items.indexOf(active), activeIsTrigger: Boolean(active?.classList.contains("leader-actions-trigger")), activeTag: active?.tagName ?? null,
    menuId: menu?.querySelector('[role="menu"]')?.id ?? null, controls: trigger?.getAttribute("aria-controls") ?? null,
    manual: menu?.querySelector(".leader-menu-url") ? { value: menu.querySelector(".leader-menu-url").value, selected: [menu.querySelector(".leader-menu-url").selectionStart, menu.querySelector(".leader-menu-url").selectionEnd], note: menu.querySelector(".leader-menu-note")?.textContent ?? null } : null,
    live: [...document.querySelectorAll('.leader-actions [role="status"]')].map((el) => el.textContent).filter(Boolean).join("|"),
  };
});
const inside = (s, edge = 0) => s.rect !== null && s.rect[0] >= edge && s.rect[1] >= edge && s.rect[2] <= s.viewport[0] - edge && s.rect[3] <= s.viewport[1] - edge;
const cardRects = (page) => page.$$eval(".leader-card, .leader-profile-grid", (els) => els.map((el) => { const b = el.getBoundingClientRect(); return [b.x, b.y + scrollY, b.width, b.height].map((n) => Math.round(n * 10) / 10); }));

async function open(page, index = 0) {
  const triggers = await page.$$(TRIGGER);
  await triggers[index].click();
  await page.waitForSelector(".leader-menu [role=menuitem]", { timeout: 10000 });
  await sleep(150);
  return state(page);
}

async function verifyPhase() {
  const profiles = await loadProfiles();
  const browser = await launch();
  const first = profiles[0];
  const shots = join(OUT, "shots");
  mkdirSync(shots, { recursive: true });
  const shot = (page, name) => page.screenshot({ path: join(shots, `${name}.png`) }).catch(() => {});

  // ---- cards: the button, open, outside click, Escape, keyboard ----
  for (const locale of ["bn", "en"]) {
    const w = WORDS[locale];
    const { page, context, problems } = await openPage(browser, { viewport: "desktop", locale, grant: true });
    const url = `${SITE}${locale === "en" ? "/en" : ""}/leadership`;
    await go(page, url);
    await page.waitForSelector(TRIGGER, { visible: true, timeout: 30000 });
    const tag = `${locale} index`;

    const buttons = await page.$$eval(TRIGGER, (els) => els.map((el) => { el.scrollIntoView({ block: "center" }); const b = el.getBoundingClientRect(); return { w: b.width, h: b.height, label: el.getAttribute("aria-label"), haspopup: el.getAttribute("aria-haspopup"), expanded: el.getAttribute("aria-expanded"), text: el.textContent.trim(), dots: el.querySelectorAll("svg circle").length, dotsX: [...new Set([...el.querySelectorAll("svg circle")].map((c) => c.getAttribute("cx")))].length, svgHidden: el.querySelector("svg")?.getAttribute("aria-hidden"), pressable: (() => { const cx = b.x + b.width / 2, cy = b.y + b.height / 2; return [[-21, 0], [21, 0], [0, -21], [0, 21]].every(([dx, dy]) => document.elementFromPoint(cx + dx, cy + dy) === el); })() }; }));
    check("V1", `${tag}: every card has the three-dot button (${buttons.length} of ${profiles.length})`, buttons.length === profiles.length);
    check("V2", `${tag}: the button is a 32 px circle (44 px pressable), an SVG of three dots one above the other, no text or emoji, named after the person, a menu button`, buttons.every((b, i) => Math.abs(b.w - 32) < 0.6 && Math.abs(b.h - 32) < 0.6 && b.dots === 3 && b.dotsX === 1 && b.svgHidden === "true" && b.text === "" && b.haspopup === "menu" && b.expanded === "false" && b.pressable && b.label === w.actions(namesOf(profiles[i], locale).name)), JSON.stringify(buttons[0]));

    const before = page.url();
    const rectsBefore = await cardRects(page);
    let s = await open(page, 0);
    await shot(page, `menu-open-${locale}-desktop-index`);
    check("V3", `${tag}: clicking the button opens a menu (two entries: "${w.share}", "${w.copy}"), inside the viewport, aria-expanded true, aria-controls points at it, the first entry has focus, the page did not navigate`, s.open && s.expanded && s.items.length === 2 && s.items[0].text === w.share && s.items[1].text === w.copy && inside(s, 8) && s.menuId !== null && s.menuId === s.controls && s.activeIndex === 0 && page.url() === before, JSON.stringify(s).slice(0, 300));
    check("V4", `${tag}: opening the menu moves nothing on the page (every card keeps its box)`, JSON.stringify(await cardRects(page)) === JSON.stringify(rectsBefore));
    await page.mouse.click(4, 450);
    await sleep(120);
    s = await state(page);
    check("V5", `${tag}: a click outside closes it (nothing left in the page), aria-expanded false, no navigation`, !s.open && !s.expanded && page.url() === before);
    await open(page, 0);
    await page.keyboard.press("Escape");
    await sleep(120);
    s = await state(page);
    check("V6", `${tag}: Escape closes it and focus is back on the button`, !s.open && s.activeIsTrigger);

    // keyboard model
    await page.focus(TRIGGER);
    await page.keyboard.press("Enter");
    await page.waitForSelector(".leader-menu [role=menuitem]");
    await sleep(120);
    const seq = [];
    seq.push((await state(page)).activeIndex);
    for (const key of ["ArrowDown", "ArrowDown", "ArrowUp", "Home", "End"]) { await page.keyboard.press(key); await sleep(40); seq.push((await state(page)).activeIndex); }
    check("V7", `${tag}: keyboard: Enter opens on the first entry; Down, Down wraps, Up wraps, Home, End → ${JSON.stringify(seq)}`, JSON.stringify(seq) === JSON.stringify([0, 1, 0, 1, 0, 1]));
    await page.keyboard.press("Tab");
    await sleep(150);
    s = await state(page);
    check("V8", `${tag}: Tab closes the menu and moves on to the next control (focus is not lost on the page body)`, !s.open && s.activeTag !== "BODY" && !(await page.evaluate(() => document.activeElement?.closest(".leader-menu") !== null)), `${s.activeTag}`);
    await page.focus(TRIGGER);
    await page.keyboard.press("ArrowDown");
    await page.waitForSelector(".leader-menu [role=menuitem]");
    await sleep(120);
    const down = (await state(page)).activeIndex;
    await page.keyboard.press("Escape");
    await sleep(100);
    await page.keyboard.press("ArrowUp");
    await page.waitForSelector(".leader-menu [role=menuitem]");
    await sleep(120);
    const up = (await state(page)).activeIndex;
    await page.keyboard.press("Escape");
    check("V9", `${tag}: ArrowDown on the button opens on the first entry, ArrowUp on the last (${down}, ${up})`, down === 0 && up === 1);

    // the card's own link still works and the button is not inside it
    const structure = await page.evaluate(() => { const t = document.querySelector(".leader-actions-trigger"); return { insideLink: Boolean(t.closest("a")), insideHeading: Boolean(t.closest("h3")), insideCard: Boolean(t.closest(".leader-card")) }; });
    check("V10", `${tag}: the button is inside the card but never inside the name link or the heading`, structure.insideCard && !structure.insideLink && !structure.insideHeading, JSON.stringify(structure));
    await Promise.all([page.waitForNavigation({ waitUntil: "networkidle2", timeout: 60000 }), page.click(".leader-card-link")]);
    check("V11", `${tag}: the name link still opens the profile (${new URL(page.url()).pathname})`, new RegExp(`${locale === "en" ? "/en" : ""}/leadership/[a-z0-9-]+$`).test(new URL(page.url()).pathname));

    // scrolling with the menu open keeps it attached to the button
    await go(page, url);
    await page.waitForSelector(TRIGGER, { visible: true });
    await open(page, 0);
    const gap0 = await page.evaluate(() => { const t = document.querySelector('.leader-actions-trigger[aria-expanded="true"]').getBoundingClientRect(); const m = document.querySelector(".leader-menu").getBoundingClientRect(); return Math.round(m.top - t.bottom); });
    await page.evaluate(() => window.scrollBy(0, 80));
    await sleep(250);
    const gap1 = await page.evaluate(() => { const t = document.querySelector('.leader-actions-trigger[aria-expanded="true"]'); const m = document.querySelector(".leader-menu"); if (!t || !m) return null; return Math.round(m.getBoundingClientRect().top - t.getBoundingClientRect().bottom); });
    check("V12", `${tag}: scrolling the page with the menu open keeps it attached to its button (gap ${gap0} → ${gap1} px)`, gap1 !== null && Math.abs(gap0 - gap1) <= 1);
    await page.keyboard.press("Escape");
    check("V13", `${tag}: no console error, page error or failed request`, problems.length === 0, problems.join(" | "));
    await context.close();
  }

  // `--section cards` stops here: the first section alone, for a quick look at one thing.
  if (arg("section") === "cards") { await browser.close(); return; }

  // ---- share: native (recorded), cancelled, refused, unavailable ----
  for (const locale of ["bn", "en"]) {
    const w = WORDS[locale];
    const expected = namesOf(first, locale);
    const url = `${SITE}${locale === "en" ? "/en" : ""}/leadership`;
    {
      const { page, context } = await stubbed(browser, { viewport: "desktop", locale }, "ok", url);
      await open(page, 0);
      await page.click(".leader-menu [role=menuitem]:first-child, .leader-menu [role=menuitem]");
      await sleep(300);
      const shared = await page.evaluate(() => window.__shared ?? null);
      const s = await state(page);
      check("V20", `${locale}: Share calls the browser's share sheet with exactly the person's name, designation and canonical address`, shared && JSON.stringify(shared) === JSON.stringify({ title: expected.name, text: expected.designation, url: expected.url }), JSON.stringify(shared));
      check("V21", `${locale}: the shared data holds nothing else (no e-mail, no phone, no other key)`, shared && Object.keys(shared).every((k) => ["title", "text", "url"].includes(k)) && looksLikePrivate(JSON.stringify(shared), first).length === 0 && !/@/.test(JSON.stringify(shared)));
      check("V22", `${locale}: after a successful share the menu closes and focus returns to the button`, !s.open && s.activeIsTrigger);
      await context.close();
    }
    {
      const { page, context } = await stubbed(browser, { viewport: "desktop", locale }, "abort", url);
      await open(page, 0);
      await page.click(".leader-menu [role=menuitem]");
      await sleep(300);
      const s = await state(page);
      check("V23", `${locale}: closing the share sheet (AbortError) is not an error: the menu simply closes, no fallback list, no message`, !s.open && s.live === "" && (await page.evaluate(() => window.__shareCalls)) === 1);
      await context.close();
    }
    for (const mode of ["denied", "none"]) {
      const { page, context } = await stubbed(browser, { viewport: "desktop", locale }, mode, url);
      await open(page, 0);
      await page.click(".leader-menu [role=menuitem]");
      await sleep(350);
      const s = await state(page);
      const links = s.items.filter((i) => i.tag === "A");
      const want = expectedShareLinks(expected);
      const hrefs = Object.fromEntries(links.map((l, i) => [["whatsapp", "facebook", "linkedin", "email"][i], l.href]));
      check("V24", `${locale}: ${mode === "none" ? "without the Web Share API" : "when the share sheet is refused"}, Share opens four sharing links (nothing fails silently)`, s.open && links.length === 4 && JSON.stringify(hrefs) === JSON.stringify(want), JSON.stringify(hrefs).slice(0, 300));
      check("V25", `${locale}: ${mode}: each fallback link opens in a new tab with rel="noopener noreferrer", is https or mailto, carries the canonical address only (percent-encoded) and no private data`, links.every((l) => (l.href.startsWith("mailto:") || l.target === "_blank") && /noopener/.test(l.rel ?? "") && /noreferrer/.test(l.rel ?? "") && /^(https:\/\/|mailto:)/.test(l.href) && !/javascript:|data:/i.test(l.href)) && looksLikePrivate(links.map((l) => l.href).join(" ") + decodeURIComponent(links.map((l) => l.href).join(" ")), first).length === 0, JSON.stringify(links.map((l) => [l.target, l.rel])));
      await shot(page, `share-fallback-${mode}-${locale}`);
      await page.keyboard.press("ArrowDown");
      await sleep(60);
      const keyboard = (await state(page)).activeIndex;
      await page.keyboard.press("Escape");
      await sleep(100);
      check("V26", `${locale}: ${mode}: the fallback links are reachable with the arrow keys, and Escape closes the menu (${keyboard})`, keyboard >= 1 && !(await state(page)).open);
      await context.close();
    }

    // ---- copy: exact clipboard text, feedback, no layout shift ----
    {
      const { page, context } = await stubbed(browser, { viewport: "desktop", locale, grant: true }, null, url);
      await page.bringToFront();
      const rects = await cardRects(page);
      await open(page, 0);
      await page.click(".leader-menu [role=menuitem]:nth-of-type(2), .leader-menu [role=menu] > button:last-of-type");
      await sleep(250);
      const clip = await page.evaluate(() => navigator.clipboard.readText().catch((e) => `ERROR ${e.name}`));
      const s = await state(page);
      check("V30", `${locale}: Copy puts exactly the canonical address on the clipboard (${clip})`, clip === expected.url, clip);
      check("V31", `${locale}: feedback is "${w.copied}" in the menu and in the polite live region, and no card moved`, s.items.some((i) => i.text === w.copied) && s.live === w.copied && JSON.stringify(await cardRects(page)) === JSON.stringify(rects), JSON.stringify({ items: s.items.map((i) => i.text), live: s.live }));
      await shot(page, `copy-feedback-${locale}`);
      await sleep(1700);
      const closed = await state(page);
      check("V32", `${locale}: the menu closes by itself after the feedback and focus returns to the button`, !closed.open && closed.activeIsTrigger);
      await context.close();
    }
    {
      const { page, context } = await stubbed(browser, { viewport: "desktop", locale }, "legacyCopy", url);
      await open(page, 0);
      await page.click(".leader-menu [role=menu] > button:last-of-type");
      await sleep(300);
      const legacy = await page.evaluate(() => window.__legacy ?? null);
      const s = await state(page);
      check("V33", `${locale}: without the Clipboard API the fallback copies the same canonical address and says "${w.copied}"`, Array.isArray(legacy) && legacy.length === 1 && legacy[0] === expected.url && s.items.some((i) => i.text === w.copied), JSON.stringify(legacy));
      await context.close();
    }
    {
      const { page, context } = await stubbed(browser, { viewport: "desktop", locale }, "noCopy", url);
      await open(page, 0);
      await page.click(".leader-menu [role=menu] > button:last-of-type");
      await sleep(350);
      const s = await state(page);
      check("V34", `${locale}: when copying is impossible the address is shown in a selected box with an honest message (never a false "copied")`, s.manual !== null && s.manual.value === expected.url && s.manual.note === w.copyFailed && s.manual.selected[0] === 0 && s.manual.selected[1] === expected.url.length && !s.items.some((i) => i.text === w.copied) && s.live === w.copyFailed, JSON.stringify(s.manual));
      await shot(page, `copy-manual-${locale}`);
      await page.keyboard.press("Escape");
      await sleep(120);
      check("V35", `${locale}: Escape closes the manual-copy box too, focus back on the button`, !(await state(page)).open && (await state(page)).activeIsTrigger);
      await context.close();
    }
  }

  // ---- the profile pages: every active profile, both languages ----
  for (const p of profiles) {
    for (const locale of ["bn", "en"]) {
      const expected = namesOf(p, locale);
      const { page, context, problems } = await stubbed(browser, { viewport: "desktop", locale, grant: true }, "ok", pageUrl(p.slug, locale));
      const tag = `${p.slug} ${locale}`;
      const box = await page.evaluate(() => { const t = document.querySelector(".leader-actions-trigger").getBoundingClientRect(); const f = document.querySelector(".leader-photo-frame").getBoundingClientRect(); return { dx: f.right - t.right, dy: t.top - f.top, inFrame: t.left >= f.left && t.right <= f.right && t.top >= f.top && t.bottom <= f.bottom }; });
      check("V40", `${tag}: the profile page has the button over the top-right corner of the portrait (10 px in)`, box.inFrame && Math.abs(box.dx - 10) <= 1.5 && Math.abs(box.dy - 10) <= 1.5, JSON.stringify(box));
      const heights = await page.evaluate(() => [document.documentElement.scrollHeight, document.querySelector(".leader-photo-frame").getBoundingClientRect().height]);
      const s = await open(page, 0);
      const heightsOpen = await page.evaluate(() => [document.documentElement.scrollHeight, document.querySelector(".leader-photo-frame").getBoundingClientRect().height]);
      check("V41", `${tag}: opening the menu changes neither the page height nor the portrait`, JSON.stringify(heights) === JSON.stringify(heightsOpen) && s.open && inside(s, 8));
      await page.click(".leader-menu [role=menuitem]");
      await sleep(300);
      const shared = await page.evaluate(() => window.__shared ?? null);
      check("V42", `${tag}: Share sends this profile's canonical address (${expected.url.replace("https://provatferi.org", "")}), name and designation`, shared && JSON.stringify(shared) === JSON.stringify({ title: expected.name, text: expected.designation, url: expected.url }), JSON.stringify(shared));
      await open(page, 0);
      await page.click(".leader-menu [role=menu] > button:last-of-type");
      await sleep(250);
      const clip = await page.evaluate(() => navigator.clipboard.readText().catch((e) => `ERROR ${e.name}`));
      check("V43", `${tag}: Copy puts exactly the canonical address on the clipboard, and it is the one the page declares as canonical`, clip === expected.url && clip === (await page.$eval('link[rel="canonical"]', (l) => l.href)), clip);
      check("V44", `${tag}: no console error, page error or failed request`, problems.length === 0, problems.join(" | "));
      if (p === first) await shot(page, `profile-${locale}-desktop`);
      await context.close();
    }
  }

  // ---- the homepage section (compact cards) ----
  for (const locale of ["bn", "en"]) {
    const { page, context } = await stubbed(browser, { viewport: "desktop", locale }, null, locale === "en" ? `${SITE}/en` : `${SITE}/`);
    const count = await page.$$eval(".home-leadership .leader-actions-trigger", (els) => els.length);
    const expectedCount = Math.min(4, profiles.filter((p) => p.featured).length);
    check("V50", `${locale} homepage: every card of the Leadership section has the button (${count} of ${expectedCount})`, count === expectedCount);
    const s = await open(page, 0);
    check("V51", `${locale} homepage: the menu opens inside the viewport on a compact card`, s.open && inside(s, 8) && s.items.length === 2);
    await shot(page, `menu-open-${locale}-desktop-home`);
    await page.keyboard.press("Escape");
    await context.close();
  }

  // ---- a 390 px phone with touch ----
  for (const locale of ["bn", "en"]) {
    const expected = namesOf(first, locale);
    const { page, context, problems } = await stubbed(browser, { viewport: "mobile", locale, grant: true }, null, `${SITE}${locale === "en" ? "/en" : ""}/leadership`);
    const startUrl = page.url();
    const triggers = await page.$$(TRIGGER);
    // Taps are made on the element's centre after it was brought on screen (a box is only measurable for something in the document),
    // and an "outside" tap lands on text that is not a link, so it cannot navigate away.
    const tapAt = async (index, { bring = true } = {}) => {
      const handle = (await page.$$(TRIGGER))[index];
      if (!handle) throw new Error(`button ${index} is not in the page (url ${page.url()})`);
      if (bring) await handle.evaluate((el) => el.scrollIntoView({ block: "center" }));
      await sleep(120);
      const b = await handle.boundingBox();
      if (!b) throw new Error(`button ${index} has no box (url ${page.url()})`);
      await page.touchscreen.tap(b.x + b.width / 2, b.y + b.height / 2);
      await sleep(250);
    };
    const tapOutside = async () => {
      const point = await page.evaluate(() => {
        for (let y = 6; y < innerHeight - 6; y += 14) {
          for (let x = 6; x < innerWidth - 6; x += 14) {
            const el = document.elementFromPoint(x, y);
            if (el && !el.closest("a, button, input, textarea, select, summary, label, [role=menu], [role=menuitem], [tabindex]")) return [x, y];
          }
        }
        return null;
      });
      if (!point) throw new Error("no quiet point to tap outside the menu");
      await page.touchscreen.tap(point[0], point[1]);
      await sleep(250);
    };
    const geometry = await page.$$eval(TRIGGER, (els) => els.map((el) => { el.scrollIntoView({ block: "center" }); const b = el.getBoundingClientRect(); const c = el.closest(".leader-photo-frame").getBoundingClientRect(); return { w: b.width, h: b.height, offRight: c.right - b.right, offTop: b.top - c.top, pressable: [[-21, 0], [21, 0], [0, -21], [0, 21]].every(([dx, dy]) => document.elementFromPoint(b.x + b.width / 2 + dx, b.y + b.height / 2 + dy) === el) }; }));
    check("V60", `${locale} 390 px: every button is 32 px with a 44 px pressable area, 8 px from the photo's top-right corner`, geometry.length === profiles.length && geometry.every((g) => Math.abs(g.w - 32) < 0.6 && g.pressable && Math.abs(g.offRight - 8) <= 1.5 && Math.abs(g.offTop - 8) <= 1.5), JSON.stringify(geometry[0]));
    await tapAt(0);
    let s = await state(page);
    check("V61", `${locale} 390 px: a tap opens the menu, fully inside the 390 px screen`, s.open && inside(s, 8), JSON.stringify(s.rect));
    await shot(page, `menu-open-${locale}-mobile`);
    await tapOutside();
    check("V62", `${locale} 390 px: a tap outside closes it`, !(await state(page)).open);
    // the last card, scrolled so that its button is near the bottom edge: the menu must open above it, still inside
    const lastIndex = triggers.length - 1;
    await triggers[lastIndex].evaluate((el) => { el.scrollIntoView({ block: "center" }); const b = el.getBoundingClientRect(); window.scrollBy(0, b.bottom - (window.innerHeight - 20)); });
    await sleep(200);
    await tapAt(lastIndex, { bring: false });
    s = await state(page);
    check("V63", `${locale} 390 px: a button near the bottom edge opens its menu above it, still inside the screen`, s.open && inside(s, 8), JSON.stringify(s.rect));
    await tapOutside();
    // share fallback list is the tallest the menu gets: it must fit too
    const { page: p2, context: c2 } = await stubbed(browser, { viewport: "mobile", locale }, "none", `${SITE}${locale === "en" ? "/en" : ""}/leadership`);
    const t2 = await p2.$$(TRIGGER);
    await t2[0].evaluate((el) => el.scrollIntoView({ block: "center" }));
    const b2 = await t2[0].boundingBox();
    await p2.touchscreen.tap(b2.x + b2.width / 2, b2.y + b2.height / 2);
    await sleep(250);
    await p2.click(".leader-menu [role=menuitem]");
    await sleep(350);
    s = await state(p2);
    check("V64", `${locale} 390 px: the tallest menu (share links open) still fits inside the screen`, s.open && s.items.length === 6 && inside(s, 8), JSON.stringify(s.rect));
    await shot(p2, `share-fallback-${locale}-mobile`);
    await c2.close();
    // copy by touch
    await page.bringToFront();
    await tapAt(0);
    const items = await page.$$(".leader-menu [role=menuitem]");
    const copyBox = await items[1].boundingBox();
    await page.touchscreen.tap(copyBox.x + copyBox.width / 2, copyBox.y + copyBox.height / 2);
    await sleep(300);
    const clip = await page.evaluate(() => navigator.clipboard.readText().catch((e) => `ERROR ${e.name}`));
    check("V65", `${locale} 390 px: copying by touch puts the canonical address on the clipboard`, clip === expected.url, clip);
    check("V66", `${locale} 390 px: no console error, page error or failed request, and no tap navigated away`, problems.length === 0 && page.url() === startUrl, `${problems.join(" | ")} ${page.url() === startUrl ? "" : `now at ${page.url()}`}`);
    await context.close();
    const prof = await stubbed(browser, { viewport: "mobile", locale }, null, pageUrl(first.slug, locale));
    const pb = await prof.page.evaluate(() => { const t = document.querySelector(".leader-actions-trigger").getBoundingClientRect(); const f = document.querySelector(".leader-photo-frame").getBoundingClientRect(); return t.left >= f.left && t.right <= f.right && t.top >= f.top && t.bottom <= f.bottom; });
    const ps = await open(prof.page, 0);
    check("V67", `${locale} 390 px: on the profile page the button sits on the portrait and its menu fits the screen`, pb && ps.open && inside(ps, 8));
    await shot(prof.page, `profile-${locale}-mobile-open`);
    await prof.context.close();
  }

  // ---- the button lies over no text and no other control, at any width: the frozen layout needs no gutter and re-wraps nothing ----
  for (const [locale, viewport] of [["bn", "desktop"], ["bn", "tablet"], ["bn", "mobile"], ["en", "desktop"], ["en", "tablet"], ["en", "mobile"]]) {
    for (const [name, url] of [["index", `${SITE}${locale === "en" ? "/en" : ""}/leadership`], ["home", locale === "en" ? `${SITE}/en` : `${SITE}/`]]) {
      const { page, context } = await stubbed(browser, { viewport, locale }, null, url);
      const rows = await page.evaluate(() => {
        const box = (r) => ({ x: r.x, y: r.y, r: r.right, b: r.bottom });
        const meet = (a, b) => a.x < b.r - 0.5 && a.r > b.x + 0.5 && a.y < b.b - 0.5 && a.b > b.y + 0.5;
        return [...document.querySelectorAll(".leader-card")].map((card) => {
          const trigger = card.querySelector(".leader-actions-trigger");
          if (!trigger) return { missing: true };
          const t = trigger.getBoundingClientRect();
          const area = { x: t.x - 6, y: t.y - 6, r: t.right + 6, b: t.bottom + 6 };
          const f = card.querySelector(".leader-photo-frame").getBoundingClientRect();
          const insideFrame = area.x >= f.x - 0.5 && area.y >= f.y - 0.5 && area.r <= f.right + 0.5 && area.b <= f.bottom + 0.5;
          const others = [];
          const walker = document.createTreeWalker(card.querySelector(".leader-card-body"), NodeFilter.SHOW_TEXT);
          for (let n = walker.nextNode(); n; n = walker.nextNode()) {
            if (!n.textContent.trim()) continue;
            const range = document.createRange();
            range.selectNodeContents(n);
            for (const r of range.getClientRects()) others.push(box(r));
          }
          for (const el of card.querySelectorAll(".leader-card-body a, .leader-card-body button")) others.push(box(el.getBoundingClientRect()));
          return { insideFrame, overlapsText: others.some((o) => meet(area, o)) };
        });
      });
      check("V90", `${locale} ${name} ${viewport}: on every card (${rows.length}) the button, with its 44 px pressable area, is inside the photo and lies over no text or control`, rows.length > 0 && rows.every((r) => r.insideFrame && !r.overlapsText), JSON.stringify(rows.filter((r) => r.missing || !r.insideFrame || r.overlapsText)));
      await context.close();
    }
  }

  // ---- light and dark: contrast of the new elements, visible focus ----
  for (const theme of ["light", "dark"]) {
    const { page, context } = await stubbed(browser, { viewport: "desktop", locale: "bn", theme }, null, `${SITE}/leadership`);
    const probe = () => page.evaluate(() => {
      const rgb = (css) => { const m = css.match(/rgba?\(([^)]+)\)|color\(srgb ([^)]+)\)/); if (!m) return null; const parts = (m[1] ?? m[2]).split(/[ ,/]+/).filter(Boolean).map(Number); if (m[2]) return [parts[0] * 255, parts[1] * 255, parts[2] * 255, parts[3] ?? 1]; return [parts[0], parts[1], parts[2], parts[3] ?? 1]; };
      const lum = ([r, g, b]) => { const f = (c) => { const s = c / 255; return s <= 0.03928 ? s / 12.92 : ((s + 0.055) / 1.055) ** 2.4; }; return 0.2126 * f(r) + 0.7152 * f(g) + 0.0722 * f(b); };
      const ratio = (a, b) => { const [x, y] = [lum(a), lum(b)].sort((m, n) => n - m); return Math.round(((x + 0.05) / (y + 0.05)) * 100) / 100; };
      const over = (top, under) => { const a = top[3]; return [0, 1, 2].map((i) => top[i] * a + under[i] * (1 - a)); };
      const card = rgb(getComputedStyle(document.querySelector(".leader-card")).backgroundColor);
      const trigger = document.querySelector(".leader-actions-trigger");
      const ts = getComputedStyle(trigger);
      const chip = over(rgb(ts.backgroundColor), card);
      const out = { theme: document.documentElement.getAttribute("data-theme"), icon: ratio(rgb(ts.color), chip), border: ratio(rgb(ts.borderTopColor), chip) };
      const menu = document.querySelector(".leader-menu");
      if (menu) {
        const bg = rgb(getComputedStyle(menu).backgroundColor);
        const item = menu.querySelector('[role="menuitem"]');
        out.menuText = ratio(rgb(getComputedStyle(item).color), bg);
        out.menuIcon = ratio(rgb(getComputedStyle(item.querySelector("svg")).color), bg);
        out.menuBorder = ratio(rgb(getComputedStyle(menu).borderTopColor), card);
      }
      return out;
    });
    const closed = await probe();
    await open(page, 0);
    const openState = await probe();
    await shot(page, `menu-open-bn-desktop-${theme}`);
    await page.keyboard.press("Escape");
    await sleep(100);
    await page.keyboard.press("Tab");
    // keyboard focus onto a button: the outline must be there
    await page.focus(TRIGGER);
    await page.keyboard.press("Shift");
    const focusRing = await page.evaluate(() => { const cs = getComputedStyle(document.activeElement); return { style: cs.outlineStyle, width: cs.outlineWidth, color: cs.outlineColor, matches: document.activeElement.matches(":focus-visible") }; });
    check("V70", `${theme}: the button's icon and outline have at least 3:1 contrast against the card (icon ${closed.icon}, border ${closed.border})`, closed.theme === theme && closed.icon >= 3 && closed.border >= 1.2, JSON.stringify(closed));
    check("V71", `${theme}: menu text has at least 4.5:1 contrast (${openState.menuText}), its icons at least 3:1 (${openState.menuIcon}), the menu is set off from the card`, openState.menuText >= 4.5 && openState.menuIcon >= 3 && openState.menuBorder >= 1.2, JSON.stringify(openState));
    check("V72", `${theme}: a keyboard-focused button shows a 2 px outline`, focusRing.style !== "none" && parseFloat(focusRing.width) >= 2 && focusRing.matches, JSON.stringify(focusRing));
    await context.close();
  }

  // ---- accessible names as a screen reader gets them ----
  {
    const { page, context } = await stubbed(browser, { viewport: "desktop", locale: "en" }, null, `${SITE}/en/leadership`);
    const handle = (await page.$$(TRIGGER))[0];
    let tree = null;
    try { tree = await page.accessibility.snapshot({ root: handle, interestingOnly: false }); } catch { /* the snapshot API is not available in every Puppeteer build */ }
    const wantName = WORDS.en.actions(namesOf(first, "en").name);
    if (tree) check("V80", `the button's accessible name is "${wantName}" and its role is button with a menu popup`, tree.name === wantName && tree.role === "button" && /menu/.test(String(tree.haspopup ?? tree.hasPopup ?? "")), JSON.stringify({ name: tree.name, role: tree.role, haspopup: tree.haspopup }));
    else note("accessibility snapshot unavailable: names were checked through the aria-label attributes instead (V2)");
    await handle.click();
    await page.waitForSelector(".leader-menu [role=menuitem]");
    const names = await page.evaluate(() => ({ menu: document.querySelector('.leader-menu [role="menu"]').getAttribute("aria-label"), items: [...document.querySelectorAll('.leader-menu [role="menuitem"]')].map((el) => el.textContent.trim()), live: document.querySelectorAll('[role="status"][aria-live="polite"]').length > 0 }));
    check("V81", "the menu has an accessible name, each entry has visible text, and a polite live region exists for the feedback", names.menu === wantName && names.items.length === 2 && names.items.every((t) => t.length > 3) && names.live, JSON.stringify(names));
    await context.close();
  }
  await browser.close();
}

// ============================================================================================================ run
if (PHASE === "verify") {
  await verifyPhase();
  finish();
}
if (PHASE === "seo") {
  await seoPhase();
  finish();
}
if (PHASE === "baseline" || PHASE === "compare") {
  await designPhase(PHASE);
  finish();
}
