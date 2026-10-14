// Real-Chrome acceptance of the admin-managed public contact & social settings (docs/PUBLIC_CONTACT_SETTINGS.md): it drives
// the REAL admin Settings form, then looks at the REAL public pages, on desktop and mobile, and measures how long a saved
// change takes to reach a visitor. Run it against production or against a local pair:
//
//   node scripts/contact-settings-qa.mjs --admin https://admin.provatferi.org --site https://provatferi.org \
//        --env <file with <PREFIX>_EMAIL and <PREFIX>_PASSWORD> --prefix PROD_QA_ADMIN --out <evidence dir>
//
// The admin login is read from the env file only and is never printed. The script CHANGES the public contact settings for
// the minutes it runs (temporary values) and then RESTORES every field to what it found, through the same form; it ends
// by comparing the public API and the settings page with the starting state and exits non-zero if they differ.
//
// Checks are labelled with the owner's test matrix: A phone, B address (bn/en), C public e-mail, D Facebook, E LinkedIn,
// F YouTube, G map location, H a disabled link disappears, I invalid/javascript URL refused, J admin@ refused,
// K nothing but an ordinary save and an ordinary page load is needed (no refresh, no redeploy).
import { createRequire } from "node:module";
import { existsSync, mkdirSync, readFileSync, writeFileSync } from "node:fs";
import { join } from "node:path";

const arg = (name, fallback) => {
  const i = process.argv.indexOf(`--${name}`);
  return i > -1 ? process.argv[i + 1] : fallback;
};
const ADMIN = arg("admin").replace(/\/+$/, "");
const SITE = arg("site").replace(/\/+$/, "");
const OUT = arg("out");
const ENV_FILE = arg("env");
const PREFIX = arg("prefix");
if (!ADMIN || !SITE || !OUT || !ENV_FILE || !PREFIX) {
  console.error("usage: --admin <url> --site <url> --env <file> --prefix <KEY_PREFIX> --out <dir>");
  process.exit(2);
}
mkdirSync(OUT, { recursive: true });

const require = createRequire(import.meta.url);
const puppeteer = require("puppeteer-core");
const env = Object.fromEntries(readFileSync(ENV_FILE, "utf8").split(/\r?\n/).filter((l) => l.includes("=")).map((l) => [l.slice(0, l.indexOf("=")).trim(), l.slice(l.indexOf("=") + 1).trim()]));
const chrome = [process.env.CHROME_PATH, "C:/Program Files/Google/Chrome/Application/chrome.exe", "C:/Program Files (x86)/Google/Chrome/Application/chrome.exe", "/usr/bin/google-chrome", "/usr/bin/chromium"].filter(Boolean).find(existsSync);

const checks = [];
const timings = [];
const check = (id, description, ok, detail = "") => {
  checks.push({ id, description, ok: Boolean(ok), detail: String(detail).slice(0, 300) });
  console.log(`${ok ? "PASS" : "FAIL"}  ${id.padEnd(4)} ${description}${ok || !detail ? "" : `  -> ${String(detail).slice(0, 200)}`}`);
};
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

const browser = await puppeteer.launch({ executablePath: chrome, headless: true, args: ["--no-sandbox"] });
const adminPage = await browser.newPage();
await adminPage.setViewport({ width: 1366, height: 1000 });
// A page asking "leave site?" would block every later navigation: accept it (the unsaved-guard check handles it itself).
adminPage.on("dialog", (d) => d.accept().catch(() => {}));

// ----------------------------------------------------------------------------------------------------------- admin form
async function login() {
  await adminPage.goto(`${ADMIN}/login`, { waitUntil: "networkidle2", timeout: 90000 });
  await adminPage.type("#email", env[`${PREFIX}_EMAIL`], { delay: 1 });
  await adminPage.type("#password", env[`${PREFIX}_PASSWORD`], { delay: 1 });
  await Promise.all([adminPage.waitForNavigation({ waitUntil: "networkidle2", timeout: 90000 }), adminPage.click('form button[type="submit"]')]);
  return !adminPage.url().includes("/login");
}

async function openSettings(page = adminPage) {
  await page.goto(`${ADMIN}/admin/settings`, { waitUntil: "networkidle2", timeout: 90000 });
}

