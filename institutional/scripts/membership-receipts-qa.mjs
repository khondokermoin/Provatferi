// Real-Chrome acceptance of Membership task 5 (2026-10-08): the official payment receipt, on the real admin panel and the
// real member portal. Only disposable "QA REGISTRY TEST" data is used — the people r1, r2, r3 made by
// `admin-erp/deploy/qa/membership-registry-qa.php receipt-setup` (see its header) — and that kit's `cleanup` removes all of
// it, receipts included, and gives the receipt numbers back. Admin credentials are CLI arguments only (never stored); the
// QA members' portal passwords are generated here, kept in memory only, never printed.
//
//   node scripts/membership-receipts-qa.mjs --phase <approve|flow|views|portal|security> --site <public url> --admin <admin url> \
//        --email <admin e-mail> --password <admin password> --setup <receipt-setup JSON> [--invites <receipt-invites JSON>] \
//        --state <state.json> --out <dir>
//
//   approve   r1: before any payment the page offers no receipt; registration ৳500 recorded (still no receipt, awaiting) then
//             verified -> receipt A appears (number, View, Download). Approved. r2: the registration fee WAIVED -> no
//             receipt anywhere (H); approved.
//   flow      r1: ৳200 for this month recorded (no receipt) and verified -> receipt B; a voluntary ৳100 -> receipt V;
//             the race (I): three simultaneous verification requests for one payment, and two browser tabs clicking
//             "verify" at the same instant for another -> exactly one receipt each.
//             r2: ৳100 of the ৳200 month verified -> receipt C for what was received, the month partially paid; ৳50 left
//             awaiting (no receipt, F); ৳70 recorded then cancelled (no receipt, G).
//             r3 (five months owed): one month waived (no payment, no receipt, H); ৳600 advance -> three months (D);
//             ৳500 advance -> one month + ৳300 credit left (E).
//   views     every receipt, English and Bangla: the print view (facts, logo, font, no overflow at 390 px, toolbar) and the
//             PDF downloaded through the admin session (headers, %PDF, %%EOF); screenshots; a manifest for pdf-rasterize.mjs.
//   portal    r1 and r2 sign in: their own receipts only, View / Download / English work, another member's number is a 404,
//             a signed-out request is sent to the login page (J); desktop and 390 px, light and dark.
//   security  signed-out requests to the admin receipt, the admin PDF and the member API answer with a login, never a file;
//             an unknown number is a 404; a guessed number reaches nothing.
// Every phase also fails on console errors, page errors and failed requests.

import { existsSync, readFileSync } from "node:fs";
import { mkdir, readFile, writeFile } from "node:fs/promises";
import { resolve } from "node:path";
import { randomBytes } from "node:crypto";
import puppeteer from "puppeteer-core";

const arg = (n, d = null) => {
  const i = process.argv.indexOf(`--${n}`);
  return i === -1 ? d : process.argv[i + 1];
};
const phase = arg("phase");
const site = (arg("site") ?? "").replace(/\/+$/, "");
const admin = (arg("admin") ?? "").replace(/\/+$/, "");
const admin2 = (arg("admin2") ?? arg("admin") ?? "").replace(/\/+$/, "");
const adminEmail = arg("email");
const adminPassword = arg("password");
const readJson = (file) => {
  const raw = readFileSync(resolve(file), "utf8");
  return JSON.parse(raw.slice(raw.indexOf("{"))); // a cron's output may start with a PHP warning line
};
const setup = readJson(arg("setup"));
const invites = arg("invites") ? readJson(arg("invites")) : null;
const stateFile = resolve(arg("state", "./receipts-qa-state.json"));
const out = resolve(arg("out", "./receipts-qa"));
const chrome = [process.env.CHROME_PATH, "C:/Program Files/Google/Chrome/Application/chrome.exe", "C:/Program Files (x86)/Google/Chrome/Application/chrome.exe", "/usr/bin/google-chrome", "/usr/bin/chromium"].filter(Boolean).find(existsSync);
if (!chrome) throw new Error("No Chrome found; set CHROME_PATH");
await mkdir(resolve(out, "pdf"), { recursive: true });

const state = existsSync(stateFile) ? JSON.parse(await readFile(stateFile, "utf8")) : { apps: {}, receipts: {}, payments: {}, pdfs: [] };
const saveState = () => writeFile(stateFile, JSON.stringify(state, null, 2));
const VIEWPORTS = { desktop: { width: 1440, height: 900 }, mobile: { width: 390, height: 844, deviceScaleFactor: 3, isMobile: true, hasTouch: true } };
const results = [];
let failures = 0;
const check = (name, ok, detail) => {
  results.push({ phase, name, ok: !!ok, detail });
  if (!ok) failures++;
  console.log(`${ok ? "  ok  " : "  FAIL"} ${name}${detail !== undefined ? " " + JSON.stringify(detail).slice(0, 500) : ""}`);
};

const browser = await puppeteer.launch({ executablePath: chrome, headless: true, args: ["--no-sandbox", "--disable-dev-shm-usage"] });

async function open({ vp = "desktop", theme = "light" } = {}) {
  const context = await browser.createBrowserContext();
  const page = await context.newPage();
  await page.setViewport(VIEWPORTS[vp]);
  await page.emulateMediaFeatures([{ name: "prefers-color-scheme", value: theme }]);
  const problems = { console: [], pageErrors: [], failed: [] };
  page.on("console", (m) => { if (m.type() === "error") problems.console.push(m.text().slice(0, 200)); });
  page.on("pageerror", (e) => problems.pageErrors.push(String(e).slice(0, 200)));
  page.on("requestfailed", (r) => {
    const reason = r.failure()?.errorText ?? "";
    // A navigation superseded: a flight fetch, a document, or a Server Action stream cut short by its own redirect.
    // Also this script's own `fetch(…, { redirect: "manual" })` race requests: the redirect they get back is not followed, so Chrome
    // reports the abandoned follow-up GET as aborted — not an application error.
    if (/ERR_ABORTED/.test(reason) && (r.url().includes("_rsc=") || r.resourceType() === "document" || r.resourceType() === "fetch" || r.headers()["next-action"] !== undefined)) return;
    problems.failed.push(`${r.method()} ${r.url().replace(/token=[^&]+/, "token=…").slice(0, 110)} ${reason} [${r.resourceType()}${r.isNavigationRequest() ? ", navigation" : ""}]`);
  });
  return { context, page, problems };
}
const clean = (label, problems) => check(`${label}: no console errors, page errors or failed requests`, !problems.console.length && !problems.pageErrors.length && !problems.failed.length, problems.console.length + problems.pageErrors.length + problems.failed.length ? problems : undefined);
const shot = (page, name) => page.screenshot({ path: resolve(out, `${name}.png`), fullPage: false });
const shotFull = (page, name) => page.screenshot({ path: resolve(out, `${name}.png`), fullPage: true });
const hydrated = (page, selector) => page.waitForFunction((s) => { const f = document.querySelector(s); return f && Object.keys(f).some((k) => k.startsWith("__reactProps$")); }, { timeout: 30000 }, selector);
const frames = (page) => page.evaluate(() => new Promise((r) => requestAnimationFrame(() => requestAnimationFrame(r))));
const scrollTo = async (page, selector) => {
  const to = () => page.$eval(selector, (el) => {
    document.documentElement.style.scrollBehavior = "auto";
    const pinned = [...document.querySelectorAll("body *")].filter((e) => {
      const s = getComputedStyle(e);
      const r = e.getBoundingClientRect();
      return (s.position === "fixed" || s.position === "sticky") && r.top <= 1 && r.bottom > 0 && r.height < window.innerHeight / 3 && s.visibility !== "hidden";
    }).reduce((max, e) => Math.max(max, e.getBoundingClientRect().bottom), 0);
    window.scrollTo({ top: el.getBoundingClientRect().top + window.scrollY - pinned - 16, behavior: "instant" });
  }).catch(() => null);
  await to();
  await frames(page);
  await to();
  await frames(page);
};
/** The viewport scrolled to the section, and the section on its own (pinned bars hidden for that one shot). */
const shotSection = async (page, selector, name) => {
  await scrollTo(page, selector);
  await shot(page, name);
  const el = await page.$(selector);
  if (!el) return;
  await page.evaluate(() => {
    for (const e of document.querySelectorAll("body *")) {
      const p = getComputedStyle(e).position;
      if (p === "fixed" || p === "sticky") { e.dataset.qaVisibility = e.style.visibility; e.style.visibility = "hidden"; }
    }
  });
  await el.screenshot({ path: resolve(out, `${name}-section.png`) });
  await page.evaluate(() => {
    for (const e of document.querySelectorAll("[data-qa-visibility]")) { e.style.visibility = e.dataset.qaVisibility; delete e.dataset.qaVisibility; }
  });
};

