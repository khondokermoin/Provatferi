// Real-Chrome acceptance of the Leadership / Key Persons system (admin-erp/docs/LEADERSHIP_SYSTEM.md): it drives the REAL admin
// screens (create, edit, photo, activate, feature, reorder, archive), then looks at the REAL public site, on desktop and phone,
// in light and dark, and measures how long each admin change takes to reach a visitor. Run it against production or a local pair:
//
//   node scripts/leadership-qa.mjs --admin https://admin.provatferi.org --site https://provatferi.org \
//        --env <file with <PREFIX>_EMAIL and <PREFIX>_PASSWORD> --prefix PROD_QA_ADMIN \
//        --fixtures <dir made by php admin-erp/deploy/qa/leadership-fixtures.php> --out <evidence dir> [--tag <6 hex>]
//
// WHAT IT TOUCHES. Only disposable data, every name of it starting with the marker QA-LEAD-<tag>: four profiles, one role and
// two users (they prove that access is granted per permission). It never opens, edits or reorders a profile it did not create,
// and it ends by archiving what it made. Rows, files, users and the role are DELETED afterwards by the server-side kit
// (admin-erp/deploy/qa/leadership-qa.php purge), which is the only thing that matches the marker. The admin login is read from
// the env file only and is never printed; the two throw-away users get random passwords that live in this process only.
//
// Check ids follow the owner's matrix: A create  B edit Bangla  C edit English  D photo  E publish  F deactivate  G feature
// H reorder  I invalid input refused  J access control  K Bangla profile page  L English profile page  M public API privacy
// N desktop and phone  O light and dark  P cache revalidation timings  Q old address redirects  R archive and restore
// S hardening (script injection, forged fields, hostile uploads)  T admin screens in both languages  U sitemap.
import { createRequire } from "node:module";
import { existsSync, mkdirSync, readFileSync, statSync, writeFileSync } from "node:fs";
import { join } from "node:path";
import { randomBytes } from "node:crypto";

const arg = (name, fallback) => {
  const i = process.argv.indexOf(`--${name}`);
  return i > -1 ? process.argv[i + 1] : fallback;
};
const ADMIN = (arg("admin") ?? "").replace(/\/+$/, "");
const SITE = (arg("site") ?? "").replace(/\/+$/, "");
const OUT = arg("out");
const ENV_FILE = arg("env");
const PREFIX = arg("prefix");
const FIXTURES = arg("fixtures");
const TAG = (arg("tag") ?? randomBytes(3).toString("hex")).toLowerCase();
if (!ADMIN || !SITE || !OUT || !ENV_FILE || !PREFIX || !FIXTURES || !/^[a-f0-9]{6}$/.test(TAG)) {
  console.error("usage: --admin <url> --site <url> --env <file> --prefix <KEY_PREFIX> --fixtures <dir> --out <dir> [--tag <6 hex>]");
  process.exit(2);
}
mkdirSync(OUT, { recursive: true });

const require = createRequire(import.meta.url);
const puppeteer = require("puppeteer-core");
const env = Object.fromEntries(readFileSync(ENV_FILE, "utf8").split(/\r?\n/).filter((l) => l.includes("=")).map((l) => [l.slice(0, l.indexOf("=")).trim(), l.slice(l.indexOf("=") + 1).trim()]));
const ADMIN_EMAIL = env[`${PREFIX}_EMAIL`];
const ADMIN_PASSWORD = env[`${PREFIX}_PASSWORD`];
const chrome = [process.env.CHROME_PATH, "C:/Program Files/Google/Chrome/Application/chrome.exe", "C:/Program Files (x86)/Google/Chrome/Application/chrome.exe", "/usr/bin/google-chrome", "/usr/bin/chromium"].filter(Boolean).find(existsSync);
const fixture = (name) => join(FIXTURES, name);

const MARK = `QA-LEAD-${TAG}`;
const slugBase = `qa-lead-${TAG}`;
const UA = "provatferi-leadership-qa/1.0";
const NO_SHOT = process.argv.includes("--no-shots");

const checks = [];
const timings = [];
const notes = [];
const check = (id, description, ok, detail = "") => {
  checks.push({ id, description, ok: Boolean(ok), detail: String(detail).slice(0, 400) });
  console.log(`${ok ? "PASS" : "FAIL"}  ${id.padEnd(4)} ${description}${ok || !detail ? "" : `  -> ${String(detail).slice(0, 240)}`}`);
};
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const fileSafe = (s) => s.replace(/[^a-z0-9-]+/gi, "-");
const strongPassword = () => `Qa!${randomBytes(9).toString("hex")}Zz9`;

// ----------------------------------------------------------------------------------------------------------- the data
const ver = (extra = "") => `${MARK}${extra}`;
const DATA = {
  A: {
    slug: `${slugBase}-a`,
    name_bn: `${ver()} A নমুনা`, name_en: `${ver()} A`,
    designation_bn: "পরীক্ষামূলক পদবি", designation_en: "QA test designation",
    short_bio_bn: "এটি একটি পরীক্ষামূলক প্রোফাইল; এতে কোনো সত্য তথ্য নেই।", short_bio_en: "A QA profile. It holds no real information.",
    full_bio_bn: "প্রথম অনুচ্ছেদ (QA)।\n\nদ্বিতীয় অনুচ্ছেদে তালিকা:\n• প্রথম বিন্দু\n• দ্বিতীয় বিন্দু",
    full_bio_en: "First QA paragraph.\n\nA list in the second paragraph:\n• first point\n• second point\n\nA link: https://example.org/qa-path?x=1&y=2",
    quote_bn: "এটি একটি পরীক্ষামূলক উক্তি।", quote_en: "This is a QA quote.",
    expertise_bn: "পরীক্ষা", expertise_en: "Testing",
    associated_since_year: "2020",
    public_email: `qa.lead.${TAG}@example.org`, public_phone: "+880 1711 000002",
    facebook_url: `https://www.facebook.com/qa.lead.${TAG}`, linkedin_url: `https://www.linkedin.com/in/qa-lead-${TAG}`,
    youtube_url: `https://www.youtube.com/@qalead${TAG}`, website_url: `https://example.org/qa-lead-${TAG}`,
  },
  // Bangla only: no English text at all, so its English page is the fallback render with the translation note.
  B: {
    slug: `${slugBase}-b`,
    name_bn: `${ver()} B শুধু-বাংলা`, designation_bn: "শুধু বাংলায় পদবি",
    short_bio_bn: "শুধু বাংলায় লেখা একটি পরীক্ষামূলক পরিচিতি।",
  },
  // Slug left empty on purpose: the address is made from the English name.
  C: {
    name_bn: `${ver()} C তৃতীয়`, name_en: `${ver()} C`,
    designation_bn: "তৃতীয় পরীক্ষামূলক পদবি", designation_en: "Third QA designation",
    short_bio_en: "Third QA profile, short biography.",
  },
  // Created by the editor role, which cannot publish.
  D: {
    slug: `${slugBase}-d`,
    name_bn: `${ver()} D সম্পাদক`, name_en: `${ver()} D`,
    designation_bn: "সম্পাদকের তৈরি পদবি", designation_en: "Made by an editor",
    short_bio_en: "Created by a user who may not publish.",
  },
};
const slugC = `${slugBase}-c`; // what the English name C makes
const profileUrl = (slug, en = false) => `${SITE}${en ? "/en" : ""}/leadership/${slug}`;
const apiProfile = (slug) => `/api/v1/public/leadership/${slug}`;

// ---------------------------------------------------------------------------------------------------------- browser
const browser = await puppeteer.launch({ executablePath: chrome, headless: true, args: ["--no-sandbox", "--hide-scrollbars"] });
const adminPage = await browser.newPage();
await adminPage.setViewport({ width: 1366, height: 1000 });
// A page asking "leave site?" would block every later navigation: accept it (the unsaved-changes check handles its own).
adminPage.on("dialog", (d) => d.accept().catch(() => {}));
const adminProblems = [];
adminPage.on("console", (m) => { if (m.type() === "error") adminProblems.push(`console: ${m.text().slice(0, 160)}`); });
adminPage.on("pageerror", (e) => adminProblems.push(`pageerror: ${String(e).slice(0, 160)}`));

async function login(page, email, password) {
  await page.goto(`${ADMIN}/login`, { waitUntil: "networkidle2", timeout: 90000 });
  await page.type("#email", email, { delay: 1 });
  await page.type("#password", password, { delay: 1 });
  await Promise.all([page.waitForNavigation({ waitUntil: "networkidle2", timeout: 90000 }), page.click('form button[type="submit"]')]);
  return !page.url().includes("/login");
}


/** What the screen shows after a form: messages, per-field errors and which controls are marked invalid. */
async function screenState(page) {
  return page.evaluate(() => {
    const text = (sel) => [...document.querySelectorAll(sel)].map((e) => e.textContent.replace(/\s+/g, " ").trim());
    return {
      path: location.pathname,
      success: text(".alert-success"),
      warning: text(".alert-warning"),
      danger: text(".alert-danger"),
      errors: text(".invalid-feedback"),
      invalidFields: [...document.querySelectorAll('[aria-invalid="true"]')].map((e) => e.getAttribute("name")),
    };
  });
}

// ----------------------------------------------------------------------------------------------------- admin helpers
const FORM = "#leadership-form";

async function openCreate(page = adminPage) {
  const response = await page.goto(`${ADMIN}/admin/leadership/create`, { waitUntil: "networkidle2", timeout: 90000 });
  return response?.status();
}

async function openEdit(id, page = adminPage) {
  const response = await page.goto(`${ADMIN}/admin/leadership/${id}/edit`, { waitUntil: "networkidle2", timeout: 90000 });
  return response?.status();
}

/** Sets text controls, selects and switches by NAME (all panes of a bilingual field are in the page, so a hidden tab works too). */
async function setFields(page, values) {
  await page.evaluate((formSel, vals) => {
    const form = document.querySelector(formSel);
    for (const [name, value] of Object.entries(vals)) {
      const els = [...form.querySelectorAll(`[name="${name}"]`)];
      const el = els.find((e) => e.type !== "hidden") ?? els[0];
      if (!el) throw new Error(`no field named ${name}`);
      if (el.type === "checkbox") el.checked = Boolean(value);
      else el.value = value ?? "";
      el.dispatchEvent(new Event("input", { bubbles: true }));
      el.dispatchEvent(new Event("change", { bubbles: true }));
    }
  }, FORM, values);
}

/** What a hostile client does: extra form fields the screen never offers. */
async function injectFields(page, fields) {
  await page.evaluate((formSel, extra) => {
    const form = document.querySelector(formSel);
    for (const [name, value] of Object.entries(extra)) {
      const input = document.createElement("input");
      input.type = "hidden";
      input.name = name;
      input.value = value;
      form.appendChild(input);
    }
  }, FORM, fields);
}

async function attachPhoto(page, file) {
  const input = await page.$(`${FORM} input[type="file"][name="photo"]`);
  await input.uploadFile(file);
}

async function readForm(page) {
  return page.$$eval(`${FORM} [name]`, (els) => {
    const out = {};
    for (const el of els) {
      const name = el.getAttribute("name");
      if (name.startsWith("_") || el.type === "hidden" || el.type === "file") continue;
      out[name] = el.type === "checkbox" ? el.checked : el.value;
    }
    return out;
  });
}

/** Presses the real Save button. `noValidate` switches the browser's own checks off so the SERVER's rules are what answer. */
async function submitForm(page, { noValidate = false } = {}) {
  if (noValidate) await page.$eval(FORM, (f) => { f.noValidate = true; });
  const startedAt = Date.now();
  await Promise.all([page.waitForNavigation({ waitUntil: "networkidle2", timeout: 90000 }), page.click(`${FORM} button[type="submit"]`)]);
  return { ...(await screenState(page)), saveMs: Date.now() - startedAt, startedAt };
}

const saved = (r) => r.success.length === 1 && r.errors.length === 0 && r.invalidFields.length === 0 && r.path === "/admin/leadership";

async function createProfile(values, { photo, noValidate = false, inject, page = adminPage } = {}) {
  const status = await openCreate(page);
  if (status !== 200) return { status, success: [], danger: [], errors: [], invalidFields: [], path: "" };
  await setFields(page, values);
  if (photo) await attachPhoto(page, photo);
  if (inject) await injectFields(page, inject);
  return submitForm(page, { noValidate });
}