/** name -> string for text controls, boolean for switches (the form's current state). */
async function readForm(page = adminPage) {
  return page.$$eval("#site-settings-form [name]", (els) => {
    const out = {};
    for (const el of els) {
      const name = el.getAttribute("name");
      if (name.startsWith("_") || name === "locale") continue;
      if (el.type === "hidden") continue;
      out[name] = el.type === "checkbox" ? el.checked : el.value;
    }
    return out;
  });
}

async function setField(name, value) {
  // A switch is a hidden "0" followed by the checkbox, both named alike: the checkbox is the one a person clicks.
  await adminPage.evaluate((n, v) => {
    const el = document.querySelector(`#site-settings-form input[type="checkbox"][name="${n}"]`) ?? document.querySelector(`#site-settings-form [name="${n}"]`);
    if (el.type === "checkbox") el.checked = Boolean(v);
    else el.value = v;
    el.dispatchEvent(new Event("input", { bubbles: true }));
    el.dispatchEvent(new Event("change", { bubbles: true }));
  }, name, value);
}

/** Fills the given fields and presses the real Save button. Returns what the screen then showed and how long it took. */
async function save(changes, { serverSideOnly = false } = {}) {
  await openSettings();
  if (serverSideOnly) await adminPage.$eval("#site-settings-form", (f) => { f.noValidate = true; }); // what curl or a hostile client does
  for (const [name, value] of Object.entries(changes)) await setField(name, value);
  const started = Date.now();
  await Promise.all([adminPage.waitForNavigation({ waitUntil: "networkidle2", timeout: 90000 }), adminPage.click('#site-settings-form button[type="submit"]')]);
  const saveMs = Date.now() - started;
  const screen = await adminPage.evaluate(() => ({
    path: location.pathname,
    success: [...document.querySelectorAll(".alert-success")].map((e) => e.textContent.replace(/\s+/g, " ").trim()),
    info: [...document.querySelectorAll(".alert-info")].map((e) => e.textContent.replace(/\s+/g, " ").trim()),
    errors: [...document.querySelectorAll(".invalid-feedback")].map((e) => e.textContent.replace(/\s+/g, " ").trim()),
    summary: [...document.querySelectorAll(".alert-danger")].map((e) => e.textContent.replace(/\s+/g, " ").trim()),
    invalidFields: [...document.querySelectorAll('[aria-invalid="true"]')].map((e) => e.getAttribute("name")),
  }));
  return { ...screen, saveMs, startedAt: started };
}

// ---------------------------------------------------------------------------------------------------------- public site
async function getText(url) {
  const res = await fetch(url, { redirect: "follow", headers: { "user-agent": "provatferi-contact-qa/1.0" }, signal: AbortSignal.timeout(30000) });
  return { status: res.status, text: await res.text() };
}

/** Polls the public page with a plain GET (what a visitor's browser sends) until `predicate(html)`; returns ms since `since`. */
async function propagation(url, predicate, since, timeoutMs = 90000) {
  let polls = 0;
  while (Date.now() - since < timeoutMs) {
    polls++;
    try {
      const { text } = await getText(url);
      if (predicate(text)) return { ok: true, ms: Date.now() - since, polls };
    } catch {
      /* a failed poll is just another poll */
    }
    await sleep(120);
  }
  return { ok: false, ms: Date.now() - since, polls };
}

async function viewPublic(path, { mobile = false, theme } = {}) {
  const page = await browser.newPage();
  const problems = { console: [], failed: [], http: [] };
  page.on("console", (m) => { if (m.type() === "error") problems.console.push(m.text().slice(0, 140)); });
  page.on("pageerror", (e) => problems.console.push(String(e).slice(0, 140)));
  page.on("requestfailed", (r) => problems.failed.push(`${r.failure()?.errorText} ${r.url().slice(0, 100)}`));
  page.on("response", (r) => { if (r.status() >= 400 && new URL(r.url()).origin === new URL(SITE).origin) problems.http.push(`${r.status()} ${r.url().slice(0, 100)}`); });
  await page.setViewport(mobile ? { width: 390, height: 844, isMobile: true, hasTouch: true, deviceScaleFactor: 2 } : { width: 1366, height: 900 });
  if (theme) await page.evaluateOnNewDocument((t) => { try { localStorage.setItem("provatferi-theme", t); } catch { /* ignore */ } }, theme);
  const response = await page.goto(`${SITE}${path}`, { waitUntil: "networkidle0", timeout: 90000 });
  return { page, problems, status: response?.status() };
}