// ------------------------------------------------------------------------------------------------ calendar (Asia/Dhaka)
const dhakaToday = () => new Intl.DateTimeFormat("en-CA", { timeZone: "Asia/Dhaka", year: "numeric", month: "2-digit", day: "2-digit" }).format(new Date());
const TODAY = setup.today ?? dhakaToday();
const [TY, TM] = TODAY.split("-").map(Number);
const key = (y, m) => `${y}-${String(m).padStart(2, "0")}`;
const shift = (months) => { const t = TY * 12 + (TM - 1) + months; return key(Math.floor(t / 12), (t % 12) + 1); };
const THIS = key(TY, TM);
const BN_DIGITS = "০১২৩৪৫৬৭৮৯";
const bn = (s) => String(s).replace(/[0-9]/g, (d) => BN_DIGITS[Number(d)]);
const BN_MONTHS = ["জানুয়ারি", "ফেব্রুয়ারি", "মার্চ", "এপ্রিল", "মে", "জুন", "জুলাই", "আগস্ট", "সেপ্টেম্বর", "অক্টোবর", "নভেম্বর", "ডিসেম্বর"];
const EN_MONTHS = ["January", "February", "March", "April", "May", "June", "July", "August", "September", "October", "November", "December"];
const monthLabel = (period, locale = "bn") => { const [y, m] = period.split("-").map(Number); return locale === "bn" ? `${BN_MONTHS[m - 1]} ${bn(y)}` : `${EN_MONTHS[m - 1]} ${y}`; };
const taka = (n, locale = "bn") => `৳${locale === "bn" ? bn(n) : n}`;
const RECEIPT_NO = /PLCC-RCT-\d{4}-\d{6,}/;
const PURPOSE = { registration: { en: "Registration Fee", bn: "নিবন্ধন ফি" }, monthly: { en: "Monthly Contribution", bn: "মাসিক চাঁদা" }, advance: { en: "Advance Contribution", bn: "অগ্রিম চাঁদা" }, voluntary: { en: "Voluntary Contribution", bn: "স্বেচ্ছা অনুদান" } };

// ------------------------------------------------------------------------------------------------ admin helpers
async function adminLogin(page) {
  await page.goto(`${admin}/login`, { waitUntil: "networkidle2", timeout: 60000 });
  await page.type("#email", adminEmail, { delay: 2 });
  await page.type("#password", adminPassword, { delay: 2 });
  await Promise.all([page.waitForNavigation({ waitUntil: "networkidle2", timeout: 60000 }), page.click('form button[type="submit"]')]);
  check("admin: signed in", page.url().includes("/admin"));
}
const adminLocale = (page) => page.evaluate(() => document.documentElement.lang.slice(0, 2));
async function setAdminLocale(page, locale) {
  if ((await adminLocale(page)) === locale) return;
  // The standalone print / receipt pages carry no form and so no CSRF token: switch from a panel page (a 419 otherwise).
  if (!(await page.$('form input[name="_token"]'))) await page.goto(`${admin}/admin`, { waitUntil: "networkidle2", timeout: 60000 });
  await page.evaluate(async (loc) => {
    const body = new FormData();
    body.set("_token", document.querySelector('form input[name="_token"]')?.value);
    body.set("locale", loc);
    await fetch("/locale", { method: "POST", body, credentials: "same-origin" });
  }, locale);
  await page.reload({ waitUntil: "networkidle2" });
  check(`admin: language ${locale}`, (await adminLocale(page)) === locale);
}
const go = (page, path) => page.goto(path.startsWith("http") ? path : `${admin}${path}`, { waitUntil: "networkidle2", timeout: 60000 });
const submit = (page, selector) => Promise.all([page.waitForNavigation({ waitUntil: "networkidle2", timeout: 60000 }), page.click(selector)]);
const flash = (page) => page.evaluate(() => [...document.querySelectorAll(".alert")].map((a) => a.innerText.trim()).join(" | "));
const scenario = (k) => setup.scenarios[k];

