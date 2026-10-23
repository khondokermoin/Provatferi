// Does an upgrade (a framework or a dependency) change anything a visitor, a crawler or a cache can see? READ-ONLY: it only GETs
// public pages; it logs in nowhere, submits nothing and writes nothing on the server. Written for the 2026-10-23 security upgrade of
// Next.js (16.3.6 -> 16.4.2, with sharp and source-map-js), for BOTH Next applications of this repository.
//
//   node scripts/site-regression-qa.mjs --phase pages-baseline  --suite institutional|sahittopata --site <origin> --out <dir>
//   node scripts/site-regression-qa.mjs --phase pages-compare   --suite ...  --site <origin> --out <dir> --baseline <baseline dir>
//   node scripts/site-regression-qa.mjs --phase headers-baseline --suite ... --site <origin> --out <dir>
//   node scripts/site-regression-qa.mjs --phase headers-compare  --suite ... --site <origin> --out <dir> --baseline <baseline dir>
//
//   pages-*    real Chrome; for every page of the suite, at a desktop and a phone size (and in the dark theme on the key pages):
//              status, final address, <title>, canonical, description, robots, hreflang, Open Graph / Twitter tags, JSON-LD, the
//              visible text, the internal links, the rectangle of every landmark / heading / link / button / field / image, the
//              console errors and failed requests, whether every image loaded, and a full-page screenshot. `compare` demands that
//              everything equals the baseline and that every pixel does, apart from JPEG decoding noise (2/255 outside photographs,
//              32/255 inside them: a photograph is not bit-identical from one load to the next).
//   headers-*  no browser; the status and the cache / security relevant response headers of a fixed list of addresses, including the
//              image optimizer's (/_next/image) answers for good and for refused requests.
//
// The address list is discovered once, from the baseline site, and stored in the baseline directory: `compare` reuses it, so the
// same pages are compared. `--local` marks a site that is not production (nothing changes except the origin that is printed).
// Exit code 1 when a check fails.
import { createHash } from "node:crypto";
import { createRequire } from "node:module";
import { existsSync, mkdirSync, readFileSync, writeFileSync } from "node:fs";
import { join } from "node:path";

const arg = (name, fallback) => {
  const i = process.argv.indexOf(`--${name}`);
  return i > -1 ? process.argv[i + 1] : fallback;
};
const PHASE = arg("phase");
const SUITE = arg("suite", "institutional");
const SITE = (arg("site", "") ?? "").replace(/\/+$/, "");
const OUT = arg("out");
const BASELINE_DIR = arg("baseline");
const LABEL = arg("label", PHASE);
if (!PHASE || !SITE || !OUT) {
  console.error("usage: --phase pages-baseline|pages-compare|headers-baseline|headers-compare --suite institutional|sahittopata --site <origin> --out <dir> [--baseline <dir>] [--filter <text>] [--attempts 2]");
  process.exit(2);
}
mkdirSync(OUT, { recursive: true });

const require = createRequire(import.meta.url);
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const CHROME = [process.env.CHROME_PATH, "C:/Program Files/Google/Chrome/Application/chrome.exe", "C:/Program Files (x86)/Google/Chrome/Application/chrome.exe", "/usr/bin/google-chrome", "/usr/bin/chromium"].filter(Boolean).find((p) => existsSync(p));
const VIEWPORTS = {
  desktop: { width: 1366, height: 900, deviceScaleFactor: 1 },
  mobile: { width: 390, height: 844, deviceScaleFactor: 1, isMobile: true, hasTouch: true },
};
const UA = "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36";