/** What a visitor can see and click: every contact surface of the page, read from the live DOM. */
async function surfaces(page) {
  return page.evaluate(() => {
    const hrefs = (sel) => [...document.querySelectorAll(sel)].map((a) => a.getAttribute("href"));
    const text = (sel) => [...document.querySelectorAll(sel)].map((e) => e.textContent.replace(/\s+/g, " ").trim());
    const ld = [...document.querySelectorAll('script[type="application/ld+json"]')].map((s) => { try { return JSON.parse(s.textContent); } catch { return null; } });
    const org = ld.find((e) => e && e["@type"] === "Organization") ?? {};
    return {
      toplineSocial: hrefs(".topline-social a"),
      footerSocial: hrefs(".social-links-footer a"),
      footerContact: text(".footer-grid > div:last-child"),
      footerTel: hrefs('.footer-grid a[href^="tel:"]'),
      footerMail: hrefs('.footer-grid a[href^="mailto:"]'),
      footerMap: hrefs('.footer-grid a[href*="goo.gl"], .footer-grid a[href*="google."]'),
      contactSocial: hrefs(".contact-link"),
      contactCards: text(".info-card"),
      contactTel: hrefs('main a[href^="tel:"]'),
      contactMail: hrefs('main a[href^="mailto:"]'),
      mapButtons: hrefs(".contact-map-actions a"),
      mapLabels: text(".contact-map-label"),
      notes: text(".translation-note"),
      ldEmail: org.email ?? null,
      ldTelephone: org.telephone ?? null,
      ldSameAs: org.sameAs ?? [],
      horizontalScroll: document.documentElement.scrollWidth > window.innerWidth + 1,
    };
  });
}

const fileSafe = (s) => s.replace(/[^a-z0-9-]+/gi, "-");
async function shot(page, name, selector) {
  const file = join(OUT, `${fileSafe(name)}.png`);
  if (selector) {
    const el = await page.$(selector);
    if (el) { await el.scrollIntoView(); await el.screenshot({ path: file }); return file; }
  }
  await page.screenshot({ path: file, fullPage: true });
  return file;
}

async function api() {
  const { status, text } = await getText(`${ADMIN}/api/v1/settings`);
  return { status, json: JSON.parse(text).data, raw: text };
}

/** After a successful save: how long until the public page shows it. Records the timing. */
async function measure(label, saveResult, url, predicate) {
  const result = await propagation(url, predicate, saveResult.startedAt);
  timings.push({ label, url: url.replace(SITE, ""), adminSaveMs: saveResult.saveMs, clickToVisibleMs: result.ms, afterSaveResponseMs: Math.max(0, result.ms - saveResult.saveMs), polls: result.polls, ok: result.ok });
  return result;
}

// ======================================================================================================================
const T = { phone: "+8801711112222", phoneDisplay: "+8801711112222", addrBn: "কিউএ-পরীক্ষা ঠিকানা, চান্দিনা, কুমিল্লা", addrEn: "QA test address, Chandina, Cumilla", map: "https://www.google.com/maps/search/?api=1&query=Chandina%2C+Cumilla", labelBn: "পরীক্ষামূলক স্থান", labelEn: "QA test place", fb: "https://www.facebook.com/provatfericenter-qa", li: "https://www.linkedin.com/company/provatferi-qa", yt: "https://www.youtube.com/@provatferi-qa", email: "support@provatferi.org" };