async function openApplication(page, k) {
  const s = scenario(k);
  await go(page, `/admin/membership?search=${encodeURIComponent(s.application_no)}`);
  const href = await page.$$eval("table a", (links, no) => links.find((l) => l.textContent.trim() === no)?.href ?? null, s.application_no);
  if (!href) throw new Error(`application ${s.application_no} not found in the list`);
  await page.goto(href, { waitUntil: "networkidle2" });
  state.apps[k] = { ...(state.apps[k] ?? {}), applicationUrl: href };
  await saveState();
}
async function memberUrl(page, k) {
  if (state.apps[k]?.memberUrl) return state.apps[k].memberUrl;
  const code = state.apps[k]?.code ?? scenario(k)?.member_code;
  await go(page, `/admin/membership/members?search=${encodeURIComponent(code)}`);
  const href = await page.$eval('[data-testid="registry-row"] a[href*="/admin/membership/members/"]', (a) => a.href).catch(() => null);
  state.apps[k] = { ...(state.apps[k] ?? {}), code, memberUrl: href };
  await saveState();
  return href;
}
/** The receipt links shown on the page: number, view and PDF hrefs, per payment row when there is one. */
const receiptLinks = (page) => page.evaluate(() => [...document.querySelectorAll('[data-testid="receipt-links"]')].map((el) => ({
  no: el.dataset.receiptNo,
  view: el.querySelector('[data-testid="receipt-view"]')?.getAttribute("href") ?? null,
  pdf: el.querySelector('[data-testid="receipt-pdf"]')?.getAttribute("href") ?? null,
  viewTarget: el.querySelector('[data-testid="receipt-view"]')?.getAttribute("target") ?? null,
  paymentId: el.closest('[data-testid="monthly-payment-row"]')?.dataset.paymentId ?? null,
})));
/** What the member page's Monthly Contributions card shows, with the receipt of each payment row. */
async function readMonthly(page) {
  return page.evaluate(() => {
    const card = document.querySelector('[data-testid="monthly-card"]');
    const q = (id) => card?.querySelector(`[data-testid="${id}"]`);
    return {
      outstanding: q("monthly-outstanding")?.innerText.trim() ?? null,
      credit: q("monthly-credit")?.innerText.trim() ?? null,
      dues: [...(card?.querySelectorAll('[data-testid="due-row"]') ?? [])].map((r) => ({
        period: r.dataset.period, state: r.dataset.state, text: r.innerText.replace(/\s+/g, " ").trim(),
        paid: r.querySelector('[data-testid="due-paid"]')?.innerText.trim(), waived: r.querySelector('[data-testid="due-waived"]')?.innerText.trim(),
        outstanding: r.querySelector('[data-testid="due-outstanding"]')?.innerText.trim(),
      })),
      payments: [...(card?.querySelectorAll('[data-testid="monthly-payment-row"]') ?? [])].map((r) => ({
        id: r.dataset.paymentId, state: r.dataset.state, text: r.innerText.replace(/\s+/g, " ").trim(),
        receiptNo: r.querySelector('[data-testid="receipt-no"]')?.innerText.trim() ?? null,
        hasReceiptWord: /রসিদ|receipt/i.test(r.innerText),
      })),
      history: document.querySelector('[data-testid="member-history"]')?.innerText ?? "",
      cardText: card?.innerText ?? "",
    };
  });
}
const due = (m, period) => m.dues.find((d) => d.period === period) ?? null;

async function recordPayment(page, { purpose = "due", period = null, amount, method = "cash", reference = "", keepRest = false }) {
  await page.evaluate(() => { document.querySelector('[data-testid="record-monthly-payment"]').open = true; });
  await page.select("#f-dues-purpose", purpose);
  if (period) {
    const id = await page.$eval(`#f-dues-month option[data-period="${period}"]`, (o) => o.value).catch(() => null);
    if (!id) throw new Error(`no month ${period} to record against`);
    await page.select("#f-dues-month", id);
  }
  await page.$eval("#f-dues-amount", (i, v) => { i.value = v; }, amount);
  await page.select("#f-dues-method", method);
  await page.$eval("#f-dues-reference", (i, v) => { i.value = v; }, reference);
  const ticked = await page.$eval("#f-dues-keep-rest", (i) => i.checked);
  if (ticked !== keepRest) await page.click("#f-dues-keep-rest");
  await submit(page, '[data-testid="record-monthly-payment-submit"]');
}
/** The newest payment awaiting verification; null when there is none. */
async function latestAwaiting(page) {
  const m = await readMonthly(page);
  return m.payments.filter((p) => p.state === "awaiting").map((p) => Number(p.id)).sort((x, y) => y - x)[0] ?? null;
}
const paymentRow = async (page, id) => (await readMonthly(page)).payments.find((p) => Number(p.id) === Number(id)) ?? null;

/** Record one monthly payment, check it has no receipt while awaiting, verify it, return {id, no}. */
async function recordAndVerify(page, label, options) {
  await recordPayment(page, options);
  const id = await latestAwaiting(page);
  let row = await paymentRow(page, id);
  check(`${label}: recorded — awaiting verification, and NO receipt yet`, row?.state === "awaiting" && row.receiptNo === null && !row.hasReceiptWord, row);
  await submit(page, `[data-testid="verify-monthly-${id}"]`);
  row = await paymentRow(page, id);
  check(`${label}: verified — the row now shows its receipt number`, row?.state === "verified" && RECEIPT_NO.test(row.receiptNo ?? ""), row);
  return { id, no: row?.receiptNo ?? null };
}

/**
 * A file as the signed-in browser session would get it — requested from Node with that session's cookies, not from inside
 * the page: a download manager on the machine running this (IDM, on the developer's) answers an in-page fetch of a PDF with
 * its own "204 Intercepted" stub, which says nothing about the server. The server sees the same request either way.
 */
async function fetchBytes(page, url, origin = admin) {
  const absolute = url.startsWith("http") ? url : `${origin}${url}`;
  const cookies = await page.cookies(absolute);
  const r = await fetch(absolute, { headers: { Cookie: cookies.map((c) => `${c.name}=${c.value}`).join("; "), Accept: "application/pdf, */*" }, redirect: "manual" });
  const buf = Buffer.from(await r.arrayBuffer());
  return { status: r.status, type: r.headers.get("content-type"), disposition: r.headers.get("content-disposition"), cache: r.headers.get("cache-control"), nosniff: r.headers.get("x-content-type-options"), bytes: buf.length, buf };
}
async function savePdf(page, url, name, expect) {
  const r = await fetchBytes(page, url);
  const buf = r.buf;
  check(`${name}: a PDF — 200, application/pdf, attachment, never cached`, r.status === 200 && /^application\/pdf/.test(r.type ?? "") && /^attachment; filename="PLCC-RCT-\d{4}-\d{6,}\.pdf"$/.test(r.disposition ?? "") && /no-store/.test(r.cache ?? "") && r.nosniff === "nosniff", { status: r.status, type: r.type, disposition: r.disposition, cache: r.cache });
  check(`${name}: real PDF bytes (%PDF- … %%EOF), ${r.bytes} bytes`, buf.subarray(0, 5).toString("latin1") === "%PDF-" && buf.subarray(-1024).toString("latin1").includes("%%EOF") && r.bytes > 8000, r.bytes);
  await writeFile(resolve(out, "pdf", `${name}.pdf`), buf);
  state.pdfs = state.pdfs.filter((p) => p.name !== name).concat({ name, file: `pdf/${name}.pdf`, expect });
  await saveState();
}