/** The row of the unfiltered or searched list that shows this slug, with its edit id. */
async function findProfile(slug, { archived = false, page = adminPage } = {}) {
  await page.goto(`${ADMIN}/admin/leadership?q=${encodeURIComponent(slug)}${archived ? "&status=archived" : ""}`, { waitUntil: "networkidle2", timeout: 90000 });
  return page.evaluate((s) => {
    const row = [...document.querySelectorAll("tbody tr")].find((tr) => new RegExp(`/leadership/${s}(\\s|$)`).test(tr.textContent));
    if (!row) return null;
    const edit = row.querySelector('a[href$="/edit"]');
    return { id: edit?.getAttribute("href")?.match(/leadership\/(\d+)\/edit/)?.[1] ?? null, text: row.textContent.replace(/\s+/g, " ").trim(), badges: [...row.querySelectorAll(".badge")].map((b) => b.textContent.trim()) };
  }, slug);
}

async function rowHandle(slug) {
  const handle = await adminPage.evaluateHandle((s) => [...document.querySelectorAll("tbody tr")].find((tr) => new RegExp(`/leadership/${s}(\\s|$)`).test(tr.textContent)) ?? null, slug);
  return handle.asElement();
}

/** status | featured | archive | restore from the row's own action menu, exactly as a person does it. */
async function rowAction(slug, action, listPath = "/admin/leadership") {
  await adminPage.goto(`${ADMIN}${listPath}`, { waitUntil: "networkidle2", timeout: 90000 });
  const row = await rowHandle(slug);
  if (!row) throw new Error(`no row for ${slug} on the first page of the list`);
  await (await row.$('[data-bs-toggle="dropdown"]')).click();
  await sleep(200);
  const startedAt = Date.now();
  if (action === "archive") {
    await (await row.$('button[data-bs-target^="#archive-profile-"]')).click();
    await adminPage.waitForSelector(".modal.show form[action$='/archive'] button[type='submit']", { visible: true, timeout: 15000 });
    await Promise.all([adminPage.waitForNavigation({ waitUntil: "networkidle2", timeout: 90000 }), adminPage.click(".modal.show form[action$='/archive'] button[type='submit']")]);
  } else {
    const button = await row.$(`form[action$="/${action}"] button`);
    if (!button) throw new Error(`no ${action} action in the row of ${slug}`);
    await Promise.all([adminPage.waitForNavigation({ waitUntil: "networkidle2", timeout: 90000 }), button.click()]);
  }
  return { ...(await screenState(adminPage)), saveMs: Date.now() - startedAt, startedAt };
}

async function moveRow(slug, direction) {
  await adminPage.goto(`${ADMIN}/admin/leadership`, { waitUntil: "networkidle2", timeout: 90000 });
  const row = await rowHandle(slug);
  if (!row) throw new Error(`no row for ${slug} on the first page of the list`);
  const button = await row.$(`form[action$="/move-${direction}"] button`);
  const startedAt = Date.now();
  await Promise.all([adminPage.waitForNavigation({ waitUntil: "networkidle2", timeout: 90000 }), button.click()]);
  return { ...(await screenState(adminPage)), saveMs: Date.now() - startedAt, startedAt };
}

/** The QA slugs in the order the admin list shows them (unfiltered list). */
async function adminOrder() {
  await adminPage.goto(`${ADMIN}/admin/leadership`, { waitUntil: "networkidle2", timeout: 90000 });
  return adminPage.evaluate((base) => [...document.querySelectorAll("tbody tr")].map((tr) => tr.textContent.match(new RegExp(`/leadership/(${base}-[a-z0-9-]+)`))?.[1]).filter(Boolean), slugBase);
}

// ------------------------------------------------------------------------------------------------ public-side helpers
async function http(url, init = {}) {
  const res = await fetch(url, { redirect: "follow", headers: { "user-agent": UA, accept: "text/html,application/json" }, signal: AbortSignal.timeout(30000), ...init });
  return { status: res.status, text: await res.text(), headers: res.headers, location: res.headers.get("location"), url: res.url };
}

async function api(path) {
  const res = await http(`${ADMIN}${path}`, { headers: { "user-agent": UA, accept: "application/json" } });
  let json = null;
  try { json = JSON.parse(res.text); } catch { /* not JSON */ }
  return { ...res, json };
}

/** Polls with a plain GET (what a visitor's browser sends) until `predicate(html, status)`; returns ms since `since`. */
async function propagation(url, predicate, since, timeoutMs = 90000) {
  let polls = 0;
  while (Date.now() - since < timeoutMs) {
    polls++;
    try {
      const r = await http(url);
      if (predicate(r.text, r.status)) return { ok: true, ms: Date.now() - since, polls };
    } catch { /* a failed poll is just another poll */ }
    await sleep(120);
  }
  return { ok: false, ms: Date.now() - since, polls };
}

async function measure(label, started, url, predicate, timeoutMs) {
  const result = await propagation(url, predicate, started.startedAt, timeoutMs);
  timings.push({ label, url: url.replace(SITE, "").replace(ADMIN, "(api)"), adminSaveMs: started.saveMs, clickToVisibleMs: result.ms, afterSaveResponseMs: Math.max(0, result.ms - started.saveMs), polls: result.polls, ok: result.ok });
  return result;
}

// A predicate is only true for a real page: an error page that happens to lack the text is not "gone".
const has = (needle) => (html, status) => status === 200 && html.includes(needle);
const lacks = (needle) => (html, status) => status === 200 && !html.includes(needle);
const gone = (html, status) => status === 404;

/** Slugs of the QA cards on a public Leadership page, in page order. */
function qaOrderIn(html, en = false) {
  const re = new RegExp(`href="${en ? "/en" : ""}/leadership/(${slugBase}-[a-z0-9-]+)"`, "g");
  const seen = [];
  for (const m of html.matchAll(re)) if (!seen.includes(m[1])) seen.push(m[1]);
  return seen;
}

async function viewPublic(path, { mobile = false, theme = "light", cookies } = {}) {
  const page = await browser.newPage();
  const problems = { console: [], failed: [], http: [] };
  page.on("console", (m) => { if (m.type() === "error") problems.console.push(m.text().slice(0, 160)); });
  page.on("pageerror", (e) => problems.console.push(String(e).slice(0, 160)));
  page.on("requestfailed", (r) => { if (!(r.url().includes("?_rsc=") && r.failure()?.errorText === "net::ERR_ABORTED")) problems.failed.push(`${r.failure()?.errorText} ${r.url().slice(0, 110)}`); });
  page.on("response", (r) => { if (r.status() >= 400) problems.http.push(`${r.status()} ${r.url().slice(0, 110)}`); });
  await page.setViewport(mobile ? { width: 390, height: 844, isMobile: true, hasTouch: true, deviceScaleFactor: 1 } : { width: 1366, height: 900, deviceScaleFactor: 1 });
  await page.evaluateOnNewDocument((t) => { try { localStorage.setItem("provatferi-theme", t); } catch { /* ignore */ } }, theme);
  if (cookies) await page.setCookie(...cookies);
  const response = await page.goto(`${SITE}${path}`, { waitUntil: "networkidle0", timeout: 90000 });
  return { page, problems, status: response?.status() };
}

async function shot(page, name, selector) {
  if (NO_SHOT) return null;
  const file = join(OUT, `${fileSafe(name)}.png`);
  if (selector) {
    const el = await page.$(selector);
    if (el) { await el.scrollIntoView(); await el.screenshot({ path: file }); return file; }
  }
  await page.screenshot({ path: file, fullPage: true });
  return file;
}