check("0", "admin sign-in works", await login());
await openSettings();
const baseline = await readForm();
const baselineApi = await api();
let restored = false;
async function restoreBaseline() {
  const result = await save(baseline);
  restored = true;
  return result;
}
try {
writeFileSync(join(OUT, "baseline-form.json"), JSON.stringify(baseline, null, 1));
writeFileSync(join(OUT, "baseline-api.json"), JSON.stringify(baselineApi.json, null, 1));
check("0", "the settings screen renders every expected field", ["site_email", "site_support_email", "site_phone", "site_address", "site_address_en", "site_facebook_url", "site_facebook_enabled", "site_linkedin_url", "site_linkedin_enabled", "site_youtube_url", "site_youtube_enabled", "site_map_url", "site_map_label", "site_map_label_en"].every((n) => n in baseline), Object.keys(baseline).join(","));

// ---- the screen itself: accessibility and guards ---------------------------------------------------------------------
{
  const info = await adminPage.evaluate(() => {
    const controls = [...document.querySelectorAll("#site-settings-form input:not([type=hidden]), #site-settings-form textarea")];
    const named = (c) => {
      if (c.getAttribute("aria-label")) return true;
      if (c.id && document.querySelector(`label[for="${c.id}"]`)) return true;
      const by = (c.getAttribute("aria-labelledby") ?? "").split(/\s+/).filter(Boolean);
      return by.length > 0 && by.every((id) => (document.getElementById(id)?.textContent ?? "").trim() !== "");
    };
    const unlabeled = controls.filter((c) => !named(c)).map((c) => c.getAttribute("name"));
    const dotted = [...document.querySelectorAll("#site-settings-form [name]")].map((e) => e.getAttribute("name")).filter((n) => n.includes("."));
    return { unlabeled, dotted, switches: document.querySelectorAll('input[type=checkbox][role="switch"]').length, hasGuard: document.querySelector("form[data-form-guard]") !== null };
  });
  check("UX", "every control has an accessible name (a label or aria-labelledby), the switches are role=switch, no field name contains a dot", info.unlabeled.length === 0 && info.dotted.length === 0 && info.switches === 3, JSON.stringify(info));
  await shot(adminPage, "admin-settings-desktop-before");
  const mobile = await browser.newPage();
  await mobile.setViewport({ width: 390, height: 844, isMobile: true, hasTouch: true, deviceScaleFactor: 2 });
  await mobile.setCookie(...(await adminPage.cookies()));
  await openSettings(mobile);
  const overflow = await mobile.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 1);
  check("UX", "the admin settings screen has no horizontal scroll on a phone", !overflow);
  await shot(mobile, "admin-settings-mobile-before");
  await mobile.close();
}

// ---- A + C: phone and public e-mail ----------------------------------------------------------------------------------
{
  const r = await save({ site_phone: T.phone, site_email: T.email });
  check("A", "saving a new phone number and a new public e-mail succeeds with a success message", r.success.length === 1 && r.errors.length === 0, JSON.stringify(r));
  const bn = await measure("A phone (bn contact)", r, `${SITE}/contact`, (h) => h.includes(T.phone) && h.includes(`mailto:${T.email}`));
  const en = await measure("A phone (en contact)", r, `${SITE}/en/contact`, (h) => h.includes(T.phone));
  const home = await measure("A phone (footer on home)", r, `${SITE}/`, (h) => h.includes(`tel:${T.phone}`));
  check("K", "the new phone and e-mail reach the Bangla contact page, the English one and every footer without a refresh or a redeploy", bn.ok && en.ok && home.ok, JSON.stringify({ bn: bn.ms, en: en.ms, home: home.ms }));

  for (const [locale, path] of [["bn", "/contact"], ["en", "/en/contact"]]) {
    const { page, problems, status } = await viewPublic(path);
    const s = await surfaces(page);
    check("A", `${locale}: contact page shows the new phone, its tel: link and the footer repeats it`, status === 200 && s.contactCards.some((c) => c.includes(T.phone)) && s.contactTel.includes(`tel:${T.phone}`) && s.footerTel.includes(`tel:${T.phone}`), JSON.stringify({ status, tel: s.contactTel, footer: s.footerTel }));
    check("C", `${locale}: the public e-mail shown (page, footer, structured data) is the one saved`, s.contactMail.includes(`mailto:${T.email}`) && s.footerMail.includes(`mailto:${T.email}`) && s.ldEmail === T.email && s.ldTelephone === T.phone, JSON.stringify({ mail: s.contactMail, ld: [s.ldEmail, s.ldTelephone] }));
    check("PAGE", `${locale}: no console error, failed request or HTTP error on the contact page`, problems.console.length + problems.failed.length + problems.http.length === 0, JSON.stringify(problems));
    await page.close();
  }
  const m = await viewPublic("/contact", { mobile: true });
  const ms = await surfaces(m.page);
  check("A", "mobile: the new phone is on the contact page and nothing scrolls sideways", ms.contactTel.includes(`tel:${T.phone}`) && !ms.horizontalScroll, JSON.stringify(ms.contactTel));
  await m.page.close();
}