// ------------------------------------------------------------------------------------------------ portal helpers
async function portalSignIn(k, { vp = "desktop", theme = "light" } = {}) {
  const link = invites?.links?.[k];
  if (!link?.url) throw new Error(`no invite link for ${k}`);
  const password = randomBytes(12).toString("base64url"); // never printed or stored
  const ctx = await open({ vp, theme });
  const { page } = ctx;
  const reset = new URL(link.url);
  await page.goto(`${site}${reset.pathname}${reset.search}`, { waitUntil: "networkidle0", timeout: 90000 });
  await hydrated(page, "form.application-form");
  await page.type("#password", password);
  await page.type("#password_confirmation", password);
  await page.click('form.application-form button[type="submit"]');
  await page.waitForFunction(() => document.querySelector(".callout") !== null, { timeout: 60000 });
  await page.goto(`${site}/member/login`, { waitUntil: "networkidle0" });
  await hydrated(page, "form.application-form");
  await page.type("#email", link.email);
  await page.type("#password", password);
  await page.click('form.application-form button[type="submit"]');
  await page.waitForFunction(() => location.pathname.startsWith("/member/dashboard"), { timeout: 60000 });
  return ctx;
}
async function portalReceipts(page) {
  await page.goto(`${site}/member/dashboard`, { waitUntil: "networkidle0", timeout: 90000 });
  return page.evaluate(() => ({
    section: !!document.querySelector('[data-testid="receipts"]'),
    rows: [...document.querySelectorAll('[data-testid="receipt-row"]')].map((r) => ({
      no: r.dataset.receiptNo, purpose: r.dataset.purpose, text: r.innerText.replace(/\s+/g, " ").trim(),
      amount: r.querySelector('[data-testid="receipt-amount"]')?.innerText.trim(),
      view: r.querySelector('[data-testid="receipt-view"]')?.getAttribute("href"), download: r.querySelector('[data-testid="receipt-download"]')?.getAttribute("href"),
      english: r.querySelector('[data-testid="receipt-view-en"]')?.getAttribute("href"), viewTarget: r.querySelector('[data-testid="receipt-view"]')?.getAttribute("target"),
    })),
    page: document.body.innerText,
    overflowX: document.documentElement.scrollWidth > document.documentElement.clientWidth,
  }));
}

// ------------------------------------------------------------------------------------------------ phase: approve
async function approvePhase() {
  const { context, page, problems } = await open();
  await adminLogin(page);
  state.adminLocale ??= await adminLocale(page);
  await saveState();
  await setAdminLocale(page, "bn");

  // r1 — the registration fee is paid: ৳500 recorded, then verified
  await openApplication(page, "r1");
  check("r1: before any payment the application page offers no receipt", (await receiptLinks(page)).length === 0 && !RECEIPT_NO.test(await page.evaluate(() => document.body.innerText)));
  await page.evaluate(() => { document.querySelector('[data-testid="record-payment-form"]').closest("details").open = true; });
  await page.$eval("#field-amount_received", (i, v) => { i.value = v; }, "500");
  await page.$eval("#field-reference", (i) => { i.value = "QA-RCPT-R1-REGISTRATION"; });
  await submit(page, '[data-testid="record-payment-form"] button[type="submit"]');
  check("r1: ৳500 recorded — awaiting verification, so NO receipt yet (F for the registration fee)",
    (await page.$$('[data-testid="payment-row"]')).length === 1 && (await receiptLinks(page)).length === 0 && !!(await page.$('[data-testid="verify-payment"]')));
  await shotFull(page, "A1-r1-registration-recorded-awaiting-bn");
  await submit(page, '[data-testid="verify-payment"]');
  let links = await receiptLinks(page);
  check("r1: verified — the receipt appears with its number, View receipt (new tab) and Download PDF", links.length === 1 && RECEIPT_NO.test(links[0].no) && !!links[0].view && /\/pdf/.test(links[0].pdf ?? "") && links[0].viewTarget === "_blank", links);
  state.receipts.A = links[0]?.no ?? null;
  await shotFull(page, "A2-r1-registration-verified-receipt-links-bn");

  await submit(page, '[data-testid="approve-button"]');
  const member = await page.$eval('[data-testid="open-member"]', (a) => ({ href: a.href, code: a.textContent.match(/PLCC-[A-Z0-9]+-\d{4}-\d{4,}/)?.[0] ?? null })).catch(() => null);
  check("r1: approved — the member number exists", !!member?.code, member);
  state.apps.r1 = { ...state.apps.r1, code: member?.code, memberUrl: member?.href };
  await saveState();
  await page.goto(state.apps.r1.memberUrl, { waitUntil: "networkidle2" });
  links = await receiptLinks(page);
  check("r1: the member page lists the registration receipt too, with the same number", links.some((l) => l.no === state.receipts.A), links);
  const m1 = await readMonthly(page);
  check(`r1: the joining month ${THIS} is owed ৳২০০ — nothing paid yet, so no monthly receipt`, due(m1, THIS)?.state === "due" && m1.payments.length === 0, due(m1, THIS));
  await shotFull(page, "A3-r1-member-page-after-approval-bn");

  // r2 — the registration fee is WAIVED: a waiver is not money, so no receipt anywhere
  await openApplication(page, "r2");
  await page.evaluate(() => { document.querySelector('form[action*="/payments/waive"]').closest("details").open = true; });
  await page.$eval('form[action*="/payments/waive"] textarea[name="waiver_reason"]', (t) => { t.value = "QA REGISTRY TEST — receipt waiver check"; });
  await submit(page, 'form[action*="/payments/waive"] button[type="submit"]');
  const waivedRows = await page.$$eval('[data-testid="payment-row"]', (rows) => rows.map((r) => r.innerText.replace(/\s+/g, " ").trim()));
  check("r2: the registration fee is waived — one payment row, marked waived and verified, and NO receipt", waivedRows.length === 1 && (await receiptLinks(page)).length === 0 && !RECEIPT_NO.test(await page.evaluate(() => document.body.innerText)), waivedRows);
  await shotFull(page, "H1-r2-registration-waived-no-receipt-bn");
  await submit(page, '[data-testid="approve-button"]');
  const member2 = await page.$eval('[data-testid="open-member"]', (a) => ({ href: a.href, code: a.textContent.match(/PLCC-[A-Z0-9]+-\d{4}-\d{4,}/)?.[0] ?? null })).catch(() => null);
  check("r2: approved with the waived fee", !!member2?.code, member2);
  state.apps.r2 = { ...state.apps.r2, code: member2?.code, memberUrl: member2?.href };
  await saveState();
  await page.goto(state.apps.r2.memberUrl, { waitUntil: "networkidle2" });
  check("r2: the member page shows no receipt for the waiver", (await receiptLinks(page)).length === 0 && !RECEIPT_NO.test(await page.evaluate(() => document.body.innerText)));

  await setAdminLocale(page, state.adminLocale);
  clean("approve", problems);
  await context.close();
}