/** What a visitor can see on a profile page. */
async function profileFacts(page) {
  return page.evaluate(() => {
    const text = (sel) => [...document.querySelectorAll(sel)].map((e) => e.textContent.replace(/\s+/g, " ").trim());
    const attr = (sel, a) => [...document.querySelectorAll(sel)].map((e) => e.getAttribute(a));
    const meta = (sel, a = "content") => document.querySelector(sel)?.getAttribute(a) ?? null;
    return {
      lang: document.documentElement.lang,
      title: document.title,
      h1: text("h1")[0] ?? null,
      designation: text(".leader-header p")[0] ?? null,
      breadcrumb: text("nav.breadcrumb"),
      quote: text(".leader-quote p")[0] ?? null,
      bio: text(".leader-bio-text"),
      bioHtmlTags: [...document.querySelectorAll(".leader-bio-text *")].map((e) => e.tagName.toLowerCase()),
      bioLinks: attr(".leader-bio-text a", "href"),
      bioLinkRel: attr(".leader-bio-text a", "rel"),
      facts: text(".leader-facts li"),
      contactLinks: attr(".leader-contact-list a", "href"),
      socialLinks: attr(".social-links-profile a", "href"),
      socialRel: attr(".social-links-profile a, .leader-contact-list a[target]", "rel"),
      photoSrc: meta(".leader-photo-page img", "src"),
      photoAlt: meta(".leader-photo-page img", "alt"),
      initials: text(".leader-initials").length > 0,
      note: text(".translation-note"),
      description: meta('meta[name="description"]'),
      canonical: meta('link[rel="canonical"]', "href"),
      alternates: [...document.querySelectorAll('link[rel="alternate"][hreflang]')].map((l) => [l.getAttribute("hreflang"), l.getAttribute("href")]),
      og: Object.fromEntries([...document.querySelectorAll('meta[property^="og:"]')].map((m) => [m.getAttribute("property"), m.getAttribute("content")])),
      robots: meta('meta[name="robots"]'),
      scriptsInMain: document.querySelectorAll("main script:not([type='application/ld+json'])").length,
      horizontalScroll: document.documentElement.scrollWidth > window.innerWidth + 1,
      brokenImages: [...document.images].filter((i) => i.getClientRects().length > 0 && i.complete && i.naturalWidth === 0).map((i) => i.currentSrc || i.src),
      entities: (document.body.innerText.match(/&(#\d+|#x[0-9a-f]+|amp|quot|lt|gt|apos);/gi) ?? []),
    };
  });
}

// ---- photo inspection --------------------------------------------------------------------------------------------------
function jpegInfo(buf) {
  if (buf.length < 4 || buf[0] !== 0xff || buf[1] !== 0xd8) return null;
  const info = { progressive: false, exif: false, width: 0, height: 0 };
  let i = 2;
  while (i + 4 < buf.length) {
    if (buf[i] !== 0xff) { i++; continue; }
    const marker = buf[i + 1];
    if (marker === 0xd8 || marker === 0x01 || (marker >= 0xd0 && marker <= 0xd7) || marker === 0xff) { i += marker === 0xff ? 1 : 2; continue; }
    const length = buf.readUInt16BE(i + 2);
    if (marker === 0xe1 && buf.subarray(i + 4, i + 8).toString("latin1") === "Exif") info.exif = true;
    if (marker === 0xc0 || marker === 0xc1 || marker === 0xc2) {
      info.progressive = marker === 0xc2;
      info.height = buf.readUInt16BE(i + 5);
      info.width = buf.readUInt16BE(i + 7);
      break;
    }
    i += 2 + length;
  }
  return info;
}

async function fetchImage(url) {
  const res = await fetch(url, { headers: { "user-agent": UA }, signal: AbortSignal.timeout(30000) });
  const bytes = Buffer.from(await res.arrayBuffer());
  return { status: res.status, type: res.headers.get("content-type") ?? "", bytes, info: res.status === 200 ? jpegInfo(bytes) : null };
}

// A deleted upload is a 404 from the web server. PHP's built-in server (the local pair only) answers 403 for a missing file under /storage.
const LOCAL_PAIR = /^https?:\/\/(127\.0\.0\.1|localhost)(:|\/|$)/.test(ADMIN);
const fileGone = (status) => status === 404 || (LOCAL_PAIR && status === 403);
const longest = (info) => (info ? Math.max(info.width, info.height) : 0);
const MiB = 1048576;

// ===================================================================================================================
const created = { roleName: null, userEmails: [] };
const ids = {};
let slugA = DATA.A.slug;
let originalLocale = "en";
let realBefore = [];

const signedIn = await login(adminPage, ADMIN_EMAIL, ADMIN_PASSWORD);
check("0", "QA admin signs in", signedIn);
if (!signedIn) {
  await browser.close();
  process.exit(1);
}
originalLocale = await adminPage.evaluate(() => document.documentElement.lang || "en");

async function step(name, fn) {
  try {
    await fn();
  } catch (error) {
    check(`${name}!`, `step "${name}" ended with an error`, false, error?.stack ?? error);
  }
}

try {
  // ---- 0. what is there before ----------------------------------------------------------------------------------------
  await step("preflight", async () => {
    const list = await api("/api/v1/public/leadership");
    realBefore = (list.json?.data ?? []).map((p) => p.slug).filter((s) => !s.startsWith(slugBase));
    check("0", "the public Leadership API answers 200 with a data array, and none of this run's profiles exist yet", list.status === 200 && Array.isArray(list.json?.data) && !list.text.includes(MARK), `status ${list.status}`);
    const sidebar = await adminPage.evaluate(() => [...document.querySelectorAll(".side-nav a")].map((a) => a.getAttribute("href")));
    check("0", "the Super Admin sees Leadership in the side menu", sidebar.some((h) => h?.endsWith("/admin/leadership")), sidebar.join(","));
    for (const name of ["portrait-a.jpg", "portrait-b.png", "portrait-c.jpg", "landscape.jpg", "sideways-exif.jpg", "tiny.jpg", "evil.jpg", "logo.svg", "notes.png", "big-ok.jpg", "big-too-large.jpg"]) {
      if (!existsSync(fixture(name))) throw new Error(`missing fixture ${name} in ${FIXTURES}`);
    }
    const bigOk = statSync(fixture("big-ok.jpg")).size;
    const bigBad = statSync(fixture("big-too-large.jpg")).size;
    check("0", "the two size fixtures sit on either side of the 5 MB limit", bigOk > 4 * MiB && bigOk < 4.98 * 1000 * 1000 && bigBad > 5.3 * 1000 * 1000, `${bigOk} / ${bigBad}`);
  });

  // ---- J setup: a role and two users that prove access is granted per permission --------------------------------------
  const nobody = { email: `qa-lead-${TAG}-nobody@example.test`, password: strongPassword(), name: `${MARK} no access` };
  const editor = { email: `qa-lead-${TAG}-editor@example.test`, password: strongPassword(), name: `${MARK} editor` };
  await step("access setup", async () => {
    // The role form lists every permission by its slug: the five leadership.* ones prove the data migration ran.
    await adminPage.goto(`${ADMIN}/admin/system/roles/create`, { waitUntil: "networkidle2", timeout: 90000 });
    const present = await adminPage.$$eval("label code", (els) => els.map((e) => e.textContent.trim()).filter((t) => t.startsWith("leadership.")));
    check("J", "the role editor offers exactly the five Leadership permissions (view, create, update, publish, archive)", ["leadership.view", "leadership.create", "leadership.update", "leadership.publish", "leadership.archive"].every((p) => present.includes(p)) && present.length === 5, present.join(","));

    created.roleName = `${MARK} editor role`;
    await adminPage.evaluate((name) => {
      const input = document.querySelector('input[name="name"]');
      input.value = name;
      input.dispatchEvent(new Event("input", { bubbles: true }));
      for (const slug of ["leadership.view", "leadership.create", "leadership.update"]) {
        const code = [...document.querySelectorAll("label code")].find((c) => c.textContent.trim() === slug);
        document.getElementById(code.closest("label").getAttribute("for")).checked = true;
      }
    }, created.roleName);
    const roleSaved = await Promise.all([adminPage.waitForNavigation({ waitUntil: "networkidle2", timeout: 90000 }), adminPage.click('form[action$="/system/roles"] button[type="submit"]')]).then(() => screenState(adminPage));
    check("J", "a role holding only view, create and update is created through the Roles screen", roleSaved.success.length === 1 && roleSaved.path.endsWith("/system/roles"), JSON.stringify(roleSaved));

    for (const [account, pickRole] of [[nobody, (labels) => labels.find((l) => /membership admin/i.test(l)) ?? labels.find((l) => !/super|QA-LEAD/i.test(l))], [editor, (labels) => labels.find((l) => l.includes(created.roleName))]]) {
      await adminPage.goto(`${ADMIN}/admin/system/users/create`, { waitUntil: "networkidle2", timeout: 90000 });
      const roleLabels = await adminPage.$$eval('input[name="roles[]"]', (boxes) => boxes.map((b) => document.querySelector(`label[for="${b.id}"]`)?.textContent.replace(/\s+/g, " ").trim() ?? ""));
      const role = pickRole(roleLabels);
      if (!role) throw new Error(`no role to give ${account.email}: ${roleLabels.join(" | ")}`);
      account.role = role;
      await adminPage.evaluate((a, roleLabel) => {
        const set = (name, value) => { const el = document.querySelector(`input[name="${name}"]`); el.value = value; el.dispatchEvent(new Event("input", { bubbles: true })); };
        set("name", a.name); set("email", a.email); set("password", a.password); set("password_confirmation", a.password);
        for (const box of document.querySelectorAll('input[name="roles[]"]')) {
          box.checked = (document.querySelector(`label[for="${box.id}"]`)?.textContent.replace(/\s+/g, " ").trim() ?? "") === roleLabel;
        }
      }, account, role);
      const userSaved = await Promise.all([adminPage.waitForNavigation({ waitUntil: "networkidle2", timeout: 90000 }), adminPage.click('form[action$="/system/users"] button[type="submit"]')]).then(() => screenState(adminPage));
      check("J", `the throw-away user "${account.name}" (role: ${role}) is created through the Users screen`, userSaved.success.length === 1 && userSaved.errors.length === 0, JSON.stringify(userSaved));
      created.userEmails.push(account.email);
    }
  });

  // ---- A: create the first profile through the real form --------------------------------------------------------------
  await step("A create", async () => {
    const r = await createProfile(DATA.A, { photo: fixture("portrait-a.jpg") });
    check("A", "saving a new profile (Bangla and English, every field, a photo) succeeds with one success message", saved(r), JSON.stringify(r));
    const row = await findProfile(DATA.A.slug);
    ids.A = row?.id ?? null;
    check("A", "the profile is listed under the slug that was typed", row !== null && ids.A !== null, JSON.stringify(row));

    await openEdit(ids.A);
    const form = await readForm(adminPage);
    const same = Object.entries(DATA.A).every(([k, v]) => form[k] === v);
    check("A", "reopening the profile shows every typed value unchanged (newlines, the bullet sign and the ampersand included)", same, JSON.stringify(Object.entries(DATA.A).filter(([k, v]) => form[k] !== v).map(([k]) => [k, form[k]])));
    check("A", "a profile made by the Super Admin is saved as inactive and not featured until someone publishes it", form.status === "inactive" && form.featured === false, `${form.status}/${form.featured}`);

    // E (before): an inactive profile does not exist for the public.
    const bn = await http(profileUrl(slugA));
    const en = await http(profileUrl(slugA, true));
    const one = await api(apiProfile(slugA));
    const list = await api("/api/v1/public/leadership");
    check("E", "while inactive: the Bangla page, the English page and the API detail all answer 404, and the list does not carry it", bn.status === 404 && en.status === 404 && one.status === 404 && !list.text.includes(slugA), `${bn.status}/${en.status}/${one.status}`);
  });

  // ---- E + K/L basics: publish -------------------------------------------------------------------------------------------
  await step("E publish", async () => {
    const r = await rowAction(slugA, "status");
    check("E", "Activate in the row menu succeeds with a success message", r.success.length === 1 && r.danger.length === 0, JSON.stringify(r));
    const bn = await measure("E activate (bn profile)", r, profileUrl(slugA), (h, s) => s === 200 && h.includes(DATA.A.name_bn));
    const en = await measure("E activate (en profile)", r, profileUrl(slugA, true), (h, s) => s === 200 && h.includes(DATA.A.name_en));
    const idx = await measure("E activate (bn index)", r, `${SITE}/leadership`, has(DATA.A.name_bn));
    const idxEn = await measure("E activate (en index)", r, `${SITE}/en/leadership`, has(DATA.A.name_en));
    check("E", "once activated, the profile reaches the Bangla page, the English page and both indexes without a refresh or a redeploy", bn.ok && en.ok && idx.ok && idxEn.ok, JSON.stringify({ bn: bn.ms, en: en.ms, idx: idx.ms, idxEn: idxEn.ms }));
    const home = await http(`${SITE}/`);
    check("E", "an active profile that is not featured stays off the homepage", !home.text.includes(`/leadership/${slugA}`));
  });

  // ---- D: the photo as the public gets it -----------------------------------------------------------------------------------
  await step("D photo", async () => {
    const one = await api(apiProfile(slugA));
    const photo = one.json?.data?.photo;
    check("D", "the API carries a photo object with a page-size and a card-size address and their dimensions", photo && photo.url && photo.sm_url && photo.width > 0 && photo.height > 0, JSON.stringify(photo));
    const page = await fetchImage(photo.url);
    const card = await fetchImage(photo.sm_url);
    check("D", "both copies are served (200, image/jpeg) from the uploads folder of the admin domain", page.status === 200 && card.status === 200 && /image\/jpeg/.test(page.type) && /image\/jpeg/.test(card.type) && photo.url.includes("/storage/leadership/"), `${page.status} ${page.type} / ${card.status} ${card.type} ${photo.url}`);
    check("D", "the page copy is at most 1200 px on its longest side, the card copy at most 480 px, both progressive JPEGs without an EXIF block", longest(page.info) <= 1200 && longest(page.info) > 480 && longest(card.info) <= 480 && page.info?.progressive && card.info?.progressive && !page.info?.exif && !card.info?.exif, JSON.stringify([page.info, card.info]));
    const sizes = { page: page.bytes.length, card: card.bytes.length };
    check("D", "the card copy is smaller than the page copy and both are lighter than the 1200x1500 original", sizes.card < sizes.page, JSON.stringify(sizes));

    const view = await viewPublic(profileUrl(slugA).replace(SITE, ""));
    const facts = await profileFacts(view.page);
    check("D", "the profile page shows the photo with the person's name as its alt text", facts.photoSrc === photo.url && facts.photoAlt?.includes(DATA.A.name_bn) && facts.brokenImages.length === 0, JSON.stringify({ src: facts.photoSrc, alt: facts.photoAlt }));
    await view.page.close();

    // Different bytes each time: the edge and the server must accept a PNG with transparency, a sideways phone photo and a 4.7 MB photograph.
    let previous = photo;
    const replace = async (label, file, expect) => {
      await openEdit(ids.A);
      await attachPhoto(adminPage, fixture(file));
      const r = await submitForm(adminPage);
      check("D", `${label}: the new photo is accepted`, saved(r), JSON.stringify(r));
      const m = await measure(`D photo ${file}`, r, profileUrl(slugA), (h, s) => s === 200 && !h.includes(previous.url.split("/").pop()) && h.includes("/storage/leadership/"));
      const now = (await api(apiProfile(slugA))).json?.data?.photo;
      const img = now ? await fetchImage(now.url) : null;
      const oldGone = fileGone((await fetchImage(previous.url)).status);
      check("D", `${label}: ${expect.text}`, m.ok && now && now.url !== previous.url && img?.status === 200 && expect.ok(now, img), JSON.stringify({ now, info: img?.info, gps: img?.bytes.includes(Buffer.from("GPS-POSITION-MARKER")) }));
      check("D", `${label}: the previous copy no longer exists on the server (a replaced photo does not pile up)`, oldGone, previous.url);
      previous = now ?? previous;
    };
    await replace("PNG with a transparent band", "portrait-b.png", { text: "it is stored as a progressive JPEG within the size limits", ok: (n, i) => i.info?.progressive && longest(i.info) <= 1200 && n.width <= 1200 });
    await replace("phone photo that says 'rotate 90 degrees' (with a GPS marker inside)", "sideways-exif.jpg", { text: "the picture is stood upright (taller than wide), the GPS marker and every EXIF block are gone", ok: (n, i) => n.height > n.width && i.info.height > i.info.width && !i.info.exif && !i.bytes.includes(Buffer.from("GPS-POSITION-MARKER")) });
    await replace("landscape photograph", "landscape.jpg", { text: "a wide photograph keeps its proportions inside 1200 px", ok: (n, i) => n.width > n.height && n.width <= 1200 && i.info.width > i.info.height });
    await replace("4.7 MB photograph", "big-ok.jpg", { text: "a photograph just under the limit is accepted and reduced to 1200 px", ok: (n, i) => longest(i.info) <= 1200 && i.bytes.length < 1.5 * MiB });
    await replace("second portrait", "portrait-c.jpg", { text: "an ordinary portrait is accepted", ok: (n, i) => i.info?.progressive });

    // Remove it: the page shows the initials, the files are gone.
    await openEdit(ids.A);
    await setFields(adminPage, { remove_photo: true });
    const removed = await submitForm(adminPage);
    check("D", "removing the photo is accepted", saved(removed), JSON.stringify(removed));
    const m = await measure("D photo removed", removed, profileUrl(slugA), (h) => !h.includes("/storage/leadership/") && h.includes("leader-initials"));
    const afterApi = (await api(apiProfile(slugA))).json?.data;
    check("D", "without a photo the API says null and the page shows the initials box, never a broken image", m.ok && afterApi?.photo === null, JSON.stringify(afterApi?.photo));
    check("D", "the removed photo's files are deleted from the server", fileGone((await fetchImage(previous.url)).status) && fileGone((await fetchImage(previous.url.replace(/\.jpg$/, "-sm.jpg"))).status), previous.url);

    // Back to a good portrait for everything that follows (and the screenshots).
    await openEdit(ids.A);
    await attachPhoto(adminPage, fixture("portrait-a.jpg"));
    const again = await submitForm(adminPage);
    check("D", "a photo can be added again after it was removed", saved(again), JSON.stringify(again));
  });

  // ---- B and C: edit one language at a time ------------------------------------------------------------------------------
  await step("B edit Bangla", async () => {
    await openEdit(ids.A);
    const next = { designation_bn: "সম্পাদিত বাংলা পদবি", short_bio_bn: `সম্পাদিত বাংলা সংক্ষিপ্ত পরিচিতি ${TAG}` };
    await setFields(adminPage, next);
    const r = await submitForm(adminPage);
    check("B", "saving a Bangla designation and short biography succeeds", saved(r), JSON.stringify(r));
    const m = await measure("B edit Bangla (bn profile)", r, profileUrl(slugA), has(next.short_bio_bn));
    const idx = await measure("B edit Bangla (bn index)", r, `${SITE}/leadership`, has(next.short_bio_bn));
    const en = await http(profileUrl(slugA, true));
    check("B", "the Bangla page shows the new Bangla text within seconds, and the English page still shows its own, unchanged English text", m.ok && idx.ok && en.text.includes(DATA.A.designation_en) && !en.text.includes(next.short_bio_bn), JSON.stringify({ m: m.ms, idx: idx.ms }));
    DATA.A.designation_bn = next.designation_bn;
    DATA.A.short_bio_bn = next.short_bio_bn;
  });

  await step("C edit English", async () => {
    await openEdit(ids.A);
    const next = { designation_en: "Edited English designation", short_bio_en: `Edited English short biography ${TAG}`, quote_en: "An edited English quote." };
    await setFields(adminPage, next);
    const r = await submitForm(adminPage);
    check("C", "saving an English designation, short biography and quote succeeds", saved(r), JSON.stringify(r));
    const m = await measure("C edit English (en profile)", r, profileUrl(slugA, true), has(next.short_bio_en));
    const idx = await measure("C edit English (en index)", r, `${SITE}/en/leadership`, has(next.short_bio_en));
    const bn = await http(profileUrl(slugA));
    check("C", "the English page shows the new English text within seconds, and the Bangla page is untouched", m.ok && idx.ok && bn.text.includes(DATA.A.short_bio_bn) && !bn.text.includes(next.short_bio_en), JSON.stringify({ m: m.ms, idx: idx.ms }));
    Object.assign(DATA.A, next);
  });

  // ---- Create B (Bangla only) and C (slug from the name), both as photos of other shapes ------------------------------------
  await step("create B and C", async () => {
    const b = await createProfile(DATA.B, { photo: fixture("landscape.jpg") });
    check("A", "a Bangla-only profile (no English text at all) can be saved", saved(b), JSON.stringify(b));
    ids.B = (await findProfile(DATA.B.slug))?.id ?? null;

    const c = await createProfile(DATA.C, { photo: fixture("portrait-b.png") });
    check("A", "with the slug left empty, the address is made from the English name", saved(c), JSON.stringify(c));
    const rowC = await findProfile(slugC);
    ids.C = rowC?.id ?? null;
    check("A", `the made-up address is ${slugC}`, rowC !== null, JSON.stringify(rowC));

    // Activate both: the order they were created in is the order they are shown in.
    for (const slug of [DATA.B.slug, slugC]) {
      const r = await rowAction(slug, "status");
      check("E", `${slug}: Activate succeeds`, r.success.length === 1, JSON.stringify(r));
    }
    const seen = await measure("E activate B and C (bn index)", { startedAt: Date.now() - 1, saveMs: 0 }, `${SITE}/leadership`, (h) => h.includes(DATA.B.name_bn) && h.includes(DATA.C.name_bn));
    check("E", "both reach the Bangla Leadership page", seen.ok, JSON.stringify(seen));
  });

  // ---- G: featured -> homepage -----------------------------------------------------------------------------------------------
  await step("G feature", async () => {
    const r = await rowAction(slugA, "featured");
    check("G", "Feature in the row menu succeeds", r.success.length === 1, JSON.stringify(r));
    const home = await measure("G feature (homepage bn)", r, `${SITE}/`, has(`/leadership/${slugA}`));
    const homeEn = await measure("G feature (homepage en)", r, `${SITE}/en`, has(`/en/leadership/${slugA}`));
    check("G", "a featured, active profile appears in the homepage section (Bangla and English) within seconds", home.ok && homeEn.ok, JSON.stringify({ bn: home.ms, en: homeEn.ms }));

    const view = await viewPublic("/");
    const section = await view.page.evaluate(() => ({
      heading: document.querySelector("#leadership-home-title")?.textContent ?? null,
      link: [...document.querySelectorAll(".home-leadership .text-link")].map((a) => a.getAttribute("href")),
      cards: [...document.querySelectorAll(".home-leadership .leader-card-link")].map((a) => a.getAttribute("href")),
    }));
    check("G", "the homepage section has a heading, a link to the full Leadership page, and at most four cards", section.heading && section.link.includes("/leadership") && section.cards.length >= 1 && section.cards.length <= 4, JSON.stringify(section));
    await view.page.close();

    // Not featured -> not on the homepage; the profile stays public.
    const off = await rowAction(slugA, "featured");
    const gone1 = await measure("G unfeature (homepage bn)", off, `${SITE}/`, lacks(`/leadership/${slugA}`));
    const stillThere = await http(profileUrl(slugA));
    check("G", "Unfeature removes it from the homepage but not from the Leadership page or its own page", gone1.ok && stillThere.status === 200, JSON.stringify(gone1));
    // A and C are featured for the order and screenshot checks that follow.
    await rowAction(slugA, "featured");
    await rowAction(slugC, "featured");
  });

  // ---- H: order ------------------------------------------------------------------------------------------------------------------
  await step("H order", async () => {
    const wanted = (list) => list.filter((s) => [slugA, DATA.B.slug, slugC].includes(s));
    let admin = wanted(await adminOrder());
    check("H", "created A, B, C in that order: the admin list shows A, B, C", JSON.stringify(admin) === JSON.stringify([slugA, DATA.B.slug, slugC]), JSON.stringify(admin));
    const apiOrder = (await api("/api/v1/public/leadership")).json?.data?.map((p) => p.slug).filter((s) => admin.includes(s));
    check("H", "the public API lists them in the same order", JSON.stringify(apiOrder) === JSON.stringify(admin), JSON.stringify(apiOrder));

    // Featured ones come first on the page (a group of their own), each group in display order: A(featured) C(featured) | B.
    const idx = await http(`${SITE}/leadership`);
    check("H", "the Bangla page shows the featured group first (A, C) then the others (B), each in the admin's order", JSON.stringify(qaOrderIn(idx.text)) === JSON.stringify([slugA, slugC, DATA.B.slug]), JSON.stringify(qaOrderIn(idx.text)));

    const first = await moveRow(slugC, "up");
    const second = await moveRow(slugC, "up");
    check("H", "Move up twice succeeds", first.danger.length === 0 && second.danger.length === 0, JSON.stringify([first, second]));
    admin = wanted(await adminOrder());
    check("H", "after moving C up twice the admin list shows C, A, B", JSON.stringify(admin) === JSON.stringify([slugC, slugA, DATA.B.slug]), JSON.stringify(admin));
    const m = await measure("H reorder (bn index)", second, `${SITE}/leadership`, (h) => JSON.stringify(qaOrderIn(h)) === JSON.stringify([slugC, slugA, DATA.B.slug]));
    const mEn = await measure("H reorder (en index)", second, `${SITE}/en/leadership`, (h) => JSON.stringify(qaOrderIn(h, true)) === JSON.stringify([slugC, slugA, DATA.B.slug]));
    const apiNow = (await api("/api/v1/public/leadership")).json?.data?.map((p) => p.slug).filter((s) => admin.includes(s));
    check("H", "the new order reaches both language pages and the API within seconds", m.ok && mEn.ok && JSON.stringify(apiNow) === JSON.stringify(admin), JSON.stringify({ m: m.ms, en: mEn.ms, apiNow }));

    // Featuring B must not change the display order: it only moves B into the featured group, at its own place.
    const featuredB = await rowAction(DATA.B.slug, "featured");
    admin = wanted(await adminOrder());
    const fm = await measure("H feature B (bn index)", featuredB, `${SITE}/leadership`, (h) => JSON.stringify(qaOrderIn(h)) === JSON.stringify([slugC, slugA, DATA.B.slug]));
    const view = await viewPublic("/leadership");
    const groups = await view.page.evaluate(() => ({ featured: [...document.querySelectorAll(".leader-grid.is-featured .leader-card-link")].map((a) => a.getAttribute("href")), others: [...document.querySelectorAll(".leader-grid:not(.is-featured) .leader-card-link")].map((a) => a.getAttribute("href")) }));
    await view.page.close();
    check("H", "featuring B changes no display order: the admin order is still C, A, B and B simply joins the featured group, last", JSON.stringify(admin) === JSON.stringify([slugC, slugA, DATA.B.slug]) && fm.ok && JSON.stringify(groups.featured.filter((h) => h.includes(slugBase))) === JSON.stringify([slugC, slugA, DATA.B.slug].map((s) => `/leadership/${s}`)), JSON.stringify({ admin, groups }));
    await rowAction(DATA.B.slug, "featured"); // B is not featured again
    check("H", "ordering is unchanged by the second toggle too", JSON.stringify(wanted(await adminOrder())) === JSON.stringify([slugC, slugA, DATA.B.slug]));
  });

  // ---- F: deactivate -----------------------------------------------------------------------------------------------------------------
  await step("F deactivate", async () => {
    const r = await rowAction(slugC, "status");
    check("F", "Deactivate in the row menu succeeds", r.success.length === 1, JSON.stringify(r));
    const bn = await measure("F deactivate (bn profile 404)", r, profileUrl(slugC), gone);
    const en = await measure("F deactivate (en profile 404)", r, profileUrl(slugC, true), gone);
    const idx = await measure("F deactivate (bn index)", r, `${SITE}/leadership`, lacks(`/leadership/${slugC}"`));
    const home = await measure("F deactivate (homepage)", r, `${SITE}/`, lacks(`/leadership/${slugC}"`));
    const one = await api(apiProfile(slugC));
    const list = await api("/api/v1/public/leadership");
    check("F", "a deactivated profile disappears from the Bangla page, the English page, the index, the homepage and the API within seconds", bn.ok && en.ok && idx.ok && home.ok && one.status === 404 && !list.text.includes(slugC), JSON.stringify({ bn: bn.ms, en: en.ms, idx: idx.ms, home: home.ms, api: one.status }));
    const unknown = await api(apiProfile(`${slugBase}-never-existed`));
    check("F", "asking for an inactive profile gives exactly the answer an unknown address gives (nothing reveals that it exists)", unknown.status === 404 && one.text === unknown.text, `${one.text.slice(0, 80)} | ${unknown.text.slice(0, 80)}`);
    const back = await rowAction(slugC, "status");
    const reachable = await measure("F reactivate (bn profile)", back, profileUrl(slugC), (h, s) => s === 200 && h.includes(DATA.C.name_bn));
    check("F", "Activate brings it back", back.success.length === 1 && reachable.ok, JSON.stringify(reachable));
  });

  // ---- Q: an old address keeps working -----------------------------------------------------------------------------------------------
  await step("Q slug", async () => {
    const newSlug = `${slugA}-renamed`;
    await openEdit(ids.A);
    await setFields(adminPage, { slug: newSlug });
    const r = await submitForm(adminPage);
    check("Q", "changing a profile's web address is accepted", saved(r), JSON.stringify(r));
    const fresh = await measure("Q slug change (new address)", r, profileUrl(newSlug), (h, s) => s === 200 && h.includes(DATA.A.name_bn));
    const bnOld = await http(profileUrl(slugA), { redirect: "manual" });
    const enOld = await http(profileUrl(slugA, true), { redirect: "manual" });
    check("Q", "the old Bangla address answers with a permanent redirect to the new one, and so does the old English address", fresh.ok && [301, 308].includes(bnOld.status) && (bnOld.location ?? "").endsWith(`/leadership/${newSlug}`) && [301, 308].includes(enOld.status) && (enOld.location ?? "").endsWith(`/en/leadership/${newSlug}`), JSON.stringify({ bn: [bnOld.status, bnOld.location], en: [enOld.status, enOld.location] }));
    const oldApi = await api(apiProfile(slugA));
    check("Q", "the API resolves the old address to the profile under its current address", oldApi.status === 200 && oldApi.json?.data?.slug === newSlug, `${oldApi.status} ${oldApi.json?.data?.slug}`);
    const followed = await http(profileUrl(slugA));
    check("Q", "a visitor who follows the old link lands on the profile page", followed.status === 200 && followed.text.includes(DATA.A.name_bn) && followed.url.endsWith(`/leadership/${newSlug}`), followed.url);

    // Another profile that asks for the address A gave up is given a numbered address instead (and told), never A's old one.
    await openEdit(ids.B);
    await setFields(adminPage, { slug: slugA });
    const taken = await submitForm(adminPage);
    await openEdit(ids.B);
    const formB = await readForm(adminPage);
    check("Q", "another profile that asks for an address that used to belong to this one gets a numbered address and a notice, never the old address", saved(taken) && taken.warning.length === 1 && taken.warning[0].includes(slugA) && formB.slug !== slugA && formB.slug.startsWith(`${slugA}-`), JSON.stringify({ warning: taken.warning, slug: formB.slug }));
    const stillOwn = await http(profileUrl(slugA), { redirect: "manual" });
    check("Q", "and the old address still goes to its own profile afterwards", [301, 308].includes(stillOwn.status) && (stillOwn.location ?? "").endsWith(`/leadership/${newSlug}`), `${stillOwn.status} ${stillOwn.location}`);
    // B goes back to the address it had (it may take its own earlier address back), without any notice.
    await setFields(adminPage, { slug: DATA.B.slug });
    const bBack = await submitForm(adminPage);
    check("Q", "that profile can take its own first address back, and no notice is shown for an address that was free", saved(bBack) && bBack.warning.length === 0, JSON.stringify(bBack));
    await measure("Q slug B back", bBack, profileUrl(DATA.B.slug), (h, s) => s === 200 && h.includes(DATA.B.name_bn));

    // The profile can have its first address back.
    await openEdit(ids.A);
    await setFields(adminPage, { slug: slugA });
    const back = await submitForm(adminPage);
    check("Q", "a profile can take its own earlier address back", saved(back), JSON.stringify(back));
    const reclaimed = await measure("Q slug back", back, profileUrl(slugA), (h, s) => s === 200 && h.includes(DATA.A.name_bn));
    const viaRenamed = await http(profileUrl(newSlug), { redirect: "manual" });
    check("Q", "the address it had in between now redirects to the restored one", reclaimed.ok && [301, 308].includes(viaRenamed.status) && (viaRenamed.location ?? "").endsWith(`/leadership/${slugA}`), JSON.stringify([viaRenamed.status, viaRenamed.location]));
  });

  // ---- R: archive / restore ----------------------------------------------------------------------------------------------------------------
  await step("R archive", async () => {
    const r = await rowAction(slugC, "archive");
    check("R", "Archive (with its confirmation) succeeds", r.success.length === 1, JSON.stringify(r));
    const gone1 = await measure("R archive (bn profile 404)", r, profileUrl(slugC), gone);
    const home = await measure("R archive (homepage)", r, `${SITE}/`, lacks(`/leadership/${slugC}"`));
    check("R", "an archived profile disappears from the public site, its page and the API", gone1.ok && home.ok && (await api(apiProfile(slugC))).status === 404, JSON.stringify({ gone: gone1.ms, home: home.ms }));
    await adminPage.goto(`${ADMIN}/admin/leadership`, { waitUntil: "networkidle2", timeout: 90000 });
    const hidden = (await rowHandle(slugC)) === null;
    const filtered = await findProfile(slugC, { archived: true });
    check("R", "the default admin list hides it and the Archived filter shows it", hidden && filtered !== null, JSON.stringify(filtered));
    const noDelete = await adminPage.evaluate(() => document.querySelectorAll('form[action*="/admin/leadership/"][method="POST"] input[name="_method"][value="DELETE"]').length);
    check("R", "there is no way to delete a profile permanently in the admin screens", noDelete === 0);
    // The Restore action lives in the Archived filter of the list.
    const restored = await rowAction(slugC, "restore", `/admin/leadership?status=archived&q=${slugC}`);
    check("R", "Restore works and brings the profile back as inactive (never straight to public)", restored.success.length === 1 && (await http(profileUrl(slugC))).status === 404, JSON.stringify(restored));
    const act = await rowAction(slugC, "status");
    check("R", "an administrator then activates it again deliberately", act.success.length === 1 && (await measure("R reactivate", act, profileUrl(slugC), (h, s) => s === 200)).ok);
    // C is featured again for the homepage and screenshot checks that follow (archiving had unfeatured it).
    await rowAction(slugC, "featured");
  });

  // ---- I: invalid input is refused, field by field, by the SERVER ------------------------------------------------------------------------
  await step("I invalid input", async () => {
    const base = (n) => ({ slug: `${slugBase}-bad${n}`, name_bn: `${ver()} bad ${n}`, designation_bn: "পরীক্ষামূলক" });
    const before = await api("/api/v1/public/leadership");
    const BAD = [
      ["facebook_url", "javascript:alert(1)", "a javascript: address"],
      ["facebook_url", "http://www.facebook.com/qa", "plain http"],
      ["facebook_url", "https://evil.example.com/www.facebook.com/qa", "another host dressed up as Facebook"],
      ["facebook_url", "https://user:pw@www.facebook.com/qa", "credentials inside the address"],
      ["linkedin_url", "https://www.facebook.com/in/qa", "another platform's host in the LinkedIn field"],
      ["youtube_url", "data:text/html,<script>alert(1)</script>", "a data: address"],
      ["website_url", "http://example.org/", "a plain http website"],
      ["website_url", "https://127.0.0.1/admin", "an IP address"],
      ["website_url", "https://intranet.local/x", "an internal host name"],
      ["public_email", "admin@provatferi.org", "the organisation's internal mailbox"],
      ["public_email", "qa%0d%0abcc@example.org", "an address that could smuggle a mail header"],
      ["public_phone", "call me maybe", "words in the phone field"],
      ["slug", "../../etc/passwd", "a path in the web address"],
      ["slug", "UPPER Case", "capitals and a space in the web address"],
      ["name_en", "<script>window.__xss=1</script>", "a script tag in a name"],
      ["short_bio_en", "Hello <img src=x onerror=alert(1)>", "markup in the short biography"],
      ["full_bio_en", "Text <a href=\"javascript:alert(1)\">click</a>", "a link tag in the full biography"],
      ["associated_since_year", "1800", "a year before 1900"],
      ["display_order", "-5", "a negative display order"],
    ];
    let leaked = 0;
    for (const [n, [field, value, what]] of BAD.entries()) {
      const values = { ...base(n), [field]: value };
      const r = await createProfile(values, { noValidate: true });
      const flagged = r.invalidFields.includes(field);
      check("I", `refused by the server: ${what} (${field})`, flagged && r.success.length === 0 && r.path.includes("/admin/leadership/create"), JSON.stringify({ path: r.path, invalid: r.invalidFields, errors: r.errors.slice(0, 2) }));
      if ((await findProfile(values.slug))?.id) leaked++;
    }
    const now = await api("/api/v1/public/leadership");
    check("I", "none of the refused forms created a profile (admin list and public API unchanged)", leaked === 0 && JSON.stringify(now.json?.data?.map((p) => p.slug)) === JSON.stringify(before.json?.data?.map((p) => p.slug)), `leaked ${leaked}`);

    // What IS allowed: the same fields with the correct kind of value, in one go.
    const ok = await createProfile({ ...base(99), facebook_url: "https://www.facebook.com/valid.page", linkedin_url: "https://www.linkedin.com/company/valid", youtube_url: "https://www.youtube.com/channel/UC_valid", website_url: "https://www.example.org/valid", public_email: "valid.person@example.org", public_phone: "01711-000003", associated_since_year: String(new Date().getFullYear()) });
    check("I", "the same fields with correct values are accepted (the refusals above are about the values, not the fields)", saved(ok), JSON.stringify(ok));
  });

  // ---- S: injection, forged fields, hostile uploads ----------------------------------------------------------------------------------------------
  await step("S hardening", async () => {
    // Entities typed by a person are text: they must come out on the page as the characters typed, never as markup.
    await openEdit(ids.A);
    const typed = { short_bio_en: `Entities stay text: &lt;b&gt;bold&lt;/b&gt; &amp; "quotes" ${TAG}`, full_bio_en: "Plain text with &lt;script&gt;alert(1)&lt;/script&gt; inside. See https://example.org/qa-link for more.\n\n• bullet one\n• bullet two" };
    await setFields(adminPage, typed);
    const r = await submitForm(adminPage);
    check("S", "text that merely looks like markup (written as entities) is accepted as plain text", saved(r), JSON.stringify(r));
    await measure("S entities", r, profileUrl(slugA, true), has(TAG));
    const view = await viewPublic(profileUrl(slugA, true).replace(SITE, ""));
    const facts = await profileFacts(view.page);
    const text = facts.bio.join(" ");
    check("S", "the page shows the typed characters literally (no bold element, no script element, nothing executed)", text.includes("&lt;script&gt;alert(1)&lt;/script&gt;") && !facts.bioHtmlTags.includes("script") && !facts.bioHtmlTags.includes("b") && facts.scriptsInMain === 0, JSON.stringify({ text: text.slice(0, 120), tags: [...new Set(facts.bioHtmlTags)] }));
    check("S", "a block of bullet lines becomes a list, and a bare web address becomes one safe link", facts.bioHtmlTags.includes("li") && facts.bioLinks.length === 1 && facts.bioLinks[0] === "https://example.org/qa-link" && facts.bioLinkRel.every((r) => /noopener/.test(r ?? "")), JSON.stringify({ tags: [...new Set(facts.bioHtmlTags)], links: facts.bioLinks, rel: facts.bioLinkRel }));
    await view.page.close();
    await openEdit(ids.A);
    const form = await readForm(adminPage);
    check("S", "the edit form gives the same text back byte for byte (nothing is escaped twice)", form.short_bio_en === typed.short_bio_en && form.full_bio_en === typed.full_bio_en, JSON.stringify([form.short_bio_en, form.full_bio_en]));
    DATA.A.short_bio_en = typed.short_bio_en;
    DATA.A.full_bio_en = typed.full_bio_en;

    // Forged fields: a client that sends fields the screen never offers.
    await openEdit(ids.B);
    await injectFields(adminPage, { id: "999999", created_by: "1", updated_by: "1", photo_path: "../../../etc/passwd", photo_sm_path: "x", photo_width: "99999", photo_height: "99999", created_at: "2001-01-01 00:00:00", leadership_profile_id: "1" });
    await setFields(adminPage, { designation_bn: "জাল ক্ষেত্র পরীক্ষা" });
    const forged = await submitForm(adminPage);
    check("S", "a form carrying fields it was never offered (id, created_by, photo_path, photo_width ...) still saves only the real ones", saved(forged), JSON.stringify(forged));
    DATA.B.designation_bn = "জাল ক্ষেত্র পরীক্ষা";
    const bApi = (await api(apiProfile(DATA.B.slug))).json?.data;
    const stillBPhoto = bApi?.photo ? await fetchImage(bApi.photo.url) : null;
    check("S", "no forged value reached the record: the photo is still the real one and the address is unchanged", bApi?.slug === DATA.B.slug && bApi?.photo?.width <= 1200 && stillBPhoto?.status === 200 && !JSON.stringify(bApi).includes("passwd"), JSON.stringify(bApi?.photo));

    // Hostile uploads, all refused with a message on the photo field, none stored.
    const files = [["evil.jpg", "PHP code with a .jpg name"], ["logo.svg", "an SVG with a script inside"], ["notes.png", "text pretending to be a .png"], ["tiny.jpg", "a picture smaller than 300 px"], ["big-too-large.jpg", "a 5.6 MB photograph (over the 5 MB limit)"]];
    const filesBefore = (await api("/api/v1/public/leadership")).json?.data?.length ?? 0;
    for (const [file, what] of files) {
      const values = { slug: `${slugBase}-up${file.replace(/\W/g, "")}`.slice(0, 60), name_bn: `${ver()} upload ${file}`, designation_bn: "পরীক্ষামূলক" };
      const res = await createProfile(values, { photo: fixture(file) });
      check("S", `refused: ${what} (${file})`, res.invalidFields.includes("photo") && res.success.length === 0 && res.path.includes("/admin/leadership/create"), JSON.stringify({ path: res.path, invalid: res.invalidFields, errors: res.errors.slice(0, 2) }));
      if ((await findProfile(values.slug))?.id) check("S", `nothing was stored for ${file}`, false);
    }
    check("S", "no refused upload became a profile or a public file", (await api("/api/v1/public/leadership")).json?.data?.length === filesBefore);

    // CSRF: a state-changing request without the token is refused.
    await adminPage.goto(`${ADMIN}/admin/leadership`, { waitUntil: "networkidle2", timeout: 90000 });
    const csrf = await adminPage.evaluate(async (id) => {
      const r = await fetch(`/admin/leadership/${id}/status`, { method: "POST", headers: { "X-Requested-With": "XMLHttpRequest", Accept: "text/html" }, body: new URLSearchParams({ _method: "PATCH" }) });
      return r.status;
    }, ids.A);
    check("S", "a status change sent without the CSRF token is refused (419) and changes nothing", csrf === 419 && (await http(profileUrl(slugA))).status === 200, String(csrf));
  });

  // ---- J: access control ----------------------------------------------------------------------------------------------------------------------------
  const asUser = async (account, fn) => {
    const context = await browser.createBrowserContext();
    const page = await context.newPage();
    await page.setViewport({ width: 1366, height: 900 });
    page.on("dialog", (d) => d.accept().catch(() => {}));
    try {
      const ok = await login(page, account.email, account.password);
      return await fn(page, ok);
    } finally {
      await context.close();
    }
  };
  const csrfToken = (page) => page.evaluate(() => document.querySelector('input[name="_token"]')?.value ?? document.querySelector('meta[name="csrf-token"]')?.content ?? null);
  const post = async (page, path, method = "POST", fields = {}) => {
    const token = await csrfToken(page);
    return page.evaluate(async (p, m, f, t) => {
      const body = new URLSearchParams({ _token: t, ...f, ...(m === "POST" ? {} : { _method: m }) });
      const r = await fetch(p, { method: "POST", headers: { "X-Requested-With": "XMLHttpRequest", Accept: "text/html" }, body, redirect: "manual" });
      return r.status;
    }, path, method, fields, token);
  };

  await step("J no-access user", async () => {
    await asUser(nobody, async (page, ok) => {
      check("J", `the user with the role "${nobody.role}" (no Leadership permission) can sign in`, ok);
      const menu = await page.evaluate(() => [...document.querySelectorAll(".side-nav a")].map((a) => a.getAttribute("href")));
      check("J", "its side menu has no Leadership entry", !menu.some((h) => h?.includes("/admin/leadership")), menu.join(","));
      const statuses = {};
      for (const [label, path] of [["list", "/admin/leadership"], ["create form", "/admin/leadership/create"], ["edit form", `/admin/leadership/${ids.A}/edit`], ["preview", `/admin/leadership/${ids.A}/preview`]]) {
        statuses[label] = (await page.goto(`${ADMIN}${path}`, { waitUntil: "networkidle2", timeout: 90000 }))?.status();
      }
      check("J", "typing the Leadership addresses by hand is refused with 403 (list, create, edit, preview)", Object.values(statuses).every((s) => s === 403), JSON.stringify(statuses));
      await page.goto(`${ADMIN}/admin`, { waitUntil: "networkidle2", timeout: 90000 });
      const writes = {};
      writes.store = await post(page, "/admin/leadership", "POST", { name_bn: `${ver()} forged`, designation_bn: "x", status: "active" });
      writes.update = await post(page, `/admin/leadership/${ids.A}`, "PUT", { name_bn: "hijacked", designation_bn: "hijacked" });
      writes.status = await post(page, `/admin/leadership/${ids.A}/status`, "PATCH");
      writes.featured = await post(page, `/admin/leadership/${ids.A}/featured`, "PATCH");
      writes.moveUp = await post(page, `/admin/leadership/${ids.A}/move-up`, "POST");
      writes.archive = await post(page, `/admin/leadership/${ids.A}/archive`, "POST");
      check("J", "every write (create, update, status, feature, move, archive) sent by hand with a valid token is refused with 403", Object.values(writes).every((s) => s === 403), JSON.stringify(writes));
    });
    const a = await api(apiProfile(slugA));
    check("J", "and profile A is exactly as it was", a.status === 200 && a.json?.data?.name_bn === DATA.A.name_bn && a.json?.data?.featured === true, `${a.status}`);
  });

  await step("J editor", async () => {
    await asUser(editor, async (page, ok) => {
      check("J", `the editor user (role: ${editor.role}) can sign in`, ok);
      const menu = await page.evaluate(() => [...document.querySelectorAll(".side-nav a")].map((a) => a.getAttribute("href")));
      check("J", "its side menu shows Leadership", menu.some((h) => h?.endsWith("/admin/leadership")));
      const list = await page.goto(`${ADMIN}/admin/leadership`, { waitUntil: "networkidle2", timeout: 90000 });
      const listFacts = await page.evaluate(() => ({ newButton: !!document.querySelector('a[href$="/admin/leadership/create"]'), publishForms: document.querySelectorAll('form[action$="/status"], form[action$="/featured"], form[action$="/move-up"], form[action$="/move-down"]').length, archiveButtons: document.querySelectorAll('button[data-bs-target^="#archive-profile-"]').length, rows: document.querySelectorAll("tbody tr").length }));
      check("J", "it can open the list and sees New profile, but no activate, feature, reorder or archive control", list?.status() === 200 && listFacts.newButton && listFacts.publishForms === 0 && listFacts.archiveButtons === 0, JSON.stringify(listFacts));

      await page.goto(`${ADMIN}/admin/leadership/create`, { waitUntil: "networkidle2", timeout: 90000 });
      const controls = await page.evaluate(() => ({ status: !!document.querySelector('[name="status"]'), featured: !!document.querySelector('[name="featured"]'), order: !!document.querySelector('[name="display_order"]'), name: !!document.querySelector('[name="name_bn"]') }));
      check("J", "its create form has the content fields but no status, feature or display-order control", controls.name && !controls.status && !controls.featured && !controls.order, JSON.stringify(controls));

      // Create D, with a forged attempt to publish it on the way.
      await setFields(page, DATA.D);
      await attachPhoto(page, fixture("portrait-c.jpg"));
      await injectFields(page, { status: "active", featured: "1", display_order: "1" });
      const made = await submitForm(page);
      check("J", "it can create a profile, even when the request is forged to say status=active, featured=1, display_order=1", saved(made), JSON.stringify(made));
      const rowD = await findProfile(DATA.D.slug, { page });
      ids.D = rowD?.id ?? null;
      check("J", "the profile exists", ids.D !== null, JSON.stringify(rowD));

      // Edit again with forged publication fields.
      await openEdit(ids.D, page);
      await setFields(page, { short_bio_en: "Edited by the editor." });
      await injectFields(page, { status: "active", featured: "1", display_order: "1" });
      const edited = await submitForm(page);
      check("J", "it can edit its profile, and a forged status/featured/display_order is ignored again", saved(edited), JSON.stringify(edited));

      const statuses = {};
      statuses.status = await post(page, `/admin/leadership/${ids.D}/status`, "PATCH");
      statuses.featured = await post(page, `/admin/leadership/${ids.D}/featured`, "PATCH");
      statuses.moveUp = await post(page, `/admin/leadership/${ids.D}/move-up`, "POST");
      statuses.archive = await post(page, `/admin/leadership/${ids.D}/archive`, "POST");
      check("J", "activate, feature, move and archive sent by hand are refused with 403 for it", Object.values(statuses).every((s) => s === 403), JSON.stringify(statuses));
      const users = (await page.goto(`${ADMIN}/admin/system/users`, { waitUntil: "networkidle2", timeout: 90000 }))?.status();
      check("J", "the editor role gives no access to the Users screen either (only what was granted)", users === 403, String(users));
    });

    // What the Super Admin sees: D is inactive and not featured, and the public site has never heard of it.
    await openEdit(ids.D);
    const formD = await readForm(adminPage);
    check("J", "profile D (made and edited by the editor) is inactive, not featured, with the order the system gave it", formD.status === "inactive" && formD.featured === false && formD.display_order !== "1", JSON.stringify({ status: formD.status, featured: formD.featured, order: formD.display_order }));
    const dBn = await http(profileUrl(DATA.D.slug));
    const dApi = await api(apiProfile(DATA.D.slug));
    const list = await api("/api/v1/public/leadership");
    check("J", "so it is invisible to the public: 404 on its page, 404 in the API, absent from the list", dBn.status === 404 && dApi.status === 404 && !list.text.includes(DATA.D.slug), `${dBn.status}/${dApi.status}`);
  });

  await step("J anonymous", async () => {
    const context = await browser.createBrowserContext();
    const page = await context.newPage();
    const r = await page.goto(`${ADMIN}/admin/leadership`, { waitUntil: "networkidle2", timeout: 90000 });
    check("J", "a visitor who is not signed in is sent to the login page", page.url().includes("/login") && (r?.status() ?? 0) < 400, page.url());
    const direct = await http(`${ADMIN}/admin/leadership/${ids.A}/edit`, { redirect: "manual" });
    check("J", "and cannot reach an edit form by its address (redirect to login, never the form)", [301, 302, 303, 307, 308].includes(direct.status) && (direct.location ?? "").includes("/login"), `${direct.status} ${direct.location}`);
    await context.close();
  });

  // ---- K and L: individual profile pages, Bangla and English --------------------------------------------------------------------------------------
  await step("K/L pages", async () => {
    // Canonical and alternate addresses are compared by PATH: the site names its own public origin (metadataBase), which on a
    // local pair is not the address the browser used.
    const path = (u) => { try { return new URL(u, SITE).pathname; } catch { return String(u); } };
    const bn = await viewPublic(`/leadership/${slugA}`);
    const f = await profileFacts(bn.page);
    check("K", "Bangla profile page: html lang=bn, the name as the heading, the designation under it, a breadcrumb Home / Leadership / name", f.lang === "bn" && f.h1 === DATA.A.name_bn && f.designation === DATA.A.designation_bn && f.breadcrumb.join(" ").includes(DATA.A.name_bn) && bn.status === 200, JSON.stringify({ lang: f.lang, h1: f.h1, d: f.designation, bc: f.breadcrumb }));
    check("K", "it shows the Bangla quote, full biography, expertise and the year, and no translation note", f.quote === DATA.A.quote_bn && f.bio.join(" ").includes("প্রথম অনুচ্ছেদ (QA)") && f.facts.join(" ").includes(DATA.A.expertise_bn) && f.note.length === 0, JSON.stringify({ quote: f.quote, facts: f.facts, notes: f.note }));
    check("K", "the contact card holds only what was entered: the e-mail, the phone, the website, and the three social links with rel=noopener noreferrer", f.contactLinks.includes(`mailto:${DATA.A.public_email}`) && f.contactLinks.some((h) => h.startsWith("tel:")) && f.contactLinks.includes(DATA.A.website_url) && [DATA.A.facebook_url, DATA.A.linkedin_url, DATA.A.youtube_url].every((u) => f.socialLinks.includes(u)) && f.socialRel.every((r) => /noopener/.test(r ?? "") && /noreferrer/.test(r ?? "")), JSON.stringify({ contact: f.contactLinks, social: f.socialLinks, rel: f.socialRel }));
    check("K", "metadata: title is name — designation, the description is the short biography, canonical is the Bangla address, hreflang lists bn, en and x-default", f.title.includes(DATA.A.name_bn) && f.title.includes(DATA.A.designation_bn) && f.description?.includes(DATA.A.short_bio_bn.slice(0, 30)) && path(f.canonical) === `/leadership/${slugA}` && ["bn", "en", "x-default"].every((l) => f.alternates.some(([h]) => h === l)), JSON.stringify({ title: f.title, desc: f.description, canonical: f.canonical, alt: f.alternates }));
    check("K", "Open Graph: type, title, description, url, locale and an image", f.og["og:title"] && f.og["og:description"] && path(f.og["og:url"]) === `/leadership/${slugA}` && f.og["og:image"] && /bn/i.test(f.og["og:locale"] ?? ""), JSON.stringify(f.og));
    check("K", "no entity text, no broken image, no horizontal scroll, no console error", f.entities.length === 0 && f.brokenImages.length === 0 && !f.horizontalScroll && bn.problems.console.length + bn.problems.failed.length + bn.problems.http.length === 0, JSON.stringify({ entities: f.entities, broken: f.brokenImages, problems: bn.problems }));
    await shot(bn.page, "profile-a-bn-desktop-light");
    await bn.page.close();

    const en = await viewPublic(`/en/leadership/${slugA}`);
    const g = await profileFacts(en.page);
    check("L", "English profile page: html lang=en, the English name and designation, a breadcrumb in English", g.lang === "en" && g.h1 === DATA.A.name_en && g.designation === DATA.A.designation_en && g.breadcrumb.join(" ").includes("Leadership"), JSON.stringify({ lang: g.lang, h1: g.h1, d: g.designation, bc: g.breadcrumb }));
    check("L", "it shows the English quote and short biography text, and no translation note (every shown field has English)", g.quote === DATA.A.quote_en && g.note.length === 0, JSON.stringify({ quote: g.quote, notes: g.note }));
    check("L", "metadata: canonical is the English address (a real English page exists), hreflang lists bn, en and x-default, og:locale is English", path(g.canonical) === `/en/leadership/${slugA}` && ["bn", "en", "x-default"].every((l) => g.alternates.some(([h]) => h === l)) && /en/i.test(g.og["og:locale"] ?? ""), JSON.stringify({ canonical: g.canonical, alt: g.alternates, og: g.og["og:locale"] }));
    await shot(en.page, "profile-a-en-desktop-light");
    await en.page.close();

    // B has Bangla only: its English page is a fallback render — Bangla text with the project's note, canonical -> the Bangla page, no English hreflang.
    const enB = await viewPublic(`/en/leadership/${DATA.B.slug}`);
    const b = await profileFacts(enB.page);
    check("L", "a profile with no English text: the English page shows the Bangla name and text with the translation note (nothing is made up)", enB.status === 200 && b.h1 === DATA.B.name_bn && b.note.length >= 1 && b.lang === "en", JSON.stringify({ status: enB.status, h1: b.h1, note: b.note }));
    check("L", "its metadata says so honestly: canonical points at the Bangla page and there is no English alternate", path(b.canonical) === `/leadership/${DATA.B.slug}` && !b.alternates.some(([h]) => h === "en"), JSON.stringify({ canonical: b.canonical, alt: b.alternates }));
    await shot(enB.page, "profile-b-en-fallback-desktop-light");
    await enB.page.close();

    const idxEn = await viewPublic("/en/leadership");
    const noteCount = await idxEn.page.evaluate((slug) => [...document.querySelectorAll(".leader-card")].filter((c) => c.querySelector(`a[href$="/leadership/${slug}"]`)).map((c) => c.querySelector(".translation-note")?.textContent ?? null), DATA.B.slug);
    check("L", "on the English Leadership page the Bangla-only card carries the translation note", noteCount.length === 1 && noteCount[0] !== null, JSON.stringify(noteCount));
    await idxEn.page.close();
  });

  // ---- M: the public API ---------------------------------------------------------------------------------------------------------------------------------
  await step("M api", async () => {
    const LIST_KEYS = ["designation_bn", "designation_en", "expertise_bn", "expertise_en", "featured", "name_bn", "name_en", "photo", "short_bio_bn", "short_bio_en", "slug", "social", "website_url"];
    const DETAIL_KEYS = [...LIST_KEYS, "associated_since_year", "full_bio_bn", "full_bio_en", "public_email", "public_phone", "quote_bn", "quote_en"].sort();
    const list = await api("/api/v1/public/leadership");
    const mine = (list.json?.data ?? []).filter((p) => p.slug.startsWith(slugBase));
    check("M", "the list answers 200 JSON and holds this run's active profiles", list.status === 200 && /json/.test(list.headers.get("content-type") ?? "") && mine.length >= 2, `${list.status} ${mine.length}`);
    check("M", "every list item has exactly the public keys and no others", mine.every((p) => JSON.stringify(Object.keys(p).sort()) === JSON.stringify(LIST_KEYS)), JSON.stringify(mine[0] ? Object.keys(mine[0]).sort() : []));
    const detail = (await api(apiProfile(slugA))).json?.data;
    check("M", "the detail has exactly the public keys: the list's plus the full biography, the quote, the year and the public contact", detail && JSON.stringify(Object.keys(detail).sort()) === JSON.stringify(DETAIL_KEYS), JSON.stringify(detail ? Object.keys(detail).sort() : null));
    const forbidden = [/"id"\s*:/, /"status"\s*:/, /"display_order"\s*:/, /created_by|updated_by|created_at|updated_at/, /photo_path|photo_sm_path/, /"user/i, /password|token|remember|secret/i, /admin@|no-?reply@|security@|support@/i];
    const raw = list.text + JSON.stringify(detail);
    check("M", "nothing internal appears anywhere in the JSON: no id, status, display order, audit field, storage path, user, secret or internal mailbox", forbidden.every((re) => !re.test(raw)), forbidden.filter((re) => re.test(raw)).map(String).join(" "));
    check("M", "the photo object holds only the two addresses and their dimensions, the social entries only a platform and an address", detail?.photo && JSON.stringify(Object.keys(detail.photo).sort()) === JSON.stringify(["height", "sm_url", "url", "width"]) && detail.social.every((s) => JSON.stringify(Object.keys(s).sort()) === JSON.stringify(["platform", "url"])), JSON.stringify(detail?.photo));
    const noContact = (list.json?.data ?? []).filter((p) => p.slug === DATA.B.slug);
    const bDetail = (await api(apiProfile(DATA.B.slug))).json?.data;
    check("M", "a contact field nobody entered is null, never invented (profile B has none)", bDetail && bDetail.public_email === null && bDetail.public_phone === null && bDetail.social.length === 0 && bDetail.website_url === null && noContact.length === 1);
    const capped = await api("/api/v1/public/leadership?limit=1");
    const featuredOnly = await api("/api/v1/public/leadership?featured=1");
    check("M", "?limit=1 returns one profile and ?featured=1 returns only featured ones", capped.json?.data?.length === 1 && (featuredOnly.json?.data ?? []).every((p) => p.featured === true), `${capped.json?.data?.length}`);
    const huge = await api("/api/v1/public/leadership?limit=100000");
    check("M", "an absurd limit is capped (at most 50 items)", (huge.json?.data?.length ?? 99) <= 50);
    check("M", "the profiles that were here before this run are untouched and still listed", realBefore.every((s) => (list.json?.data ?? []).some((p) => p.slug === s)), JSON.stringify(realBefore));
    const inactiveD = await api(apiProfile(DATA.D.slug));
    check("M", "an inactive profile (D) is not in the API: 404", inactiveD.status === 404);
    const options = await http(`${ADMIN}/api/v1/public/leadership`, { method: "POST", headers: { "user-agent": UA, accept: "application/json" } });
    check("M", "the API is read-only: a POST is refused (405)", options.status === 405 || options.status === 404, String(options.status));
  });

  // ---- U: the sitemap -------------------------------------------------------------------------------------------------------------------------------------
  await step("U sitemap", async () => {
    const started = { startedAt: Date.now(), saveMs: 0 };
    const m = await measure("U sitemap lists profile A", started, `${SITE}/sitemap.xml`, (h) => h.includes(`/leadership/${slugA}`) && h.includes(`/leadership/${DATA.B.slug}`), 180000);
    const xml = (await http(`${SITE}/sitemap.xml`)).text;
    check("U", "the sitemap lists the Leadership page and every active profile with its address", m.ok && xml.includes("/leadership</loc>"), JSON.stringify({ ms: m.ms }));
    check("U", "it lists the English address only for profiles that have English (A yes, B no) and never an inactive profile (D)", xml.includes(`/en/leadership/${slugA}`) && !xml.includes(`/en/leadership/${DATA.B.slug}`) && !xml.includes(DATA.D.slug), "");
  });

  // ---- N and O: layout on desktop and phone, light and dark ----------------------------------------------------------------------------------
  await step("N/O sweep", async () => {
    const PAGES = [["index", "/leadership"], ["index-en", "/en/leadership"], ["profile-a", `/leadership/${slugA}`], ["profile-a-en", `/en/leadership/${slugA}`], ["profile-b-en", `/en/leadership/${DATA.B.slug}`], ["home", "/"], ["home-en", "/en"]];
    for (const [vp, theme] of [["desktop", "light"], ["desktop", "dark"], ["mobile", "light"], ["mobile", "dark"]]) {
      for (const [name, path] of PAGES) {
        const { page, problems, status } = await viewPublic(path, { mobile: vp === "mobile", theme });
        const facts = await page.evaluate(() => ({
          theme: document.documentElement.getAttribute("data-theme"),
          overflow: document.documentElement.scrollWidth > window.innerWidth + 1,
          broken: [...document.images].filter((i) => i.getClientRects().length > 0 && i.complete && i.naturalWidth === 0).length,
          cards: document.querySelectorAll(".leader-card").length,
          lowContrast: (() => {
            // Text colour against the nearest painted background on the leadership cards and the profile page.
            const lum = (rgb) => { const [r, g, b] = rgb.map((v) => { const s = v / 255; return s <= 0.03928 ? s / 12.92 : ((s + 0.055) / 1.055) ** 2.4; }); return 0.2126 * r + 0.7152 * g + 0.0722 * b; };
            const parse = (c) => (c.match(/[\d.]+/g) ?? []).slice(0, 4).map(Number);
            const bgOf = (el) => { for (let e = el; e; e = e.parentElement) { const c = parse(getComputedStyle(e).backgroundColor); if (c.length === 3 || (c.length === 4 && c[3] > 0.9)) return c.slice(0, 3); } return [255, 255, 255]; };
            const bad = [];
            for (const el of document.querySelectorAll(".leader-card h3 a, .leader-designation, .leader-bio, .leader-header h1, .leader-bio-text p, .leader-quote p, .leader-contact-list a")) {
              const fg = parse(getComputedStyle(el).color).slice(0, 3);
              const bg = bgOf(el);
              const [a, b] = [lum(fg), lum(bg)].sort((x, y) => y - x);
              const ratio = (a + 0.05) / (b + 0.05);
              if (ratio < 4.5) bad.push(`${el.className || el.tagName}:${ratio.toFixed(2)}`);
            }
            return bad;
          })(),
        }));
        const fine = status === 200 && facts.theme === theme && !facts.overflow && facts.broken === 0 && facts.lowContrast.length === 0 && problems.console.length + problems.failed.length + problems.http.length === 0;
        check(theme === "dark" ? "O" : "N", `${vp} ${theme} ${name}: loads, in the ${theme} theme, nothing scrolls sideways, no broken image, text contrast >= 4.5:1, no console or network error`, fine, JSON.stringify({ status, facts, problems }));
        await shot(page, `pub-${name}-${vp}-${theme}`);
        await page.close();
      }
    }
    // The three Leadership pages on a phone also keep every card readable: one card per row, photo beside the text.
    const view = await viewPublic("/leadership", { mobile: true });
    const geometry = await view.page.evaluate(() => [...document.querySelectorAll(".leader-card")].map((c) => { const r = c.getBoundingClientRect(); return [Math.round(r.left), Math.round(r.width)]; }));
    check("N", "on a 390 px phone every card is one full-width row inside the page gutters", geometry.length >= 2 && geometry.every(([left, width]) => left >= 12 && left + width <= 390 - 12 + 1 && width >= 300), JSON.stringify(geometry));
    await view.page.close();

    // Three cards on the homepage (the portrait-card layout, not the two wide cards above): feature B for a moment.
    await rowAction(DATA.B.slug, "featured");
    await measure("N feature B (homepage)", { startedAt: Date.now(), saveMs: 0 }, `${SITE}/`, has(`/leadership/${DATA.B.slug}"`));
    for (const [vp, theme] of [["desktop", "light"], ["mobile", "light"], ["desktop", "dark"]]) {
      const { page, problems } = await viewPublic("/", { mobile: vp === "mobile", theme });
      const geom = await page.evaluate(() => ({ n: document.querySelectorAll(".home-leadership .leader-card").length, overflow: document.documentElement.scrollWidth > window.innerWidth + 1 }));
      check("N", `${vp} ${theme}: with three featured profiles the homepage section shows three or four cards and nothing scrolls sideways`, geom.n >= 3 && geom.n <= 4 && !geom.overflow && problems.console.length + problems.failed.length + problems.http.length === 0, JSON.stringify({ geom, problems }));
      await shot(page, `pub-home-3cards-${vp}-${theme}`, ".home-leadership");
      await page.close();
    }
    await rowAction(DATA.B.slug, "featured");
  });

  // ---- T: the admin screens, both languages, both themes, desktop and phone --------------------------------------------------------------------------
  await step("T admin screens", async () => {
    const setLocale = async (code) => {
      await adminPage.goto(`${ADMIN}/admin/leadership`, { waitUntil: "networkidle2", timeout: 90000 });
      await Promise.all([adminPage.waitForNavigation({ waitUntil: "networkidle2", timeout: 90000 }), adminPage.evaluate((c) => {
        [...document.querySelectorAll('form[action$="/locale"]')].find((f) => f.querySelector('input[name="locale"]').value === c).submit();
      }, code)]);
    };
    const look = async (name, path, { mobile = false, theme = "light" } = {}) => {
      await adminPage.setViewport(mobile ? { width: 390, height: 844, isMobile: true, hasTouch: true, deviceScaleFactor: 1 } : { width: 1366, height: 1000, deviceScaleFactor: 1 });
      await adminPage.evaluateOnNewDocument((t) => { try { localStorage.setItem("provatferi-admin-theme", t); } catch { /* ignore */ } }, theme);
      adminProblems.length = 0;
      const response = await adminPage.goto(`${ADMIN}${path}`, { waitUntil: "networkidle2", timeout: 90000 });
      await sleep(250);
      const facts = await adminPage.evaluate(() => ({
        lang: document.documentElement.lang,
        overflow: document.documentElement.scrollWidth > window.innerWidth + 1,
        entities: document.body.innerText.match(/&(#\d+|#x[0-9a-f]+|amp|quot|lt|gt|apos);/gi) ?? [],
        active: [...document.querySelectorAll(".side-nav a.active")].map((a) => a.getAttribute("href")),
        icon: !!document.querySelector('.side-nav a[href$="/admin/leadership"] .ti-user-star'),
        unlabeled: [...document.querySelectorAll("#leadership-form input:not([type=hidden]):not([type=file]), #leadership-form textarea, #leadership-form select")].filter((c) => !(c.getAttribute("aria-label") || (c.id && document.querySelector(`label[for="${c.id}"]`)) || c.getAttribute("aria-labelledby"))).map((c) => c.getAttribute("name")),
      }));
      check("T", `admin ${name}: HTTP 200, no sideways scroll, no entity text, the Leadership menu item highlighted, every form control labelled, no console error`, response?.status() === 200 && !facts.overflow && facts.entities.length === 0 && facts.active.some((h) => h?.endsWith("/admin/leadership")) && facts.unlabeled.length === 0 && adminProblems.length === 0, JSON.stringify({ status: response?.status(), facts, problems: adminProblems.slice(0, 3) }));
      await shot(adminPage, `admin-${name}`);
      return facts;
    };
    for (const code of ["en", "bn"]) {
      await setLocale(code);
      const f1 = await look(`${code}-list-desktop-light`, "/admin/leadership");
      check("T", `admin ${code}: the menu icon renders and the page language is ${code}`, f1.icon && f1.lang === code, JSON.stringify(f1));
      await look(`${code}-create-desktop-light`, "/admin/leadership/create");
      await look(`${code}-edit-desktop-light`, `/admin/leadership/${ids.A}/edit`);
      await look(`${code}-preview-desktop-light`, `/admin/leadership/${ids.A}/preview`);
      if (code === "en") {
        await look("en-list-mobile-light", "/admin/leadership", { mobile: true });
        await look("en-edit-mobile-light", `/admin/leadership/${ids.A}/edit`, { mobile: true });
        await look("en-list-desktop-dark", "/admin/leadership", { theme: "dark" });
        await look("en-edit-desktop-dark", `/admin/leadership/${ids.A}/edit`, { theme: "dark" });
        await look("en-preview-desktop-dark", `/admin/leadership/${ids.A}/preview`, { theme: "dark" });
      }
    }
    await adminPage.setViewport({ width: 1366, height: 1000 });
    await setLocale(originalLocale === "bn" ? "bn" : "en");

    // The preview shows what visitors will see, in both languages, with the same fallback rule as the site.
    await openEdit(ids.A);
    await adminPage.goto(`${ADMIN}/admin/leadership/${ids.B}/preview`, { waitUntil: "networkidle2", timeout: 90000 });
    const preview = await adminPage.evaluate(() => document.body.innerText);
    check("T", "the preview of a Bangla-only profile shows an English page with the translation note, like the site", /translation|অনুবাদ/i.test(preview));

    // Unsaved-changes protection: leaving a dirty form asks; saving does not.
    await openEdit(ids.B);
    await setFields(adminPage, { designation_bn: "পরিবর্তিত কিন্তু সংরক্ষিত নয়" });
    let asked = false;
    adminPage.removeAllListeners("dialog");
    adminPage.on("dialog", (d) => { asked = asked || d.type() === "beforeunload"; d.accept().catch(() => {}); });
    await adminPage.goto(`${ADMIN}/admin/leadership`, { waitUntil: "networkidle2", timeout: 90000 }).catch(() => {});
    check("T", "leaving the edit form with unsaved changes asks for confirmation", asked);
    adminPage.removeAllListeners("dialog");
    adminPage.on("dialog", (d) => d.accept().catch(() => {}));
    await openEdit(ids.B);
    const clean0 = await readForm(adminPage);
    check("T", "and the unsaved text was not saved", clean0.designation_bn === DATA.B.designation_bn, clean0.designation_bn);
  });

  // ---- P: cache revalidation: the table of timings -----------------------------------------------------------------------------------------------
  await step("P timings", async () => {
    const slow = timings.filter((t) => !t.ok);
    const worst = Math.max(0, ...timings.filter((t) => t.ok && !t.label.startsWith("U ")).map((t) => t.afterSaveResponseMs));
    check("P", `every admin change reached the public site (${timings.length} measurements; the slowest after the save response: ${worst} ms)`, slow.length === 0, JSON.stringify(slow));
    check("P", "no ordinary change needed more than 20 seconds after its save response (the site's own safety refresh is 5 minutes; this is the signed revalidation working)", worst <= 20000, `${worst} ms`);
  });
} finally {
  // ---- clean up what this run made, through the same screens ------------------------------------------------------------------------------------------------
  try {
    // Found by the marker in the NAME, not by address: a profile whose address changed during the run is still caught.
    // Archiving takes a profile off the public site whatever state it was in (an archived one is no longer in this list).
    const markerList = `/admin/leadership?q=${encodeURIComponent(MARK)}`;
    for (let pass = 0; pass < 3; pass++) {
      await adminPage.goto(`${ADMIN}${markerList}`, { waitUntil: "networkidle2", timeout: 90000 });
      const slugs = await adminPage.evaluate(() => [...document.querySelectorAll("tbody tr")].map((tr) => tr.textContent.match(/\/leadership\/([a-z0-9-]+)/)?.[1]).filter(Boolean));
      if (slugs.length === 0) break;
      for (const slug of slugs) {
        try {
          await rowAction(slug, "archive", markerList);
        } catch (error) {
          notes.push(`cleanup of ${slug}: ${String(error).slice(0, 120)}`);
        }
      }
    }
    const left = await api("/api/v1/public/leadership");
    check("V", "at the end no QA profile is visible to the public (the list and the API carry none of this run's marker)", !left.text.includes(MARK) && !left.text.includes(slugBase), `${left.status}`);
    check("V", "the profiles that existed before the run are still public and unchanged in number", realBefore.every((s) => (left.json?.data ?? []).some((p) => p.slug === s)), JSON.stringify(realBefore));
    // Put the admin language back where it was, then sign out like a person.
    const lang = await adminPage.evaluate(() => document.documentElement.lang);
    if (lang !== originalLocale) {
      await adminPage.goto(`${ADMIN}/admin/leadership`, { waitUntil: "networkidle2", timeout: 90000 });
      await Promise.all([adminPage.waitForNavigation({ waitUntil: "networkidle2", timeout: 90000 }), adminPage.evaluate((c) => {
        [...document.querySelectorAll('form[action$="/locale"]')].find((f) => f.querySelector('input[name="locale"]').value === c)?.submit();
      }, originalLocale)]).catch(() => {});
    }
    await adminPage.goto(`${ADMIN}/admin`, { waitUntil: "networkidle2", timeout: 90000 });
    await Promise.all([adminPage.waitForNavigation({ waitUntil: "networkidle2", timeout: 90000 }), adminPage.evaluate(() => document.querySelector('form[action$="/logout"]')?.submit())]).catch(() => {});
    check("V", "the QA admin signs out (the login form is shown again)", (await adminPage.$("#email")) !== null && !(await adminPage.$(".side-nav")), adminPage.url());
  } catch (error) {
    check("V!", "cleanup ended with an error", false, error?.stack ?? error);
  }

  await browser.close();

  const failed = checks.filter((c) => !c.ok);
  const summary = { tag: TAG, marker: MARK, admin: ADMIN, site: SITE, finishedAt: new Date().toISOString(), checks: checks.length, passed: checks.length - failed.length, failed: failed.length, notes, accounts: { users: created.userEmails, role: created.roleName }, timings, failedChecks: failed };
  writeFileSync(join(OUT, "results.json"), JSON.stringify({ ...summary, allChecks: checks }, null, 1));
  console.log(`\n${summary.passed}/${summary.checks} checks passed (${failed.length} failed); marker ${MARK}; evidence in ${OUT}`);
  console.log("\nrevalidation timings (ms): label -> admin save | until visible | after the save response");
  for (const t of timings) console.log(`  ${t.label.padEnd(40)} ${String(t.adminSaveMs).padStart(6)} | ${String(t.clickToVisibleMs).padStart(6)} | ${String(t.afterSaveResponseMs).padStart(6)}  ${t.ok ? "" : "NOT SEEN"}`);
  if (notes.length) console.log(`\nnotes: ${notes.join(" ; ")}`);
  process.exitCode = failed.length === 0 ? 0 : 1;
}