// ---- B: address, Bangla and English ----------------------------------------------------------------------------------
{
  const r = await save({ site_address: T.addrBn, site_address_en: T.addrEn });
  check("B", "saving a new Bangla and English address succeeds", r.success.length === 1 && r.errors.length === 0, JSON.stringify(r));
  const bn = await measure("B address (bn)", r, `${SITE}/contact`, (h) => h.includes(T.addrBn));
  const en = await measure("B address (en)", r, `${SITE}/en/contact`, (h) => h.includes(T.addrEn));
  check("B", "Bangla contact page shows the Bangla address and English contact page the English one", bn.ok && en.ok, JSON.stringify({ bn: bn.ms, en: en.ms }));
  for (const [locale, path, want, notWant] of [["bn", "/contact", T.addrBn, T.addrEn], ["en", "/en/contact", T.addrEn, T.addrBn]]) {
    const { page } = await viewPublic(path);
    const s = await surfaces(page);
    check("B", `${locale}: address card and footer carry the right language, and not the other`, s.contactCards.join(" ").includes(want) && s.footerContact.join(" ").includes(want) && !s.contactCards.join(" ").includes(notWant) && s.notes.length === 0, JSON.stringify({ cards: s.contactCards, notes: s.notes }));
    await page.close();
  }

  // English address cleared: the English page shows the Bangla one with the project's translation note.
  const cleared = await save({ site_address_en: "" });
  check("B", "clearing the English address is accepted", cleared.success.length === 1 && cleared.errors.length === 0, JSON.stringify(cleared));
  const note = await measure("B address (en cleared)", cleared, `${SITE}/en/contact`, (h) => h.includes(T.addrBn));
  const { page } = await viewPublic("/en/contact");
  const s = await surfaces(page);
  check("B", "with no English address the English page shows the Bangla one with the translation note (never an invented English text)", note.ok && s.notes.length === 1 && s.contactCards.join(" ").includes(T.addrBn), JSON.stringify({ notes: s.notes, cards: s.contactCards }));
  await shot(page, "contact-en-translation-note", ".info-card");
  await page.close();
}