// ------------------------------------------------------------------------------------------------ phase: flow
async function flowPhase() {
  const adminCtx = await open();
  const { page } = adminCtx;
  await adminLogin(page);
  state.adminLocale ??= await adminLocale(page);
  await saveState();
  await setAdminLocale(page, "bn");

  // ---- r1: B, voluntary, the race
  await page.goto(await memberUrl(page, "r1"), { waitUntil: "networkidle2" });
  let r = await recordAndVerify(page, "B (r1, ৳200 for this month)", { purpose: "due", period: THIS, amount: "200", reference: "QA-RCPT-B" });
  state.receipts.B = r.no;
  state.payments.B = r.id;
  let m = await readMonthly(page);
  check("B: the month is paid in full", due(m, THIS)?.state === "paid" && due(m, THIS)?.outstanding === "৳০", due(m, THIS));
  await shotFull(page, "B1-r1-monthly-verified-receipt-bn");

  r = await recordAndVerify(page, "voluntary (r1, ৳100)", { purpose: "voluntary", amount: "100", reference: "QA-RCPT-V" });
  state.receipts.V = r.no;
  state.payments.V = r.id;

  // ---- I: the race — three simultaneous verification requests for ONE payment
  await recordPayment(page, { purpose: "advance", amount: "50", reference: "QA-RCPT-RACE-1" });
  const raceId1 = await latestAwaiting(page);
  const form1 = await page.$eval(`[data-testid="verify-monthly-${raceId1}"]`, (b) => ({ action: b.closest("form").action, token: b.closest("form").querySelector('input[name="_token"]').value }));
  const statuses = await page.evaluate(async ({ action, token }) => {
    const send = () => { const body = new FormData(); body.set("_token", token); body.set("_method", "PATCH"); return fetch(action, { method: "POST", body, credentials: "same-origin", redirect: "manual" }).then((res) => res.status); };
    return Promise.all([send(), send(), send()]);
  }, form1);
  await page.goto(state.apps.r1.memberUrl, { waitUntil: "networkidle2" });
  m = await readMonthly(page);
  const race1 = m.payments.find((p) => Number(p.id) === raceId1);
  const links = await receiptLinks(page);
  check("I (a): three simultaneous verification requests — the payment is verified once and has exactly ONE receipt",
    race1?.state === "verified" && RECEIPT_NO.test(race1.receiptNo ?? "") && links.filter((l) => String(l.paymentId) === String(raceId1)).length === 1, { statuses, row: race1 });
  state.receipts.R1 = race1?.receiptNo ?? null;

  // ---- I: two browser tabs clicking "verify" at the same instant for another payment
  await recordPayment(page, { purpose: "advance", amount: "40", reference: "QA-RCPT-RACE-2" });
  const raceId2 = await latestAwaiting(page);
  const second = await adminCtx.context.newPage();
  await second.setViewport(VIEWPORTS.desktop);
  // --admin2: a second server process for a local run (PHP's built-in server answers one request at a time, so two tabs on
  // one port would be served in turn); on production both tabs use the same site, which really does run them together.
  await second.goto(`${admin2}${new URL(state.apps.r1.memberUrl).pathname}`, { waitUntil: "networkidle2" });
  const sel = `[data-testid="verify-monthly-${raceId2}"]`;
  // A background tab is throttled (a puppeteer click waits for an animation frame that never comes), so the button is
  // clicked by the page's own script, in both tabs back to back.
  const trigger = (p) => p.evaluate((s) => document.querySelector(s).click(), sel);
  await Promise.all([
    page.waitForNavigation({ waitUntil: "load", timeout: 60000 }),
    second.waitForNavigation({ waitUntil: "load", timeout: 60000 }),
    Promise.all([trigger(page), trigger(second)]),
  ]);
  const flashes = [await flash(page), await flash(second)];
  await second.close();
  await page.goto(state.apps.r1.memberUrl, { waitUntil: "networkidle2" });
  m = await readMonthly(page);
  const race2 = m.payments.find((p) => Number(p.id) === raceId2);
  const links2 = await receiptLinks(page);
  check("I (b): two tabs verifying the same payment at once — one receipt, the second tab is told it was already done or simply repeats the result",
    race2?.state === "verified" && RECEIPT_NO.test(race2.receiptNo ?? "") && links2.filter((l) => String(l.paymentId) === String(raceId2)).length === 1, { flashes, row: race2 });
  state.receipts.R2 = race2?.receiptNo ?? null;
  const numbers = [state.receipts.B, state.receipts.V, state.receipts.R1, state.receipts.R2].map((n) => Number(n?.split("-").pop()));
  check("I: B, the gift and the two race payments hold four DIFFERENT consecutive numbers — nothing was skipped or used twice", new Set(numbers).size === 4 && Math.max(...numbers) - Math.min(...numbers) === 3, numbers);
  await shotFull(page, "I1-r1-after-the-races-bn");

  // ---- r2: C (partial), F (awaiting), G (cancelled)
  await page.goto(await memberUrl(page, "r2"), { waitUntil: "networkidle2" });
  r = await recordAndVerify(page, "C (r2, ৳100 of ৳200)", { purpose: "due", period: THIS, amount: "100", reference: "QA-RCPT-C" });
  state.receipts.C = r.no;
  state.payments.C = r.id;
  m = await readMonthly(page);
  check("C: the month is only PARTIALLY paid (৳১০০ of ৳২০০), ৳১০০ still owed", due(m, THIS)?.state === "partially_paid" && due(m, THIS)?.paid === "৳১০০" && due(m, THIS)?.outstanding === "৳১০০", due(m, THIS));
  await shotFull(page, "C1-r2-partial-payment-receipt-bn");

  await recordPayment(page, { purpose: "due", period: THIS, amount: "50", reference: "QA-RCPT-F-PENDING" });
  const pendingId = await latestAwaiting(page);
  const pending = await paymentRow(page, pendingId);
  check("F: ৳50 recorded and left unverified — no receipt, no link, the word 'receipt' appears nowhere on its row", pending?.state === "awaiting" && pending.receiptNo === null && !pending.hasReceiptWord, pending);
  state.payments.F = pendingId;

  await recordPayment(page, { purpose: "due", period: THIS, amount: "50", reference: "QA-RCPT-G-CANCELLED" });
  const cancelId = await latestAwaiting(page);
  await page.evaluate((id) => { document.querySelector(`[data-testid="cancel-monthly-${id}"]`).open = true; }, cancelId);
  await page.$eval(`#cancel-reason-${cancelId}`, (t) => { t.value = "QA REGISTRY TEST — cancelled before verification"; });
  await submit(page, `[data-testid="cancel-monthly-${cancelId}"] form button[type="submit"]`);
  const cancelled = await paymentRow(page, cancelId);
  check("G: an unverified payment cancelled — no receipt", cancelled?.state === "cancelled" && cancelled.receiptNo === null && !cancelled.hasReceiptWord, cancelled);
  state.payments.G = cancelId;
  await shotFull(page, "FG1-r2-pending-and-cancelled-no-receipts-bn");
  const forged = await fetchBytes(page, "/admin/membership/receipts/PLCC-RCT-2026-999999");
  check("a receipt number nobody was issued is a 404 on the admin side too", forged.status === 404 && !/pdf/.test(forged.type ?? ""), forged.status);

  // ---- r3: H (due waiver), D (multi-month), E (advance with credit left)
  await page.goto(await memberUrl(page, "r3"), { waitUntil: "networkidle2" });
  m = await readMonthly(page);
  const periods = [shift(-4), shift(-3), shift(-2), shift(-1), THIS];
  check(`r3: five months are owed (${periods[0]} … ${THIS}), ৳২০০ each`, m.dues.length === 5 && periods.every((p) => due(m, p)?.text.includes("৳২০০")), m.dues.map((d) => d.period));
  await page.evaluate((p) => { document.querySelector(`[data-testid="waive-${p}"]`).open = true; }, periods[0]);
  await page.$eval(`[data-testid="waive-${periods[0]}"] form textarea[name="waiver_reason"]`, (t) => { t.value = "QA REGISTRY TEST — receipt waiver check"; });
  await submit(page, `[data-testid="confirm-waive-${periods[0]}"]`);
  m = await readMonthly(page);
  check(`H (due): ${periods[0]} waived in full — it is not a payment: no payment row, no receipt`, due(m, periods[0])?.state === "waived" && m.payments.length === 0 && (await receiptLinks(page)).length === 0, { due: due(m, periods[0]), payments: m.payments.length });

  r = await recordAndVerify(page, "D (r3, ৳600 advance)", { purpose: "advance", amount: "600", reference: "QA-RCPT-D" });
  state.receipts.D = r.no;
  state.payments.D = r.id;
  m = await readMonthly(page);
  check(`D: the ৳600 paid ${periods[1]}, ${periods[2]} and ${periods[3]} (৳২০০ each), ${THIS} still owes ৳২০০`,
    [periods[1], periods[2], periods[3]].every((p) => due(m, p)?.state === "paid" && due(m, p)?.paid === "৳২০০") && due(m, THIS)?.outstanding === "৳২০০", m.dues.map((d) => `${d.period}:${d.state}`));
  await shotFull(page, "D1-r3-multi-month-verified-bn");

  r = await recordAndVerify(page, "E (r3, ৳500 advance)", { purpose: "advance", amount: "500", reference: "QA-RCPT-E" });
  state.receipts.E = r.no;
  state.payments.E = r.id;
  m = await readMonthly(page);
  check(`E: ${THIS} is paid (৳২০০) and ৳৩০০ stays as advance credit`, due(m, THIS)?.state === "paid" && /৩০০/.test(m.credit ?? ""), { due: due(m, THIS), credit: m.credit });
  await shotFull(page, "E1-r3-advance-with-credit-left-bn");

  await setAdminLocale(page, state.adminLocale);
  clean("flow admin", adminCtx.problems);
  await saveState();
  await adminCtx.context.close();
}