// ---------------------------------------------------------------------------------------------------------- reporting
const checks = [];
const check = (id, description, ok, detail = "") => {
  const row = { id, description, ok: Boolean(ok), detail: String(detail).slice(0, 600) };
  checks.push(row);
  console.log(`${row.ok ? "PASS" : "FAIL"}  ${id.padEnd(4)} ${description}${row.ok || !row.detail ? "" : `  -> ${row.detail.slice(0, 360)}`}`);
};
const note = (text) => console.log(`       ${text}`);
function finish() {
  const failed = checks.filter((c) => !c.ok);
  writeFileSync(join(OUT, `report-${LABEL}.json`), JSON.stringify({ phase: PHASE, suite: SUITE, site: SITE, passed: checks.length - failed.length, failed: failed.length, checks }, null, 2));
  console.log(`\n${checks.length - failed.length}/${checks.length} checks passed${failed.length ? `; FAILED: ${[...new Set(failed.map((c) => c.id))].join(", ")}` : ""}`);
  process.exit(failed.length ? 1 : 0);
}
const sha = (text) => createHash("sha256").update(text).digest("hex").slice(0, 16);

// ---------------------------------------------------------------------------------------------------------- the address list
const get = async (url) => {
  const res = await fetch(url, { headers: { "user-agent": UA, "accept-language": "en" } });
  return { status: res.status, html: await res.text() };
};
const firstHref = (html, re) => (html.match(re) ?? [])[1] ?? null;