// ---- D E F G: social links and the map -------------------------------------------------------------------------------
{
  const r = await save({
    site_facebook_url: T.fb, site_facebook_enabled: true,
    site_linkedin_url: T.li, site_linkedin_enabled: true,
    site_youtube_url: T.yt, site_youtube_enabled: true,
    site_map_url: T.map, site_map_label: T.labelBn, site_map_label_en: T.labelEn,
    site_address_en: T.addrEn,
  });
  check("D", "saving Facebook, LinkedIn, YouTube and a map location succeeds", r.success.length === 1 && r.errors.length === 0, JSON.stringify(r));
  const seen = await measure("D-G social+map (bn contact)", r, `${SITE}/contact`, (h) => h.includes(T.li) && h.includes(T.yt) && h.includes(T.fb) && h.includes("google.com/maps"));
  check("K", "the social links and the map reach the live contact page without a refresh or redeploy", seen.ok, JSON.stringify(seen));

  for (const [locale, path, label, viewOnMap] of [["bn", "/contact", T.labelBn, "মানচিত্রে দেখুন"], ["en", "/en/contact", T.labelEn, "View on Map"]]) {
    const { page, problems } = await viewPublic(path);
    const s = await surfaces(page);
    const sameAs = s.ldSameAs;
    check("D", `${locale}: Facebook link is the saved address on the contact page, the header, the footer and in the structured data`, s.contactSocial.includes(T.fb) && s.toplineSocial.includes(T.fb) && s.footerSocial.includes(T.fb) && sameAs.includes(T.fb), JSON.stringify({ contact: s.contactSocial, top: s.toplineSocial }));
    check("E", `${locale}: LinkedIn link is the saved address everywhere`, s.contactSocial.includes(T.li) && s.toplineSocial.includes(T.li) && s.footerSocial.includes(T.li) && sameAs.includes(T.li));
    check("F", `${locale}: YouTube link is the saved address everywhere`, s.contactSocial.includes(T.yt) && s.toplineSocial.includes(T.yt) && s.footerSocial.includes(T.yt) && sameAs.includes(T.yt));
    check("G", `${locale}: the map action opens the saved location, labelled "${viewOnMap}" with the place name of the page's language`, s.mapButtons.includes(T.map) && s.footerMap.includes(T.map) && s.mapLabels.includes(label), JSON.stringify({ buttons: s.mapButtons, labels: s.mapLabels }));
    const safe = await page.evaluate(() => {
      const external = [...document.querySelectorAll('.topline-social a, .social-links-footer a, .contact-link, .contact-map-actions a, .footer-grid a[href*="goo.gl"], .footer-grid a[href*="google."]')];
      return { all: external.length, unsafe: external.filter((a) => a.target !== "_blank" || !/noopener/.test(a.rel) || !/noreferrer/.test(a.rel)).length, noName: external.filter((a) => !(a.getAttribute("aria-label") || a.textContent.trim())).length, emoji: /\p{Extended_Pictographic}/u.test([...document.querySelectorAll(".topline-social, .social-links-footer, .contact-link")].map((e) => e.textContent).join("")) };
    });
    check("SEC", `${locale}: every external link opens in a new tab with rel noopener noreferrer, has an accessible name, and no emoji is used`, safe.all >= 8 && safe.unsafe === 0 && safe.noName === 0 && !safe.emoji, JSON.stringify(safe));
    check("PAGE", `${locale}: no console error, failed request or HTTP error`, problems.console.length + problems.failed.length + problems.http.length === 0, JSON.stringify(problems));
    await shot(page, `contact-${locale}-desktop`);
    await shot(page, `header-topline-${locale}-desktop`, ".brand-topline");
    await shot(page, `footer-${locale}-desktop`, ".site-footer");
    await page.close();
  }
  const m = await viewPublic("/en/contact", { mobile: true });
  const ms = await surfaces(m.page);
  check("G", "mobile: social cards and the map button are present and nothing scrolls sideways", ms.contactSocial.length === 3 && ms.mapButtons.includes(T.map) && !ms.horizontalScroll, JSON.stringify({ social: ms.contactSocial.length, map: ms.mapButtons }));
  await shot(m.page, "contact-en-mobile");
  await shot(m.page, "footer-en-mobile", ".site-footer");
  await m.page.close();
  const dark = await viewPublic("/en/contact", { theme: "dark" });
  await shot(dark.page, "footer-en-dark", ".site-footer");
  await shot(dark.page, "header-topline-en-dark", ".brand-topline");
  await dark.page.close();
}

// ---- H: a disabled link disappears -----------------------------------------------------------------------------------
{
  const r = await save({ site_linkedin_enabled: false });
  check("H", "switching LinkedIn off is accepted", r.success.length === 1 && r.errors.length === 0, JSON.stringify(r));
  const gone = await measure("H linkedin off (contact)", r, `${SITE}/contact`, (h) => !h.includes(T.li));
  const { page } = await viewPublic("/en/contact");
  const s = await surfaces(page);
  const all = [...s.contactSocial, ...s.toplineSocial, ...s.footerSocial, ...s.ldSameAs];
  check("H", "the LinkedIn icon is gone from the contact page, header, footer and structured data; Facebook and YouTube remain", gone.ok && !all.includes(T.li) && s.contactSocial.includes(T.fb) && s.contactSocial.includes(T.yt), JSON.stringify(all));
  const kept = await readForm(); // the address is kept in the form, only the switch changed
  await openSettings();
  const stored = (await readForm()).site_linkedin_url;
  check("H", "the switched-off link keeps its address in the form (switching off does not throw it away)", stored === T.li && kept.site_linkedin_enabled === baseline.site_linkedin_enabled ? true : stored === T.li, stored);
  const a = await api();
  check("H", "the public API no longer lists LinkedIn", !a.json.social.some((l) => l.platform === "linkedin") && a.json.social.length === 2, JSON.stringify(a.json.social));
  await page.close();
}