// ------------------------------------------------------------------------------------------------ phase: views
/** The facts of an open receipt print view. */
const readReceipt = (page) => page.evaluate(() => {
  const t = (id) => document.querySelector(`[data-testid="${id}"]`)?.innerText.trim() ?? null;
  const img = document.querySelector(".print-page img");
  return {
    lang: document.documentElement.lang, number: t("receipt-number"), paymentDate: t("receipt-payment-date"), issuedAt: t("receipt-issued-at"),
    amount: t("receipt-amount"), payer: t("receipt-payer"), memberNo: t("receipt-member-no"),
    lines: [...document.querySelectorAll('[data-testid="receipt-line"]')].map((row) => row.innerText.replace(/\s+/g, " ").trim()),
    applied: t("receipt-applied"), credit: t("receipt-credit"), hasApplication: !!document.querySelector('[data-testid="receipt-application"]'),
    text: document.querySelector(".print-page")?.innerText ?? "",
    logo: img ? { loaded: img.complete && img.naturalWidth > 0, width: img.naturalWidth, src: img.getAttribute("src") } : null,
    fonts: [...document.fonts].filter((f) => f.status === "loaded").map((f) => `${f.family} ${f.weight}`),
    overflowX: document.documentElement.scrollWidth > document.documentElement.clientWidth + 1,
    toolbar: {
      pdf: document.querySelector('.print-toolbar a[href*="/pdf"]')?.getAttribute("href") ?? null,
      print: !!document.querySelector('.print-toolbar button[onclick*="print"]'),
      links: [...document.querySelectorAll(".print-toolbar a")].map((a) => a.textContent.trim()),
    },
  };
});