/** [path, theme list] per page; `en` is false for a page that has no English mirror. Detail pages are found on the list pages. */
async function buildCases() {
  const cases = [];
  const add = (path, { locales = ["bn", "en"], themes = ["light"], key = path } = {}) => {
    for (const locale of locales) {
      const url = locale === "en" ? (path === "/" ? "/en" : `/en${path}`) : path;
      for (const viewport of ["desktop", "mobile"]) {
        for (const theme of themes) cases.push({ key: `${locale}${key}|${viewport}|${theme}`, path: url, viewport, theme });
      }
    }
  };
  if (SUITE === "institutional") {
    const both = ["light", "dark"];
    add("/", { themes: both, key: "/home" });
    add("/about");
    add("/contact", { themes: both });
    add("/notices", { themes: both });
    add("/activities");
    add("/events");
    add("/recruitment");
    add("/membership");
    add("/members");
    add("/committee");
    add("/organization");
    add("/member/login", { locales: ["bn"] });
    const found = {};
    for (const [name, list, re] of [
      ["notice", "/notices", /href="\/notices\/([a-z0-9][a-z0-9-]*)"/],
      ["activity", "/activities", /href="\/activities\/([a-z0-9][a-z0-9-]*)"/],
      ["posting", "/recruitment", /href="\/recruitment\/([a-z0-9][a-z0-9-]*)"/],
      ["member", "/members", /href="\/members\/([a-z0-9][a-z0-9-]*)"/],
      ["committee", "/committee", /href="\/committee\/([a-z0-9][a-z0-9-]*)"/],
    ]) {
      const { html } = await get(`${SITE}${list}`);
      found[name] = firstHref(html, re);
    }
    if (found.notice) add(`/notices/${found.notice}`, { themes: both, key: "/notice-detail" });
    if (found.activity) add(`/activities/${found.activity}`, { key: "/activity-detail" });
    if (found.member) add(`/members/${found.member}`, { key: "/member-detail" });
    if (found.committee) add(`/committee/${found.committee}`, { key: "/committee-detail" });
    if (found.posting) {
      add(`/recruitment/${found.posting}`, { themes: both, key: "/posting" });
      add(`/recruitment/${found.posting}/apply`, { themes: both, key: "/apply" });
      // The confirmation page opened by someone who did not just apply: it must say so plainly and show no group invite.
      add(`/recruitment/${found.posting}/apply/success`, { key: "/apply-success-noproof" });
    }
    note(`discovered: ${JSON.stringify(found)}`);
  } else if (SUITE === "sahittopata") {
    add("/", { locales: ["bn"], themes: ["light", "dark"], key: "/home" });
    add("/search", { locales: ["bn"] });
    const home = (await get(`${SITE}/`)).html;
    const hrefs = [...home.matchAll(/href="(\/[^"#?]*)"/g)].map((m) => m[1]);
    const category = hrefs.find((h) => /^\/[a-z0-9%][a-z0-9%-]*$/i.test(h) && !["/search", "/article", "/author"].includes(h))?.slice(1) ?? null;
    const article = firstHref(home, /href="\/article\/([^"/?#]+)"/);
    if (category) add(`/${category}`, { locales: ["bn"], key: "/category" });
    if (article) {
      add(`/article/${article}`, { locales: ["bn"], themes: ["light", "dark"], key: "/article" });
      const page = (await get(`${SITE}/article/${article}`)).html;
      const author = firstHref(page, /href="\/author\/([^"/?#]+)"/);
      if (author) add(`/author/${author}`, { locales: ["bn"], key: "/author" });
    }
    note(`discovered: category=${category} article=${article}`);
  } else {
    throw new Error(`unknown suite ${SUITE}`);
  }
  return cases;
}

// ---------------------------------------------------------------------------------------------------------- the browser
async function launch() {
  if (!CHROME) throw new Error("No Chrome found; set CHROME_PATH");
  const puppeteer = require("puppeteer-core");
  return puppeteer.launch({
    executablePath: CHROME, headless: true, protocolTimeout: 60000,
    args: ["--no-sandbox", "--disable-dev-shm-usage", "--hide-scrollbars", "--disable-background-timer-throttling", "--disable-renderer-backgrounding", "--disable-backgrounding-occluded-windows"],
  });
}

async function openPage(browser, { viewport, theme }) {
  const context = await browser.createBrowserContext();
  const page = await context.newPage();
  await page.setViewport(VIEWPORTS[viewport]);
  await page.setUserAgent(UA);
  await page.emulateMediaFeatures([{ name: "prefers-color-scheme", value: theme }, { name: "prefers-reduced-motion", value: "reduce" }]);
  await page.evaluateOnNewDocument((t) => { try { localStorage.setItem("provatferi-theme", t); } catch { /* ignore */ } }, theme);
  const problems = [];
  const ignorable = (url) => /\/cdn-cgi\/|favicon|rum|googletagmanager|google-analytics/.test(url);
  page.on("console", (m) => { if (m.type() === "error") problems.push(`console: ${m.text().slice(0, 160)}`); });
  page.on("pageerror", (e) => problems.push(`pageerror: ${String(e).slice(0, 160)}`));
  page.on("requestfailed", (r) => { if (r.failure()?.errorText !== "net::ERR_ABORTED" && !ignorable(r.url())) problems.push(`requestfailed: ${r.url().replace(SITE, "").slice(0, 120)} ${r.failure()?.errorText ?? ""}`); });
  page.on("response", (res) => { if (res.status() >= 400 && !ignorable(res.url())) problems.push(`http ${res.status()}: ${res.url().replace(SITE, "").slice(0, 120)}`); });
  return { page, context, problems };
}

/** Everything is loaded and nothing moves: fonts, lazy images (scrolled through once and decoded), no animation, pointer parked. */
async function settle(page) {
  await page.addStyleTag({ content: "*,*::before,*::after{animation:none!important;transition:none!important;caret-color:transparent!important;scroll-behavior:auto!important}" }).catch(() => {});
  await page.evaluate(() => Promise.race([document.fonts.ready, new Promise((r) => setTimeout(r, 10000))]));
  await page.evaluate(async () => {
    const step = Math.max(300, Math.floor(window.innerHeight * 0.7));
    for (let y = 0; y < document.documentElement.scrollHeight; y += step) { window.scrollTo(0, y); await new Promise((r) => setTimeout(r, 60)); }
    window.scrollTo(0, 0);
  });
  await page.evaluate(() => Promise.all([...document.images].map(async (img) => {
    if (!img.complete) await new Promise((r) => { img.addEventListener("load", r, { once: true }); img.addEventListener("error", r, { once: true }); setTimeout(r, 8000); });
    try { await Promise.race([img.decode(), new Promise((r) => setTimeout(r, 8000))]); } catch { /* a broken image is reported by the checks */ }
  })));
  await page.evaluate(() => Promise.race([new Promise((r) => requestAnimationFrame(() => requestAnimationFrame(r))), new Promise((r) => setTimeout(r, 2000))]));
  await page.mouse.move(0, 0);
  await sleep(500);
}

/** What the page says and where it puts things, as plain data. */
const facts = (page) => page.evaluate(() => {
  const round = (n) => Math.round(n * 10) / 10;
  const norm = (s) => String(s ?? "").replace(/\s+/g, " ").trim();
  const metas = [...document.querySelectorAll("meta")]
    .map((m) => [m.getAttribute("name") ?? m.getAttribute("property"), m.getAttribute("content")])
    .filter(([k]) => k && /^(description|robots|og:|twitter:|article:|profile:|viewport|theme-color)/.test(k))
    .map(([k, v]) => `${k}=${v}`).sort();
  const links = [...document.querySelectorAll("link[rel=canonical], link[rel=alternate][hreflang], link[rel=icon], link[rel=manifest]")].map((l) => `${l.getAttribute("rel")}${l.getAttribute("hreflang") ? ":" + l.getAttribute("hreflang") : ""}=${l.getAttribute("href")}`).sort();
  const ld = [...document.querySelectorAll('script[type="application/ld+json"]')].map((s) => { try { return JSON.parse(s.textContent); } catch { return "INVALID-JSON"; } });
  const visible = (el) => { const r = el.getBoundingClientRect(); const cs = getComputedStyle(el); return r.width > 0 && r.height > 0 && cs.visibility !== "hidden" && cs.display !== "none"; };
  const rects = [...document.querySelectorAll("header, nav, main, footer, h1, h2, h3, h4, a[href], button, input, select, textarea, img, form, section, article")]
    .filter(visible).slice(0, 900)
    .map((el) => { const b = el.getBoundingClientRect(); return [el.tagName.toLowerCase(), round(b.x + scrollX), round(b.y + scrollY), round(b.width), round(b.height)]; });
  const internal = [...new Set([...document.querySelectorAll("a[href]")].map((a) => a.getAttribute("href")).filter((h) => h && (h.startsWith("/") || h.startsWith(location.origin))).map((h) => h.replace(location.origin, "")))].sort();
  // A hidden image (the logo of the other theme, a lazy image nobody scrolled to) is not "broken"; a visible one that did not load is.
  const images = [...document.images].map((i) => { const b = i.getBoundingClientRect(); return { src: i.currentSrc || i.src, ok: i.complete && i.naturalWidth > 0, visible: b.width > 0 && b.height > 0 && getComputedStyle(i).visibility !== "hidden" }; });
  return {
    title: document.title,
    lang: document.documentElement.lang,
    metas, links, ld,
    h1: norm(document.querySelector("h1")?.textContent),
    text: norm(document.body.innerText),
    counts: { links: document.querySelectorAll("a[href]").length, images: document.images.length, forms: document.forms.length, buttons: document.querySelectorAll("button").length, inputs: document.querySelectorAll("input, select, textarea").length },
    internal,
    brokenImages: images.filter((i) => !i.ok).map((i) => i.src.slice(0, 140)),
    brokenVisibleImages: images.filter((i) => i.visible && !i.ok).map((i) => i.src.slice(0, 140)),
    imageSources: images.map((i) => i.src.replace(location.origin, "").replace(/\?.*$/, "").slice(0, 140)).sort(),
    rects,
    scroll: [document.documentElement.scrollWidth, document.documentElement.scrollHeight],
  };
});

// ---------------------------------------------------------------------------------------------------------- pixels
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

/** The first path at which two values differ, for a readable failure. */
function firstDifference(a, b, path = "") {
  if (JSON.stringify(a) === JSON.stringify(b)) return "";
  if (Array.isArray(a) && Array.isArray(b)) {
    if (a.length !== b.length) return `${path}: length ${a.length} -> ${b.length}`;
    for (let i = 0; i < a.length; i++) { const found = firstDifference(a[i], b[i], `${path}[${i}]`); if (found) return found; }
  }
  if (a && b && typeof a === "object" && typeof b === "object") {
    for (const key of new Set([...Object.keys(a), ...Object.keys(b)])) {
      const found = firstDifference(a[key], b[key], `${path}.${key}`);
      if (found) return found;
    }
  }
  return `${path}: ${JSON.stringify(a)?.slice(0, 140)} -> ${JSON.stringify(b)?.slice(0, 140)}`;
}

// ---------------------------------------------------------------------------------------------------------- pages
async function pagesPhase(mode) {
  let browser = await launch();
  const shots = join(OUT, "shots");
  mkdirSync(shots, { recursive: true });
  const compare = mode === "compare";
  const baseline = compare ? JSON.parse(readFileSync(join(BASELINE_DIR, "facts.json"), "utf8")) : null;
  const cases = compare ? JSON.parse(readFileSync(join(BASELINE_DIR, "cases.json"), "utf8")) : await buildCases();
  writeFileSync(join(OUT, "cases.json"), JSON.stringify(cases, null, 1));
  const only = arg("filter");
  const recorded = {};
  let diffPage = null;
  const prepare = async () => { diffPage = compare ? (await openPage(browser, { viewport: "desktop", theme: "light" })).page : null; };
  await prepare();
  note(`${cases.length} case(s) (pages-${mode}) on ${SITE}`);

  const attempts = Number(arg("attempts", "2"));
  for (const c of cases) {
    if (only && !c.key.includes(only)) continue;
    for (let attempt = 1; attempt <= attempts; attempt++) {
      if (!browser.connected) { browser = await launch(); await prepare(); }
      if (await capture(c, attempt === attempts)) break;
    }
  }
  writeFileSync(join(OUT, "facts.json"), JSON.stringify(recorded));
  await browser.close().catch(() => {});
  if (!compare) note(`baseline written: ${Object.keys(recorded).length} page view(s)`);

  async function capture(c, last) {
    const { page, context, problems } = await openPage(browser, c);
    try {
      const response = await page.goto(`${SITE}${c.path}`, { waitUntil: "networkidle2", timeout: 90000 });
      await settle(page);
      const f = await facts(page);
      const finalPath = new URL(page.url()).pathname + new URL(page.url()).search;
      const rec = { status: response?.status(), finalPath, ...f, problems: [...new Set(problems)].sort() };
      recorded[c.key] = rec;
      const images = await page.evaluate(() => [...document.images].map((el) => { const r = el.getBoundingClientRect(); return [r.x + scrollX, r.y + scrollY, r.width, r.height]; }).filter((r) => r[2] > 0 && r[3] > 0));
      const png = await page.screenshot({ type: "png", fullPage: true });
      const file = join(shots, `${c.key.replace(/[|/]/g, "_")}.png`);
      writeFileSync(file, png);
      if (compare) await judge(c, rec, png, images);
      return true;
    } catch (error) {
      if (last) check("R!", `${c.key}: the page was captured`, false, String(error).slice(0, 220));
      else note(`${c.key}: attempt failed (${String(error).slice(0, 100)}); trying again`);
      return false;
    } finally {
      await context.close().catch(() => {});
    }
  }

  async function judge(c, now, png, images) {
    const before = baseline[c.key];
    if (!before) { check("R0", `${c.key}: the page is in the baseline`, false); return; }
    check("R1", `${c.key}: same status (${now.status}) and same final address (${now.finalPath})`, now.status === before.status && now.finalPath === before.finalPath, `${before.status} ${before.finalPath} -> ${now.status} ${now.finalPath}`);
    const seoKeys = ["title", "lang", "metas", "links", "ld", "h1"];
    const seoBefore = Object.fromEntries(seoKeys.map((k) => [k, before[k]]));
    const seoNow = Object.fromEntries(seoKeys.map((k) => [k, now[k]]));
    check("R2", `${c.key}: same title, language, meta tags, canonical / hreflang links, JSON-LD and heading`, JSON.stringify(seoBefore) === JSON.stringify(seoNow), firstDifference(seoBefore, seoNow));
    const contentKeys = ["text", "counts", "internal", "imageSources"];
    const cBefore = Object.fromEntries(contentKeys.map((k) => [k, k === "text" ? sha(before.text) : before[k]]));
    const cNow = Object.fromEntries(contentKeys.map((k) => [k, k === "text" ? sha(now.text) : now[k]]));
    check("R3", `${c.key}: same visible text, element counts, internal links and image sources`, JSON.stringify(cBefore) === JSON.stringify(cNow), JSON.stringify(cBefore) === JSON.stringify(cNow) ? "" : (cBefore.text !== cNow.text ? `text ${cBefore.text} -> ${cNow.text}: ${diffText(before.text, now.text)}` : firstDifference(cBefore, cNow)));
    check("R4", `${c.key}: every landmark, heading, link, button, field and image is where it was (${now.rects.length} boxes, page ${now.scroll.join("x")})`, JSON.stringify([before.rects, before.scroll]) === JSON.stringify([now.rects, now.scroll]), firstDifference([before.rects, before.scroll], [now.rects, now.scroll]));
    const beforePng = readFileSync(join(BASELINE_DIR, "shots", `${c.key.replace(/[|/]/g, "_")}.png`));
    const same = Buffer.compare(beforePng, png) === 0;
    const diff = same ? { same: true, differing: 0, maxInside: 0, maxOutside: 0 } : await decodeAndDiff(diffPage, beforePng, png, images);
    check("R5", `${c.key}: every pixel is where it was (rounding of ${TEXT_TOLERANCE}/255, and of ${IMAGE_TOLERANCE}/255 inside photographs, aside)`, diff.same, `${diff.differing} pixel(s) differ in ${JSON.stringify(diff.box)}; sizes ${JSON.stringify(diff.size)}`);
    if (!same && diff.same) note(`${c.key}: only rounding noise (largest difference ${diff.maxOutside} outside photographs, ${diff.maxInside} inside)`);
    const newProblems = now.problems.filter((p) => !before.problems.includes(p));
    check("R6", `${c.key}: no console error, page error, failed request or error response that the baseline did not have`, newProblems.length === 0, newProblems.join(" | "));
    check("R7", `${c.key}: every visible image loaded (${now.imageSources.length} images on the page)`, now.brokenVisibleImages.length === 0, now.brokenVisibleImages.join(" | "));
  }
}

/** A few words of context for a text difference. */
function diffText(a, b) {
  let i = 0;
  while (i < a.length && i < b.length && a[i] === b[i]) i++;
  return `first difference at character ${i}: "${a.slice(Math.max(0, i - 30), i + 50)}" -> "${b.slice(Math.max(0, i - 30), i + 50)}"`;
}

// ---------------------------------------------------------------------------------------------------------- headers
const KEEP_HEADERS = ["content-type", "cache-control", "vary", "x-robots-tag", "location", "content-disposition", "content-security-policy", "content-language", "x-content-type-options", "referrer-policy", "permissions-policy", "strict-transport-security", "access-control-allow-origin", "link", "x-nextjs-prerender", "allow"];
// Soft: recorded, never compared. `x-nextjs-prerender` is cache state; `link` (early-hint preloads of the stylesheet and the first image) is
// present on the first render of a route after a server start and absent afterwards, in 16.3.6 and in 16.4.2 alike (measured both ways round).
const SOFT_HEADERS = new Set(["x-nextjs-prerender", "link"]);

/** The addresses whose answers a framework upgrade could change, including refused image-optimizer requests. */
async function headerCases() {
  const list = [];
  const add = (key, path, headers = {}) => list.push({ key, path, headers });
  if (SUITE === "institutional") {
    for (const p of ["/", "/en", "/about", "/contact", "/notices", "/activities", "/recruitment", "/membership", "/leadership", "/en/leadership", "/member/login", "/sitemap.xml", "/robots.txt", "/favicon.ico", "/brand/provatferi-light.png", "/textures/provatferi-floral-light.avif", "/does-not-exist", "/en/does-not-exist", "/en/member/login", "/leadership/zz-no-such-person-qa", "/api/revalidate", "/api/does-not-exist"]) add(p, p);
    // Route matching (Next 16.4 turned strict route matching on by default): the same page asked for in other spellings, the proxy's
    // locale rewrite, an address nothing answers, and a protected page. Each must answer exactly as before.
    for (const p of ["/about/", "/ABOUT", "/about?utm_source=qa", "/en/about/", "/en/", "/bn/about", "//about", "/leadership/mehedi-hasan-roney/", "/leadership/Mehedi-Hasan-Roney", "/recruitment/zz-no-such-posting-qa/apply/success", "/en/recruitment/zz-no-such-posting-qa", "/member/dashboard", "/member/dashboard/profile", "/api/membership/apply", "/api/member/profile", "/sitemap.xml/", "/robots.txt/", "/_next/static/chunks/does-not-exist.js"]) add(p, p);
    const logo = encodeURIComponent("/brand/provatferi-light.png");
    add("image: logo, browser accept", `/_next/image?url=${logo}&w=640&q=75`, { accept: "image/avif,image/webp,image/apng,image/*,*/*;q=0.8" });
    add("image: logo, webp only", `/_next/image?url=${logo}&w=640&q=75`, { accept: "image/webp,*/*" });
    add("image: logo, png only", `/_next/image?url=${logo}&w=640&q=75`, { accept: "image/png,*/*" });
    add("image: logo, 3840 wide", `/_next/image?url=${logo}&w=3840&q=75`, { accept: "image/webp,*/*" });
    add("image: logo, width not allowed", `/_next/image?url=${logo}&w=641&q=75`, { accept: "image/webp,*/*" });
    add("image: logo, quality not allowed", `/_next/image?url=${logo}&w=640&q=33`, { accept: "image/webp,*/*" });
    add("image: avif source", `/_next/image?url=${encodeURIComponent("/textures/provatferi-floral-light.avif")}&w=640&q=75`, { accept: "image/webp,*/*" });
    add("image: remote host not allowed", `/_next/image?url=${encodeURIComponent("https://example.com/x.png")}&w=64&q=75`, { accept: "image/webp,*/*" });
    add("image: cms host, a path that is not an upload", `/_next/image?url=${encodeURIComponent("https://cms.provatferi.org/wp-json/")}&w=64&q=75`, { accept: "image/webp,*/*" });
    add("image: not an image", `/_next/image?url=${encodeURIComponent("/robots.txt")}&w=64&q=75`, { accept: "image/webp,*/*" });
    add("image: no url", `/_next/image?w=64&q=75`, { accept: "image/webp,*/*" });
  } else {
    for (const p of ["/", "/search", "/sitemap.xml", "/robots.txt", "/favicon.ico", "/does-not-exist", "/article/zz-no-such-article-qa", "/author/zz-no-such-author-qa"]) add(p, p);
    const html = (await get(`${SITE}/`)).html;
    const img = html.match(/\/_next\/image\?url=([^&"]+)&amp;w=\d+&amp;q=\d+/);
    if (img) {
      add("image: a CMS image through the optimizer", `/_next/image?url=${img[1]}&w=640&q=75`, { accept: "image/webp,*/*" });
      add("image: the same, browser accept", `/_next/image?url=${img[1]}&w=640&q=75`, { accept: "image/avif,image/webp,image/apng,image/*,*/*;q=0.8" });
      add("image: width not allowed", `/_next/image?url=${img[1]}&w=641&q=75`, { accept: "image/webp,*/*" });
    }
    add("image: remote host not allowed", `/_next/image?url=${encodeURIComponent("https://example.com/x.png")}&w=64&q=75`, { accept: "image/webp,*/*" });
    add("image: cms host, a path that is not an upload", `/_next/image?url=${encodeURIComponent("https://cms.provatferi.org/wp-json/")}&w=64&q=75`, { accept: "image/webp,*/*" });
    add("image: local svg", `/_next/image?url=${encodeURIComponent("/next.svg")}&w=64&q=75`, { accept: "image/webp,*/*" });
    add("image: not an image", `/_next/image?url=${encodeURIComponent("/robots.txt")}&w=64&q=75`, { accept: "image/webp,*/*" });
  }
  return list;
}

async function headersPhase(mode) {
  const compare = mode === "compare";
  const list = compare ? JSON.parse(readFileSync(join(BASELINE_DIR, "header-cases.json"), "utf8")) : await headerCases();
  writeFileSync(join(OUT, "header-cases.json"), JSON.stringify(list, null, 1));
  const baseline = compare ? JSON.parse(readFileSync(join(BASELINE_DIR, "headers.json"), "utf8")) : null;
  const recorded = {};
  for (const c of list) {
    let rec;
    try {
      const res = await fetch(`${SITE}${c.path}`, { redirect: "manual", headers: { "user-agent": UA, "accept-language": "en", ...c.headers } });
      const body = Buffer.from(await res.arrayBuffer());
      const headers = Object.fromEntries(KEEP_HEADERS.map((h) => [h, res.headers.get(h)]).filter(([, v]) => v !== null));
      const magic = body.subarray(0, 12).toString("latin1");
      const format = /^RIFF....WEBP/s.test(magic) ? "webp" : magic.startsWith("\x89PNG") ? "png" : magic.startsWith("\xff\xd8") ? "jpeg" : /ftypavif/.test(body.subarray(4, 16).toString("latin1")) ? "avif" : /^<\?xml|^<svg/.test(body.subarray(0, 8).toString("utf8")) ? "svg" : "other";
      rec = { status: res.status, headers, format, bytes: body.length, bodySha: /text\/plain|xml|json/.test(headers["content-type"] ?? "") ? sha(body.toString("utf8").replace(/<lastmod>[^<]*<\/lastmod>/g, "")) : null };
    } catch (error) {
      rec = { error: String(error).slice(0, 160) };
    }
    recorded[c.key] = rec;
    if (compare) {
      const before = baseline[c.key];
      if (!before) { check("H0", `${c.key}: is in the baseline`, false); continue; }
      const hard = (r) => ({ status: r.status, error: r.error, format: r.format, bodySha: r.bodySha, headers: Object.fromEntries(Object.entries(r.headers ?? {}).filter(([k]) => !SOFT_HEADERS.has(k))) });
      check("H1", `${c.key}: same status (${rec.status}), headers, format (${rec.format}) and body`, JSON.stringify(hard(before)) === JSON.stringify(hard(rec)), firstDifference(hard(before), hard(rec)));
    }
  }
  writeFileSync(join(OUT, "headers.json"), JSON.stringify(recorded, null, 1));
  if (!compare) {
    for (const [key, r] of Object.entries(recorded)) note(`${key.padEnd(48)} ${String(r.status ?? r.error).padEnd(4)} ${String(r.format ?? "").padEnd(6)} ${r.headers?.["content-type"] ?? ""} | ${r.headers?.["cache-control"] ?? ""}`);
  }
}

// ---------------------------------------------------------------------------------------------------------- run
if (PHASE === "pages-baseline") await pagesPhase("baseline");
else if (PHASE === "pages-compare") await pagesPhase("compare");
else if (PHASE === "headers-baseline") await headersPhase("baseline");
else if (PHASE === "headers-compare") await headersPhase("compare");
else { console.error(`unknown phase ${PHASE}`); process.exit(2); }
finish();