// ---- I + J: what must be refused -------------------------------------------------------------------------------------
{
  const before = JSON.stringify((await api()).json);
  const hostile = [
    ["I", "a javascript: link in the Facebook field", { site_facebook_url: "javascript:alert(document.domain)" }, "site_facebook_url"],
    ["I", "an http:// (not https) LinkedIn link", { site_linkedin_url: "http://www.linkedin.com/company/x", site_linkedin_enabled: true }, "site_linkedin_url"],
    ["I", "another site dressed up as YouTube", { site_youtube_url: "https://youtube.com.evil.example/@x" }, "site_youtube_url"],
    ["I", "a user-info trick in the Facebook field", { site_facebook_url: "https://www.facebook.com@evil.example/x" }, "site_facebook_url"],
    ["I", "an iframe embed pasted into the map field", { site_map_url: '<iframe src="https://www.google.com/maps/embed?pb=x"></iframe>' }, "site_map_url"],
    ["I", "a Google Maps EMBED address in the map field", { site_map_url: "https://www.google.com/maps/embed?pb=x" }, "site_map_url"],
    ["I", "markup in the address", { site_address: "<script>alert(1)</script>" }, "site_address"],
    ["I", "letters in the phone field", { site_phone: "call us now" }, "site_phone"],
    ["J", "admin@ as the public contact e-mail", { site_email: "admin@provatferi.org" }, "site_email"],
    ["J", "admin@ as the support e-mail", { site_support_email: "ADMIN@provatferi.org" }, "site_support_email"],
    ["J", "no-reply@ as the public contact e-mail", { site_email: "no-reply@provatferi.org" }, "site_email"],
    ["J", "security@ as the public contact e-mail", { site_email: "security@provatferi.org" }, "site_email"],
  ];
  for (const [id, what, changes, field] of hostile) {
    const r = await save(changes, { serverSideOnly: true });
    const refused = r.success.length === 0 && r.invalidFields.includes(field) && r.errors.length >= 1;
    check(id, `the SERVER refuses ${what}, with a message under the field and nothing saved`, refused, JSON.stringify({ errors: r.errors, invalid: r.invalidFields, success: r.success }));
    if (id === "J") check(id, "the refusal message names the public address to use instead", r.errors.some((e) => e.includes("@")), r.errors.join(" | "));
  }
  check("I", "after every refused save the public API is byte-for-byte unchanged", JSON.stringify((await api()).json) === before);

  // The browser's own inline validation also stops the obvious mistakes before any request is made.
  await openSettings();
  await setField("site_facebook_url", "javascript:alert(1)");
  await setField("site_map_url", "http://not-https.example/x");
  const inline = await adminPage.evaluate(() => ({ fb: document.querySelector('[name="site_facebook_url"]').checkValidity(), map: document.querySelector('[name="site_map_url"]').checkValidity() }));
  check("I", "the browser's inline validation flags a javascript: or http:// address before anything is sent", inline.fb === false && inline.map === false, JSON.stringify(inline));

  // Unsaved-changes guard: leaving a dirty form raises the browser's "leave site?" prompt.
  const dialogs = [];
  const onDialog = (d) => dialogs.push(d.type());
  adminPage.removeAllListeners("dialog");
  adminPage.on("dialog", (d) => { onDialog(d); d.accept().catch(() => {}); });
  // Browsers only show the prompt for a page the person has interacted with: a real click and real key presses.
  await openSettings();
  await adminPage.click('[name="site_tagline"]');
  await adminPage.keyboard.type(" x");
  await adminPage.goto(`${ADMIN}/admin`, { waitUntil: "networkidle2", timeout: 60000 }).catch(() => {});
  check("UX", "leaving the form with unsaved changes raises the browser's leave-site prompt", dialogs.includes("beforeunload"), JSON.stringify(dialogs));
  adminPage.removeAllListeners("dialog");
  adminPage.on("dialog", (d) => d.accept().catch(() => {}));

  // Double-submit guard: the button is disabled the moment a valid submit starts.
  await openSettings();
  await setField("site_tagline", `${baseline.site_tagline ?? ""}`);
  const disabled = await adminPage.evaluate(async () => {
    const form = document.querySelector("#site-settings-form");
    const button = form.querySelector('button[type="submit"]');
    form.addEventListener("submit", (e) => e.preventDefault(), { once: true, capture: false });
    button.click();
    await new Promise((r) => setTimeout(r, 50));
    return { disabled: button.disabled, busy: button.getAttribute("aria-busy") };
  });
  check("UX", "the Save button is disabled and marked busy as soon as a submit starts (no double submit)", disabled.disabled === true && disabled.busy === "true", JSON.stringify(disabled));
}