async function viewsPhase() {
  const ctx = await open();
  const { page } = ctx;
  await adminLogin(page);
  state.adminLocale ??= await adminLocale(page);
  await saveState();
  await setAdminLocale(page, "bn");

  // What each receipt must show — language-independent facts, rendered per language below.
  const EXPECT = {
    A: { purpose: "registration", amount: 500, months: [], credit: 0 },
    B: { purpose: "monthly", amount: 200, months: [THIS], applied: 200, credit: 0 },
    V: { purpose: "voluntary", amount: 100, months: [], credit: 0 },
    // r1's month was already paid by B, so these two advances had no due to reach: all of it stays advance credit
    R1: { purpose: "advance", amount: 50, months: [], applied: 0, credit: 50 },
    R2: { purpose: "advance", amount: 40, months: [], applied: 0, credit: 40 },
    C: { purpose: "monthly", amount: 100, months: [THIS], applied: 100, credit: 0 },
    D: { purpose: "advance", amount: 600, months: [shift(-3), shift(-2), shift(-1)], applied: 600, credit: 0 },
    E: { purpose: "advance", amount: 500, months: [THIS], applied: 200, credit: 300 },
  };
  const names = Object.keys(EXPECT).filter((n) => state.receipts[n]);
  check(`every scenario has a receipt number to look at (${names.join(", ")})`, names.length === Object.keys(EXPECT).length, state.receipts);

  for (const name of names) {
    const no = state.receipts[name];
    const ex = EXPECT[name];
    for (const lang of ["en", "bn"]) {
      await go(page, `/admin/membership/receipts/${no}?lang=${lang}`);
      const d = await readReceipt(page);
      const tag = `${name} ${no} [${lang}]`;
      check(`${tag}: number, amount ${taka(ex.amount, lang)}, purpose "${PURPOSE[ex.purpose][lang]}"`, d.number === no && d.amount === taka(ex.amount, lang) && d.text.includes(PURPOSE[ex.purpose][lang]) && d.lang === lang, { number: d.number, amount: d.amount, lang: d.lang });
      check(`${tag}: no raw value (monthly_contribution, cash, paid …) and no private data reaches the page`, !/monthly_contribution|bank_transfer|voluntary_contribution|\bpaid\b|QA REGISTRY TEST — |@gmail\.com|01999\d{6}|password/i.test(d.text.replace(/QA REGISTRY TEST receipt R\d \(\w+\)/g, "")), d.text.slice(0, 200));
      check(`${tag}: the payer is shown as entered, the member number is there`, /QA REGISTRY TEST receipt R\d/.test(d.payer ?? "") && /^PLCC-[A-Z0-9]+-\d{4}-\d{4,}$/.test(d.memberNo ?? ""), { payer: d.payer, memberNo: d.memberNo });
      check(`${tag}: letterhead — both names of the institution, the logo loaded, the Bengali font loaded`, d.text.includes("প্রভাতফেরী সাহিত্য ও সাংস্কৃতিক কেন্দ্র") && /Provatferi Literary and Cultural Center/.test(d.text) && d.logo?.loaded === true && d.fonts.some((f) => f.startsWith("Noto Sans Bengali")), { logo: d.logo, fonts: d.fonts });
      if (["monthly", "advance"].includes(ex.purpose) && ex.months.length === 0) {
        check(`${tag}: nothing was due, so all ${taka(ex.credit, lang)} is advance credit — no month lines, credit and total add up`, d.hasApplication && d.lines.length === 0 && d.applied === null && d.credit === taka(ex.credit, lang), { lines: d.lines, applied: d.applied, credit: d.credit });
      } else if (ex.months.length) {
        check(`${tag}: applied to ${ex.months.join(", ")} — ${ex.months.length} line(s), applied ${taka(ex.applied, lang)}, advance credit ${ex.credit ? taka(ex.credit, lang) : "none"}`,
          d.hasApplication && d.lines.length === ex.months.length && ex.months.every((p, i) => d.lines[i].includes(monthLabel(p, lang))) && d.applied === taka(ex.applied, lang) && (ex.credit ? d.credit === taka(ex.credit, lang) : d.credit === null), { lines: d.lines, applied: d.applied, credit: d.credit });
      } else {
        check(`${tag}: no month table for a ${ex.purpose} (nothing to apply to dues)`, !d.hasApplication, d.lines);
      }
      check(`${tag}: dates are on the Dhaka calendar and clock (no UTC leak)`, new RegExp(lang === "bn" ? "[০-৯]" : "\\d").test(d.issuedAt ?? "") && (lang === "bn" ? !/[0-9]/.test(`${d.issuedAt} ${d.paymentDate}`) : !/[০-৯]/.test(`${d.issuedAt} ${d.paymentDate}`)), { issuedAt: d.issuedAt, paymentDate: d.paymentDate });
      check(`${tag}: toolbar — Download PDF, Print, the other language`, !!d.toolbar.pdf && d.toolbar.print && d.toolbar.links.length >= 3, d.toolbar);
      check(`${tag}: no horizontal overflow on desktop`, !d.overflowX);
      if (["A", "B", "C", "D", "E"].includes(name) || lang === "en") await shotFull(page, `view-${name}-${lang}-desktop`);

      // the PDF, downloaded through the admin session
      // Bengali WORDS are not expected in a Bengali PDF's extracted text: shaped, their glyphs are in visual order and conjuncts have no
      // Unicode value (RecruitmentPdfService). Latin and digits survive, so those are checked; the Bengali itself is checked by eye
      // on the rasterised page (pdf-rasterize.mjs).
      await savePdf(page, `/admin/membership/receipts/${no}/pdf?lang=${lang}`, `${name}-${lang}`, lang === "bn"
        ? [no, taka(ex.amount, "bn"), ...(ex.credit ? [taka(ex.credit, "bn")] : [])]
        : [no, taka(ex.amount, "en"), PURPOSE[ex.purpose].en, ...(ex.months ?? []).map((p) => monthLabel(p, "en")), ...(ex.credit ? [taka(ex.credit, "en")] : [])]);
    }
  }

  // mobile (390 px): the admin's receipt page and the member page's links
  const mobile = await open({ vp: "mobile" });
  await adminLogin(mobile.page);
  await setAdminLocale(mobile.page, "bn");
  for (const name of ["A", "D", "E"].filter((n) => state.receipts[n])) {
    await go(mobile.page, `/admin/membership/receipts/${state.receipts[name]}?lang=bn`);
    const d = await readReceipt(mobile.page);
    check(`${name} at 390 px: the receipt fits (no sideways scroll) and nothing is cut off`, !d.overflowX, d);
    await shotFull(mobile.page, `view-${name}-bn-mobile`);
  }
  await mobile.page.goto(await memberUrl(mobile.page, "r3"), { waitUntil: "networkidle2" });
  await shotSection(mobile.page, '[data-testid="monthly-payments"]', "view-r3-payments-with-receipts-mobile-bn");
  await setAdminLocale(mobile.page, state.adminLocale);
  clean("views mobile", mobile.problems);
  await mobile.context.close();

  await setAdminLocale(page, state.adminLocale);
  clean("views admin", ctx.problems);
  await writeFile(resolve(out, "pdf-manifest.json"), JSON.stringify(state.pdfs, null, 2));
  await saveState();
  await ctx.context.close();
}

// ------------------------------------------------------------------------------------------------ phase: portal
async function portalPhase() {
  const first = await portalSignIn("r1", { vp: "mobile", theme: "dark" });
  const cookiesR1 = await first.page.cookies(site); // the signed-in session, in memory only: the invite link is single-use
  let list = await portalReceipts(first.page);
  const mine = ["A", "B", "V", "R1", "R2"].map((n) => state.receipts[n]);
  check("portal r1: the receipts section lists exactly r1's five receipts, newest first", list.section && list.rows.length === 5 && mine.every((no) => list.rows.some((r) => r.no === no)) && list.rows.map((r) => r.no).join() === [...mine].sort().reverse().join(), list.rows.map((r) => r.no));
  check("portal r1: nothing of r2 or r3 is listed", !list.rows.some((r) => [state.receipts.C, state.receipts.D, state.receipts.E].includes(r.no)));
  check("portal r1: Bangla labels and Bangla digits for amounts; View opens a new tab; links are on this site", list.rows.every((r) => /^৳[০-৯,]+/.test(r.amount ?? "") && r.viewTarget === "_blank" && r.view?.startsWith("/api/member/receipts/PLCC-RCT-") && r.download?.includes("disposition=attachment") && r.english?.includes("lang=en")), list.rows[0]);
  check("portal r1: no verifier, internal note, reference or e-mail is shown", !/QA-RCPT|QA REGISTRY TEST —|verified by|যাচাইকারী/i.test(list.page));
  check("portal r1 (390 px): no sideways scroll", !list.overflowX);
  await shotSection(first.page, '[data-testid="receipts"]', "J1-portal-r1-receipts-mobile-dark");

  // download his own receipt: View (inline) and Download (attachment) and English
  const own = list.rows.find((r) => r.no === state.receipts.B);
  for (const [label, href, disposition] of [["Download", own.download, "attachment"], ["View", own.view, "inline"], ["English", own.english, "inline"]]) {
    const res = await fetchBytes(first.page, href, site);
    const head = res.buf.subarray(0, 5).toString("latin1");
    check(`portal r1: ${label} → a real PDF (${res.bytes} bytes), ${disposition}, never cached, named by its number`,
      res.status === 200 && /^application\/pdf/.test(res.type ?? "") && head === "%PDF-" && (res.disposition ?? "").startsWith(disposition) && (res.disposition ?? "").includes(`${state.receipts.B}.pdf`) && /no-store/.test(res.cache ?? ""),
      { status: res.status, type: res.type, disposition: res.disposition, cache: res.cache, bytes: res.bytes });
    if (label === "Download") {
      await writeFile(resolve(out, "pdf", `portal-${state.receipts.B}-bn.pdf`), res.buf);
      state.pdfs = state.pdfs.filter((p) => p.name !== "portal-B-bn").concat({ name: "portal-B-bn", file: `pdf/portal-${state.receipts.B}-bn.pdf`, expect: [state.receipts.B, "৳২০০"] });
    }
  }

  // J: someone else's receipt is a 404 — exactly like one that does not exist
  const foreign = {};
  for (const [label, no] of Object.entries({ "r2's receipt": state.receipts.C, "r3's receipt D": state.receipts.D, "r3's receipt E": state.receipts.E, "a number nobody holds": "PLCC-RCT-2026-987654", "not a receipt number (encoded, so the client does not normalise it away)": "..%2F..%2Fetc%2Fpasswd" })) {
    const r = await fetchBytes(first.page, `/api/member/receipts/${no}?lang=bn&disposition=inline`, site);
    foreign[label] = { status: r.status, type: r.type, body: r.buf.toString("utf8").slice(0, 60) };
  }
  check("J: r1 asking for r2's and r3's receipts, a guessed number and a junk string — all 404, the same text, never a PDF",
    Object.values(foreign).every((a) => a.status === 404 && !/pdf/.test(a.type ?? "")) && new Set(Object.values(foreign).map((a) => a.body)).size === 1, foreign);

  // signed out: the login page, never a file (Node fetch, so the redirect is visible)
  const signedOut = await fetch(`${site}/api/member/receipts/${state.receipts.B}`, { redirect: "manual" });
  check("J: signed out — 303 to /member/login, no file", signedOut.status === 303 && signedOut.headers.get("location") === "/member/login" && !/pdf/.test(signedOut.headers.get("content-type") ?? ""), { status: signedOut.status, location: signedOut.headers.get("location") });

  clean("portal r1 (mobile)", first.problems);
  await first.context.close();

  // the same session on desktop in light
  const desktop = await open({ vp: "desktop", theme: "light" });
  await desktop.page.setCookie(...cookiesR1);
  list = await portalReceipts(desktop.page);
  check("portal r1 (desktop): the same five receipts", list.rows.length === 5);
  await shotSection(desktop.page, '[data-testid="receipts"]', "J2-portal-r1-receipts-desktop-light");
  clean("portal r1 (desktop)", desktop.problems);
  await desktop.context.close();

  // r2 — his own single receipt, and r1's numbers are 404 for him
  const second = await portalSignIn("r2", { vp: "desktop", theme: "light" });
  list = await portalReceipts(second.page);
  check("portal r2: exactly one receipt — the partial payment C — and not the waiver, the unverified or the cancelled entry", list.rows.length === 1 && list.rows[0].no === state.receipts.C && list.rows[0].purpose === "monthly", list.rows);
  check("portal r2: the partial amount ৳১০০ is what the receipt shows", list.rows[0]?.amount === "৳১০০", list.rows[0]?.amount);
  await shotSection(second.page, '[data-testid="receipts"]', "J3-portal-r2-single-receipt-desktop");
  const r2sees = {};
  for (const [label, no] of Object.entries({ own: state.receipts.C, "r1's B": state.receipts.B, "r1's registration A": state.receipts.A, "r3's D": state.receipts.D })) {
    r2sees[label] = (await fetchBytes(second.page, `/api/member/receipts/${no}`, site)).status;
  }
  check("J: r2 gets his own receipt (200) and a 404 for r1's and r3's", r2sees.own === 200 && r2sees["r1's B"] === 404 && r2sees["r1's registration A"] === 404 && r2sees["r3's D"] === 404, r2sees);
  clean("portal r2", second.problems);
  await second.context.close();
}

// ------------------------------------------------------------------------------------------------ phase: security
async function securityPhase() {
  const no = state.receipts.B ?? state.receipts.A;
  const probe = async (label, url, init = {}) => {
    const res = await fetch(url, { redirect: "manual", ...init });
    const type = res.headers.get("content-type") ?? "";
    const body = res.status === 200 ? Buffer.from(await res.arrayBuffer()).subarray(0, 5).toString("latin1") : "";
    check(`signed out: ${label} → ${res.status}, not a file`, res.status !== 200 && !/pdf/.test(type) && body !== "%PDF-", { status: res.status, location: res.headers.get("location"), type });
    return res;
  };
  const adminShow = await probe("admin receipt page", `${admin}/admin/membership/receipts/${no}`);
  check("signed out: the admin receipt page redirects to the login page", adminShow.status === 302 && /\/login/.test(adminShow.headers.get("location") ?? ""), adminShow.headers.get("location"));
  await probe("admin receipt PDF", `${admin}/admin/membership/receipts/${no}/pdf`);
  const api = await probe("member API receipt PDF (no token)", `${admin}/api/v1/member/receipts/${no}/pdf`, { headers: { Accept: "application/json" } });
  check("signed out: the member API answers 401", api.status === 401, api.status);
  const bad = await fetch(`${admin}/api/v1/member/receipts/${no}/pdf`, { redirect: "manual", headers: { Accept: "application/json", Authorization: "Bearer 1|not-a-real-token" } });
  check("a made-up token: 401, not a file", bad.status === 401 && !/pdf/.test(bad.headers.get("content-type") ?? ""), bad.status);
  await probe("site route", `${site}/api/member/receipts/${no}`);
  const junk = await fetch(`${site}/api/member/receipts/not-a-receipt`, { redirect: "manual" });
  check("a string that is not a receipt number is a 404 without any lookup", junk.status === 404, junk.status);
  for (const guess of ["PLCC-RCT-2026-000001", "PLCC-RCT-2026-000002", "PLCC-RCT-2026-000999"]) {
    const g = await fetch(`${site}/api/member/receipts/${guess}`, { redirect: "manual" });
    check(`guessing ${guess} signed out reaches nothing (303 to login)`, g.status === 303 && g.headers.get("location") === "/member/login", g.status);
  }
  // a path the public could try: a receipt is not stored as a file anywhere under the public docroot
  for (const path of [`/storage/receipts/${no}.pdf`, `/receipts/${no}.pdf`, `/uploads/receipts/${no}.pdf`]) {
    const res = await fetch(`${admin}${path}`, { redirect: "manual" });
    check(`no stored receipt file is reachable at ${path} (404, or 403 where the path is fenced off)`, [403, 404].includes(res.status) && !/pdf/.test(res.headers.get("content-type") ?? ""), res.status);
  }
}

try {
  if (phase === "approve") await approvePhase();
  else if (phase === "flow") await flowPhase();
  else if (phase === "views") await viewsPhase();
  else if (phase === "portal") await portalPhase();
  else if (phase === "security") await securityPhase();
  else throw new Error("--phase approve|flow|views|portal|security");
} catch (e) {
  check(`phase ${phase} crashed`, false, String(e?.stack ?? e).slice(0, 800));
} finally {
  await browser.close();
  await saveState();
  await writeFile(resolve(out, `report-receipts-${phase}.json`), JSON.stringify({ phase, at: new Date().toISOString(), today: TODAY, failures, results }, null, 2));
  console.log(`\n${phase}: ${results.length - failures}/${results.length} checks passed. Screenshots + report in ${out}`);
  process.exit(failures ? 1 : 0);
}