// ---- authorization on the public side of the admin -------------------------------------------------------------------
{
  const res = await fetch(`${ADMIN}/admin/settings`, { redirect: "manual" });
  check("SEC", "a signed-out visitor is sent to the login, not shown the settings", res.status === 302 && (res.headers.get("location") ?? "").includes("/login"), `${res.status} ${res.headers.get("location")}`);
  const put = await fetch(`${ADMIN}/admin/settings`, { method: "PUT", redirect: "manual", headers: { "content-type": "application/x-www-form-urlencoded" }, body: "site_phone=%2B8801700000000" });
  check("SEC", "a signed-out PUT is refused (login redirect or CSRF), never applied", [302, 401, 403, 419].includes(put.status), String(put.status));
}

// ---- RESTORE: every field back to what was found ---------------------------------------------------------------------
{
  const restore = await restoreBaseline();
  check("RESTORE", "the starting values are saved back through the same form", restore.errors.length === 0 && (restore.success.length === 1 || restore.info.length === 1), JSON.stringify(restore));
  const settled = await propagation(`${SITE}/contact`, (h) => !h.includes(T.li) && !h.includes(T.fb) && !h.includes(T.yt) && !h.includes(T.phone) && !h.includes(T.addrBn), restore.startedAt);
  timings.push({ label: "restore (contact)", adminSaveMs: restore.saveMs, clickToVisibleMs: settled.ms, ok: settled.ok });
  await openSettings();
  const after = await readForm();
  const differing = Object.keys(baseline).filter((k) => baseline[k] !== after[k]);
  check("RESTORE", "the settings screen matches the starting state field for field", differing.length === 0, differing.join(","));
  const apiAfter = await api();
  check("RESTORE", "the public API matches the starting state exactly", JSON.stringify(apiAfter.json) === JSON.stringify(baselineApi.json), "");
  for (const [locale, path] of [["bn", "/contact"], ["en", "/en/contact"]]) {
    const { page } = await viewPublic(path);
    const s = await surfaces(page);
    const base = baselineApi.json;
    const expectedSocial = base.social.map((l) => l.url).sort();
    check("RESTORE", `${locale}: the public contact page shows the starting state again (phone, e-mail, social links, map)`,
      JSON.stringify([...s.contactSocial].sort()) === JSON.stringify(expectedSocial) && (base.contact.phone ? s.contactTel.some((t) => t.includes(base.contact.phone.replace(/\D/g, ""))) : s.contactTel.length === 0) && s.contactMail.includes(`mailto:${base.contact.email}`) && (base.location ? s.mapButtons.includes(base.location.map_url) : s.mapButtons.length === 0),
      JSON.stringify(s.contactSocial));
    await shot(page, `contact-${locale}-restored`);
    await page.close();
  }
}

} catch (error) {
  check("CRASH", "the run completed without an unexpected error", false, error?.stack ?? error);
} finally {
  if (!restored) {
    try {
      const r = await restoreBaseline();
      check("RESTORE", "after an interruption the starting values were saved back", r.errors.length === 0 && r.summary.length === 0, JSON.stringify(r));
    } catch (error) {
      check("RESTORE", "after an interruption the starting values were saved back", false, error?.message ?? error);
    }
  }
  await browser.close();
}

const failed = checks.filter((c) => !c.ok);
writeFileSync(join(OUT, "contact-settings-qa-report.json"), JSON.stringify({ site: SITE, admin: ADMIN, ranAt: new Date().toISOString(), passed: checks.length - failed.length, failed: failed.length, checks, timings }, null, 1));
console.log(`\n${checks.length - failed.length}/${checks.length} checks passed`);
console.log("propagation (ms from clicking Save until a plain GET of the public page shows the change):");
for (const t of timings) console.log(`  ${t.label.padEnd(34)} save response ${String(t.adminSaveMs).padStart(5)} ms, visible after ${String(t.clickToVisibleMs).padStart(5)} ms${t.ok ? "" : "  (NOT SEEN)"}`);
process.exit(failed.length === 0 ? 0 : 1);
