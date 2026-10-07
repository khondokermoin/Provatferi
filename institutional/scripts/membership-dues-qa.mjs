// Real-Chrome acceptance of Membership task 4 (2026-10-08): the monthly dues ledger and the monthly-contribution payment
// workflow, on the real admin panel and the real member portal. Only disposable "QA REGISTRY TEST" data is used — the
// people a–z made by `admin-erp/deploy/qa/membership-registry-qa.php dues-setup` (see its header) — and that kit's
// `cleanup` removes all of it. Admin credentials are CLI arguments only (never stored); the QA members' portal passwords
// are generated here, kept in memory only, never printed.
//
//   node scripts/membership-dues-qa.mjs --phase <approve|flow> --site <public url> --admin <admin url> \
//        --email <admin e-mail> --password <admin password> --setup <dues-setup JSON> [--invites <dues-invites JSON>] \
//        --state <state.json> --out <dir>
//
//   approve  a, b (Lifetime: registration ৳500 recorded + verified), c (General ৳100), d (Student ৳0) approved on the real
//            review page. Member pages: a/b owe this month's ৳200 and next month ৳200; c/d "No monthly contribution due",
//            no table. Registry: the "no monthly contribution" filter.
//   flow     1. c, d sign in: "no monthly contribution is required" (Bangla digits only; desktop and 390 px)
//            2. A: cash ৳200 recorded → still owed (member page, registry "due" filter, portal) → verified → paid
//            3. B: ৳300 against a ৳200 month refused; ৳100 → partially paid (registry filter, portal-free) → ৳100 → paid
//            4. E: a ৳300 policy from the 20th of this month on the QD type page: Aug–Oct stay ৳200, next month ৳300;
//               Z: a ৳50 policy from the 1st of next month on the QZ type page: still no dues, next month ৳50
//            5. E suspended: outstanding unchanged, paused, generating makes nothing; reactivated: no duplicate month
//            6. F reactivated: this month owed, the suspended months never; the history says from which month
//            7. G: one month waived in full, one in part: no payment row; history with the reason
//            8. registry filters (overdue / due / partially paid / current / none) and bn/en × desktop/390 px screenshots
//            9. F signs in: overdue months, outstanding and overdue count in Bangla (390 px dark + desktop)
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
const adminEmail = arg("email");
const adminPassword = arg("password");
const readJson = (file) => {
  const raw = readFileSync(resolve(file), "utf8");
  return JSON.parse(raw.slice(raw.indexOf("{"))); // a cron's output may start with a PHP warning line
};
const setup = readJson(arg("setup"));
const invites = arg("invites") ? readJson(arg("invites")) : null;
const stateFile = resolve(arg("state", "./dues-qa-state.json"));
const out = resolve(arg("out", "./dues-qa"));
const chrome = [process.env.CHROME_PATH, "C:/Program Files/Google/Chrome/Application/chrome.exe", "C:/Program Files (x86)/Google/Chrome/Application/chrome.exe", "/usr/bin/google-chrome", "/usr/bin/chromium"].filter(Boolean).find(existsSync);
if (!chrome) throw new Error("No Chrome found; set CHROME_PATH");
await mkdir(out, { recursive: true });

const state = existsSync(stateFile) ? JSON.parse(await readFile(stateFile, "utf8")) : { members: {}, done: {} };
const saveState = () => writeFile(stateFile, JSON.stringify(state, null, 2));
const VIEWPORTS = { desktop: { width: 1440, height: 900 }, mobile: { width: 390, height: 844, deviceScaleFactor: 3, isMobile: true, hasTouch: true } };
const results = [];
let failures = 0;
const check = (name, ok, detail) => {
  results.push({ phase, name, ok: !!ok, detail });
  if (!ok) failures++;
  console.log(`${ok ? "  ok  " : "  FAIL"} ${name}${detail !== undefined ? " " + JSON.stringify(detail).slice(0, 400) : ""}`);
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
    if (/ERR_ABORTED/.test(reason) && (r.url().includes("_rsc=") || r.resourceType() === "document" || r.headers()["next-action"] !== undefined)) return;
    problems.failed.push(`${r.method()} ${r.url().replace(/token=[^&]+/, "token=…").slice(0, 110)} ${reason}`);
  });
  return { context, page, problems };
}
const clean = (label, problems) => check(`${label}: no console errors, page errors or failed requests`, !problems.console.length && !problems.pageErrors.length && !problems.failed.length, problems.console.length + problems.pageErrors.length + problems.failed.length ? problems : undefined);
const shot = (page, name) => page.screenshot({ path: resolve(out, `${name}.png`), fullPage: false });
const shotFull = (page, name) => page.screenshot({ path: resolve(out, `${name}.png`), fullPage: true });
const hydrated = (page, selector) => page.waitForFunction((s) => { const f = document.querySelector(s); return f && Object.keys(f).some((k) => k.startsWith("__reactProps$")); }, { timeout: 30000 }, selector);
// The site scrolls smoothly (CSS scroll-behavior), so a plain scrollIntoView is still moving when the shot is taken; and
// a sticky header would cover the top of the section — it is scrolled to just below whatever stays pinned at the top.
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
  await to(); // a header that only sticks once the page has scrolled is measured on the second pass
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
const NEXT = shift(1);
const BN_DIGITS = "০১২৩৪৫৬৭৮৯";
const bn = (s) => String(s).replace(/[0-9]/g, (d) => BN_DIGITS[Number(d)]);
const BN_MONTHS = ["জানুয়ারি", "ফেব্রুয়ারি", "মার্চ", "এপ্রিল", "মে", "জুন", "জুলাই", "আগস্ট", "সেপ্টেম্বর", "অক্টোবর", "নভেম্বর", "ডিসেম্বর"];
const EN_MONTHS = ["January", "February", "March", "April", "May", "June", "July", "August", "September", "October", "November", "December"];
const monthLabel = (period, locale = "bn") => { const [y, m] = period.split("-").map(Number); return locale === "bn" ? `${BN_MONTHS[m - 1]} ${bn(y)}` : `${EN_MONTHS[m - 1]} ${y}`; };
const lastDay = (y, m) => new Date(Date.UTC(y, m, 0)).getUTCDate();
// E's new policy starts on the 20th of this month — or tomorrow, when the 20th has passed (still inside this month).
const MID_MONTH = (() => { const d = Math.max(20, Number(TODAY.slice(8)) + 1); return d > lastDay(TY, TM) ? null : `${key(TY, TM)}-${String(d).padStart(2, "0")}`; })();

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
const QA_SEARCH = encodeURIComponent("QA REGISTRY TEST dues");
const registryCodes = async (page, monthly) => {
  await go(page, `/admin/membership/members?search=${QA_SEARCH}&per_page=50${monthly ? `&monthly=${monthly}` : ""}`);
  return page.$$eval('[data-testid="registry-row"]', (r) => r.map((x) => ({ code: x.dataset.memberCode, standing: x.querySelector('[data-testid="monthly-cell"]')?.dataset.standing ?? null })));
};
const code = (letter) => state.members[letter]?.code ?? setup.scenarios[letter]?.member_code ?? null;

async function memberUrl(page, letter) {
  if (state.members[letter]?.url) return state.members[letter].url;
  await go(page, `/admin/membership/members?search=${encodeURIComponent(code(letter))}`);
  const href = await page.$eval('[data-testid="registry-row"] a[href*="/admin/membership/members/"]', (a) => a.href).catch(() => null);
  state.members[letter] = { ...(state.members[letter] ?? {}), code: code(letter), url: href };
  await saveState();
  return href;
}

/** What the member page's Monthly Contributions card shows. */
async function readMonthly(page) {
  return page.evaluate(() => {
    const card = document.querySelector('[data-testid="monthly-card"]');
    const q = (id) => card?.querySelector(`[data-testid="${id}"]`);
    return {
      amount: q("monthly-amount")?.innerText.trim() ?? null,
      monthState: q("month-state")?.dataset.state ?? null,
      outstanding: q("monthly-outstanding")?.innerText.trim() ?? null,
      overdue: q("monthly-overdue")?.innerText.trim() ?? null,
      credit: q("monthly-credit")?.innerText.trim() ?? null,
      next: q("monthly-next")?.innerText.trim() ?? null,
      paused: q("monthly-paused")?.innerText.trim() ?? null,
      empty: q("monthly-empty")?.innerText.trim() ?? null,
      table: !!q("dues-table"),
      dues: [...(card?.querySelectorAll('[data-testid="due-row"]') ?? [])].map((r) => ({
        period: r.dataset.period, state: r.dataset.state, text: r.innerText.replace(/\s+/g, " ").trim(),
        paid: r.querySelector('[data-testid="due-paid"]')?.innerText.trim(), waived: r.querySelector('[data-testid="due-waived"]')?.innerText.trim(),
        outstanding: r.querySelector('[data-testid="due-outstanding"]')?.innerText.trim(),
      })),
      payments: [...(card?.querySelectorAll('[data-testid="monthly-payment-row"]') ?? [])].map((r) => ({ id: r.dataset.paymentId, state: r.dataset.state, text: r.innerText.replace(/\s+/g, " ").trim() })),
      months: [...document.querySelectorAll("#f-dues-month option[data-period]")].map((o) => ({ id: o.value, period: o.dataset.period })),
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
async function verifyLatestAwaiting(page) {
  const m = await readMonthly(page);
  const awaiting = m.payments.filter((p) => p.state === "awaiting").map((p) => Number(p.id)).sort((x, y) => y - x)[0];
  if (!awaiting) throw new Error("no payment awaiting verification");
  await submit(page, `[data-testid="verify-monthly-${awaiting}"]`);
  return awaiting;
}
async function statusAction(page, action, reason) {
  await page.evaluate((a) => { document.querySelector(`[data-testid="action-${a}"]`).open = true; }, action);
  if (reason) await page.$eval(`#reason-${action}`, (t, v) => { t.value = v; }, reason);
  await submit(page, `[data-testid="confirm-${action}"]`);
}
async function typeUrl(page, typeCode) {
  await go(page, "/admin/membership/types");
  return page.$$eval("td", (cells, c) => cells.map((td) => ({ href: td.querySelector('a[href*="/admin/membership/types/"]')?.href ?? null, badges: [...td.querySelectorAll(".badge")].map((b) => b.textContent.trim()) })).find((t) => t.href && t.badges.includes(c))?.href ?? null, typeCode);
}
async function createPolicy(page, typeCode, { registration, monthly, from, note }) {
  const url = await typeUrl(page, typeCode);
  await page.goto(url, { waitUntil: "networkidle2" });
  await page.$eval("#field-registration_fee", (i, v) => { i.value = v; }, registration);
  await page.$eval("#field-monthly_contribution", (i, v) => { i.value = v; }, monthly);
  await page.$eval("#field-effective_from", (i, v) => { i.value = v; }, from);
  await page.$eval("#field-note", (t, v) => { t.value = v; }, note);
  await submit(page, 'form[action$="/fee-policies"] button[type="submit"]');
  return page.evaluate(() => ({ alert: [...document.querySelectorAll(".alert")].map((a) => a.innerText.trim()).join(" | "), upcoming: document.querySelector('[data-testid="upcoming-policy"]')?.innerText ?? null }));
}

// ------------------------------------------------------------------------------------------------ portal helpers
async function portalSignIn(letter, { vp = "desktop", theme = "light" } = {}) {
  const link = invites?.links?.[letter];
  if (!link?.url) throw new Error(`no invite link for ${letter}`);
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
async function portalMonthly(page) {
  await page.goto(`${site}/member/dashboard`, { waitUntil: "networkidle0", timeout: 90000 });
  return page.evaluate(() => {
    const card = document.querySelector('[data-testid="monthly-card"]');
    const q = (id) => card?.querySelector(`[data-testid="${id}"]`);
    return {
      section: !!document.querySelector('[data-testid="monthly-contribution"]'),
      required: card?.dataset.required ?? null,
      notRequired: q("monthly-not-required")?.innerText.trim() ?? null,
      amount: q("monthly-current-amount")?.innerText.trim() ?? null,
      monthState: q("monthly-month-state")?.dataset.state ?? null,
      monthStateText: q("monthly-month-state")?.innerText.trim() ?? null,
      outstanding: q("monthly-outstanding")?.innerText.trim() ?? null,
      outstandingRaw: q("monthly-outstanding")?.dataset.amount ?? null,
      overdue: q("monthly-overdue-count")?.innerText.trim() ?? null,
      overdueRaw: q("monthly-overdue-count")?.dataset.count ?? null,
      rows: [...(card?.querySelectorAll('[data-testid="monthly-due-row"]') ?? [])].map((r) => ({ period: r.dataset.period, state: r.dataset.state, text: r.innerText.replace(/\s+/g, " ").trim() })),
      latinDigitsInCard: /[0-9]/.test(card?.innerText ?? ""),
      page: document.body.innerText,
    };
  });
}

// ------------------------------------------------------------------------------------------------ phase: approve
async function approvePhase() {
  const { context, page, problems } = await open();
  await adminLogin(page);
  state.adminLocale ??= await adminLocale(page);
  await saveState();
  await setAdminLocale(page, "bn");

  for (const [letter, fee] of [["a", "500"], ["b", "500"], ["c", "100"], ["d", null]]) {
    const s = setup.scenarios[letter];
    if (!state.members[letter]?.code) {
      await go(page, `/admin/membership?search=${encodeURIComponent(s.application_no)}`);
      const appUrl = await page.$$eval("table a", (links, no) => links.find((l) => l.textContent.trim() === no)?.href ?? null, s.application_no);
      await page.goto(appUrl, { waitUntil: "networkidle2" });
      if (fee !== null && !(await page.$('[data-testid="payment-row"]'))) {
        await page.evaluate(() => { document.querySelector('[data-testid="record-payment-form"]').closest("details").open = true; });
        await page.$eval("#field-amount_received", (i, v) => { i.value = v; }, fee);
        await page.$eval("#field-reference", (i) => { i.value = "QA-DUES-REGISTRATION"; });
        await submit(page, '[data-testid="record-payment-form"] button[type="submit"]');
        await submit(page, '[data-testid="verify-payment"]');
      }
      await submit(page, '[data-testid="approve-button"]');
      const member = await page.$eval('[data-testid="open-member"]', (a) => ({ href: a.href, code: a.textContent.match(/PLCC-[A-Z0-9]+-\d{4}-\d{4,}/)?.[0] ?? null })).catch(() => null);
      check(`approve ${letter} (${s.type}): approved`, !!member?.code, member);
      state.members[letter] = { code: member?.code, url: member?.href };
      await saveState();
    }
    await page.goto(state.members[letter].url, { waitUntil: "networkidle2" });
    const m = await readMonthly(page);
    if (s.type === "LM") {
      const d = due(m, THIS);
      check(`member ${letter}: the joining month ${THIS} is owed ৳২০০ — created by the approval itself`, m.dues.length === 1 && d?.state === "due" && d.outstanding === "৳২০০" && m.monthState === "due", m.dues);
      check(`member ${letter}: monthly contribution ৳২০০, next month (${monthLabel(NEXT)}) ৳২০০`, m.amount === "৳২০০" && m.next?.includes(monthLabel(NEXT)) && m.next?.includes("৳২০০"), { amount: m.amount, next: m.next });
      check(`member ${letter}: the registration fee information is still there`, (await page.$eval('[data-testid="member-payment-state"]', (e) => e.dataset.state ?? e.textContent).catch(() => null)) !== null);
    } else {
      check(`member ${letter} (${s.type} ৳0): "মাসিক চাঁদা প্রযোজ্য নয়" — no due, no table, never paid/unpaid`,
        m.dues.length === 0 && !m.table && m.monthState === "not_required" && m.amount === "মাসিক চাঁদা প্রযোজ্য নয়" && /প্রযোজ্য নয়/.test(m.empty ?? "") && !/পরিশোধিত|বকেয়া/.test(m.cardText.replace(/মোট বকেয়া/g, "")), { amount: m.amount, empty: m.empty, state: m.monthState });
    }
    if (letter === "a" || letter === "d") await shotFull(page, `A1-member-${letter}-after-approval-bn`);
  }

  const none = await registryCodes(page, "not_required");
  check('registry: "no monthly contribution" filter lists the General, Student and zero-type members, not the Lifetime ones',
    [code("c"), code("d"), code("z")].every((c) => none.some((r) => r.code === c)) && ![code("a"), code("b")].some((c) => none.some((r) => r.code === c)), none);
  const due1 = await registryCodes(page, "due");
  check('registry: "due" filter lists both Lifetime members (this month owed)', [code("a"), code("b")].every((c) => due1.some((r) => r.code === c)), due1);
  await shot(page, "A2-registry-due-filter-bn");

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

  // 1. zero contributions on the portal
  for (const [letter, vp, theme] of [["c", "desktop", "light"], ["d", "mobile", "light"]]) {
    const ctx = await portalSignIn(letter, { vp, theme });
    const pm = await portalMonthly(ctx.page);
    check(`portal ${letter}: "no monthly contribution required", no months, no amounts owed`,
      pm.section && pm.required === "false" && /কোনো মাসিক চাঁদা প্রযোজ্য নয়/.test(pm.notRequired ?? "") && pm.rows.length === 0 && pm.outstanding === null, pm.notRequired);
    await shotSection(ctx.page, '[data-testid="monthly-contribution"]', `B-portal-${letter}-no-monthly-${vp}`);
    clean(`portal ${letter}`, ctx.problems);
    await ctx.context.close();
  }

  // 2. A — record, still owed, verify, paid
  const portalA = await portalSignIn("a");
  let pm = await portalMonthly(portalA.page);
  check("portal A: this month owed ৳২০০ before anything is recorded", pm.monthState === "due" && pm.outstandingRaw === "200.00" && pm.amount === "৳২০০" && !pm.latinDigitsInCard, pm);
  await page.goto(await memberUrl(page, "a"), { waitUntil: "networkidle2" });
  await recordPayment(page, { period: THIS, amount: "200", reference: "QA-DUES-A-CASH" });
  let m = await readMonthly(page);
  check("A: cash ৳200 recorded — awaiting verification, the month still owes ৳২০০", m.payments.length === 1 && m.payments[0].state === "awaiting" && due(m, THIS)?.state === "due" && due(m, THIS)?.outstanding === "৳২০০" && m.outstanding === "৳২০০", { payments: m.payments, due: due(m, THIS) });
  await shotFull(page, "C1-member-a-recorded-awaiting-bn");
  const dueNow = await registryCodes(page, "due");
  check('A: the registry still lists A under "due" (an unverified payment pays nothing)', dueNow.some((r) => r.code === code("a")), dueNow);
  pm = await portalMonthly(portalA.page);
  check("portal A: still owed after the payment was recorded but not verified", pm.monthState === "due" && pm.outstandingRaw === "200.00", { state: pm.monthState, outstanding: pm.outstandingRaw });
  check("portal A: no reference, internal note or verifier is shown to the member", !pm.page.includes("QA-DUES-A-CASH") && !pm.page.includes("QA-DUES-REGISTRATION"));
  await shotSection(portalA.page, '[data-testid="monthly-contribution"]', "C2-portal-a-recorded-still-owed");
  await page.goto(await memberUrl(page, "a"), { waitUntil: "networkidle2" });
  await verifyLatestAwaiting(page);
  m = await readMonthly(page);
  check("A: verified — the month is paid, nothing outstanding", due(m, THIS)?.state === "paid" && due(m, THIS)?.outstanding === "৳০" && m.outstanding === "৳০" && m.payments[0].state === "verified" && m.monthState === "paid", { due: due(m, THIS), payments: m.payments });
  check("A: the history records the payment and, separately, its verification", m.history.includes("মাসিক পরিশোধ রেকর্ড") && m.history.includes("মাসিক পরিশোধ যাচাই") && m.history.includes("৳২০০"));
  await shotFull(page, "C3-member-a-verified-paid-bn");
  pm = await portalMonthly(portalA.page);
  check("portal A: paid after verification", pm.monthState === "paid" && pm.outstandingRaw === "0.00" && pm.rows[0]?.state === "paid", { state: pm.monthState, rows: pm.rows });
  await shotSection(portalA.page, '[data-testid="monthly-contribution"]', "C4-portal-a-paid");
  clean("portal A", portalA.problems);
  await portalA.context.close();

  // 3. B — over-payment refused, then 100 + 100
  await page.goto(await memberUrl(page, "b"), { waitUntil: "networkidle2" });
  await recordPayment(page, { period: THIS, amount: "300" });
  m = await readMonthly(page);
  const refused = await page.$eval('[data-testid="record-monthly-payment-form"]', (f) => f.innerText).catch(() => "");
  check("B: ৳300 against a ৳200 month is refused unless kept as advance credit", m.payments.length === 0 && /৳২০০/.test(refused) && /অগ্রিম/.test(refused), refused.slice(0, 200));
  await page.goto(await memberUrl(page, "b"), { waitUntil: "networkidle2" });
  await recordPayment(page, { period: THIS, amount: "100", reference: "QA-DUES-B-1" });
  await verifyLatestAwaiting(page);
  m = await readMonthly(page);
  check("B: ৳100 verified — partially paid, ৳১০০ still owed", due(m, THIS)?.state === "partially_paid" && due(m, THIS)?.paid === "৳১০০" && due(m, THIS)?.outstanding === "৳১০০", due(m, THIS));
  await shotFull(page, "D1-member-b-partially-paid-bn");
  const partial = await registryCodes(page, "partially_paid");
  check('B: the registry lists B under "partially paid"', partial.some((r) => r.code === code("b")) && !partial.some((r) => r.code === code("a")), partial);
  await page.goto(await memberUrl(page, "b"), { waitUntil: "networkidle2" });
  await recordPayment(page, { period: THIS, amount: "100", reference: "QA-DUES-B-2" });
  await verifyLatestAwaiting(page);
  m = await readMonthly(page);
  check("B: the second ৳100 verified — paid, two verified payments", due(m, THIS)?.state === "paid" && due(m, THIS)?.paid === "৳২০০" && m.payments.filter((p) => p.state === "verified").length === 2, { due: due(m, THIS), payments: m.payments.map((p) => p.state) });
  await shotFull(page, "D2-member-b-paid-bn");

  // 4. E and Z — future policies created on the real type pages
  if (!MID_MONTH) {
    check("E: a mid-month date is left in this month", false, "today is the last day of the month — run the flow another day");
  } else {
    const made = await createPolicy(page, "QD", { registration: "0", monthly: "300", from: MID_MONTH, note: "QA REGISTRY TEST — a mid-month change (removed by cleanup)" });
    check(`E: a ৳300 policy from ${MID_MONTH} created on the QD type page`, /৩০০/.test(made.upcoming ?? "") || /300/.test(made.upcoming ?? ""), made);
    await shot(page, "E1-type-qd-upcoming-policy-bn");
  }
  await page.goto(await memberUrl(page, "e"), { waitUntil: "networkidle2" });
  m = await readMonthly(page);
  const eMonths = [shift(-2), shift(-1), THIS];
  check(`E: the months already owed stay ৳২০০ (${eMonths.join(", ")}) — this month too: a mid-month change applies from the next month`,
    m.dues.length === 3 && eMonths.every((p) => due(m, p)?.text.includes("৳২০০")), m.dues.map((d) => d.text));
  check(`E: next month (${monthLabel(NEXT)}) uses the new ৳৩০০`, m.next?.includes(monthLabel(NEXT)) && m.next?.includes("৳৩০০"), m.next);
  await shotFull(page, "E2-member-e-history-unchanged-next-300-bn");
  const zMade = await createPolicy(page, "QZ", { registration: "0", monthly: "50", from: `${NEXT}-01`, note: "QA REGISTRY TEST — zero now, 50 later (removed by cleanup)" });
  check(`Z: a ৳50 policy from ${NEXT}-01 created on the QZ type page`, /৫০|50/.test(zMade.upcoming ?? ""), zMade);
  await page.goto(await memberUrl(page, "z"), { waitUntil: "networkidle2" });
  m = await readMonthly(page);
  check("Z: still no dues at all — no historical fake months", m.dues.length === 0 && m.monthState === "not_required", m.dues);
  check(`Z: next month (${monthLabel(NEXT)}) ৳৫০`, m.next?.includes(monthLabel(NEXT)) && m.next?.includes("৳৫০"), m.next);
  await shotFull(page, "E3-member-z-zero-then-50-bn");

  // 5. E suspended, then reactivated in the same month
  await page.goto(await memberUrl(page, "e"), { waitUntil: "networkidle2" });
  const before = await readMonthly(page);
  await statusAction(page, "suspend", "QA REGISTRY TEST — suspension check");
  m = await readMonthly(page);
  check("E suspended: nothing owed changes, the paused notice replaces next month", m.outstanding === before.outstanding && m.dues.length === before.dues.length && !!m.paused && m.next === null, { outstanding: m.outstanding, paused: m.paused });
  await submit(page, '[data-testid="generate-dues"]');
  const generated = await flash(page);
  m = await readMonthly(page);
  check("E suspended: generating dues creates nothing", m.dues.length === before.dues.length && /কিছু বাদ পড়েনি|হালনাগাদ/.test(generated), generated);
  check(`E suspended: the history says dues pause from ${monthLabel(NEXT)}`, m.history.includes("মাসিক চাঁদা স্থগিত") && m.history.includes(`${monthLabel(NEXT)} থেকে`));
  await shotFull(page, "F1-member-e-suspended-bn");
  await statusAction(page, "reactivate");
  m = await readMonthly(page);
  check("E reactivated in the same month: no month twice, next month back", m.dues.length === before.dues.length && new Set(m.dues.map((d) => d.period)).size === m.dues.length && !!m.next, m.dues.map((d) => d.period));

  // 6. F — suspended three months ago, reactivated now
  await page.goto(await memberUrl(page, "f"), { waitUntil: "networkidle2" });
  m = await readMonthly(page);
  const fBefore = m.dues.map((d) => d.period).sort(); // the page lists the newest month first
  check(`F (suspended since ${shift(-3)}): only the months before the suspension (${shift(-4)}, ${shift(-3)}) are owed, both overdue`,
    fBefore.join() === [shift(-4), shift(-3)].join() && m.dues.every((d) => d.state === "overdue") && m.outstanding === "৳৪০০" && m.overdue === "২" && !!m.paused, { periods: fBefore, outstanding: m.outstanding, overdue: m.overdue });
  await shotFull(page, "G1-member-f-suspended-overdue-bn");
  await statusAction(page, "reactivate");
  m = await readMonthly(page);
  const fAfter = m.dues.map((d) => d.period).sort();
  check(`F reactivated: this month (${THIS}) owed ৳২০০; ${shift(-2)} and ${shift(-1)} never charged`,
    fAfter.join() === [shift(-4), shift(-3), THIS].join() && due(m, THIS)?.state === "due" && m.outstanding === "৳৬০০", { periods: fAfter, outstanding: m.outstanding });
  check(`F: the history says dues resume from ${monthLabel(THIS)}`, m.history.includes("মাসিক চাঁদা আবার চালু") && m.history.includes(`${monthLabel(THIS)} থেকে`));
  await shotFull(page, "G2-member-f-reactivated-bn");

  // 7. G — waivers: one in full, one in part
  await page.goto(await memberUrl(page, "g"), { waitUntil: "networkidle2" });
  const gBefore = await readMonthly(page);
  for (const [period, amount, reason] of [[shift(-2), "", "QA REGISTRY TEST — full waiver"], [shift(-1), "50", "QA REGISTRY TEST — partial waiver"]]) {
    await page.evaluate((p) => { document.querySelector(`[data-testid="waive-${p}"]`).open = true; }, period);
    const form = `[data-testid="waive-${period}"] form`;
    if (amount) await page.$eval(`${form} input[name="waive_amount"]`, (i, v) => { i.value = v; }, amount);
    await page.$eval(`${form} textarea[name="waiver_reason"]`, (t, v) => { t.value = v; }, reason);
    await submit(page, `[data-testid="confirm-waive-${period}"]`);
  }
  m = await readMonthly(page);
  check(`G: ${shift(-2)} waived in full — status "waived", nothing outstanding`, due(m, shift(-2))?.state === "waived" && due(m, shift(-2))?.waived === "৳২০০" && due(m, shift(-2))?.outstanding === "৳০", due(m, shift(-2)));
  check(`G: ${shift(-1)} waived ৳৫০ in part — ৳১৫০ still owed (overdue)`, due(m, shift(-1))?.waived === "৳৫০" && due(m, shift(-1))?.outstanding === "৳১৫০" && due(m, shift(-1))?.state === "overdue", due(m, shift(-1)));
  check("G: a waiver is not a payment — no payment row appeared", m.payments.length === gBefore.payments.length && m.payments.length === 0, m.payments);
  check("G: the history shows both waivers with their reasons", m.history.includes("মাসিক চাঁদা মওকুফ") && m.history.includes("QA REGISTRY TEST — full waiver") && m.history.includes("QA REGISTRY TEST — partial waiver"));
  await shotFull(page, "H1-member-g-waivers-bn");

  // 8. registry filters + bn/en × desktop/390 px
  const expect = { overdue: ["e", "f", "g"], current: ["a", "b"], not_required: ["c", "d", "z"] };
  for (const [standing, letters] of Object.entries(expect)) {
    const listed = await registryCodes(page, standing);
    const others = Object.entries(expect).filter(([s]) => s !== standing).flatMap(([, l]) => l);
    check(`registry filter "${standing}": ${letters.join(", ")} and none of the others`, letters.every((l) => listed.some((r) => r.code === code(l))) && !others.some((l) => listed.some((r) => r.code === code(l))), listed);
  }
  for (const standing of ["due", "partially_paid"]) {
    const listed = await registryCodes(page, standing);
    check(`registry filter "${standing}": no QA member left there now`, listed.length === 0, listed);
  }
  for (const combo of [{ vp: "desktop", locale: "bn" }, { vp: "desktop", locale: "en" }, { vp: "mobile", locale: "bn" }, { vp: "mobile", locale: "en" }]) {
    const tag = `${combo.vp}-${combo.locale}`;
    const ctx = combo.vp === "desktop" && combo.locale === "bn" ? adminCtx : await open({ vp: combo.vp });
    if (ctx !== adminCtx) await adminLogin(ctx.page);
    const p = ctx.page;
    await setAdminLocale(p, combo.locale);
    const all = await registryCodes(p, null);
    check(`registry ${tag}: the monthly column shows every QA member's standing`, all.length >= 8 && all.every((r) => r.standing), all);
    await shot(p, `I1-registry-${tag}`);
    await p.goto(await memberUrl(p, "f"), { waitUntil: "networkidle2" });
    const mm = await readMonthly(p);
    if (combo.locale === "en") {
      check(`member page ${tag}: English labels and Latin digits`, /Monthly contribution/i.test(mm.cardText) && mm.outstanding === "৳600" && !/[০-৯]/.test(mm.cardText), mm.outstanding);
    } else {
      check(`member page ${tag}: Bangla labels and Bangla digits`, /মাসিক চাঁদা/.test(mm.cardText) && mm.outstanding === "৳৬০০" && !/[0-9]/.test(mm.cardText), mm.outstanding);
    }
    await shotSection(p, '[data-testid="monthly-card"]', `I2-member-f-monthly-${tag}`);
    if (ctx !== adminCtx) {
      await setAdminLocale(p, state.adminLocale);
      clean(`admin ${tag}`, ctx.problems);
      await ctx.context.close();
    }
  }

  // 9. F on the portal
  await setAdminLocale(page, "bn");
  let fCookies = []; // the signed-in session, in memory only: the invite link is single-use
  for (const [vp, theme] of [["mobile", "dark"], ["desktop", "light"]]) {
    const ctx = await (vp === "mobile" ? portalSignIn("f", { vp, theme }) : (async () => {
      const c = await open({ vp, theme });
      await c.page.setCookie(...fCookies);
      return c;
    })());
    if (vp === "mobile") fCookies = await ctx.page.cookies(site);
    const fm = await portalMonthly(ctx.page);
    check(`portal F ${vp}: overdue months, outstanding ৳৬০০ and "২ মাস" overdue, all in Bangla`,
      fm.required === "true" && fm.outstandingRaw === "600.00" && fm.outstanding === "৳৬০০" && fm.overdue === "২ মাস" && fm.rows.filter((r) => r.state === "overdue").length === 2 && fm.rows.some((r) => r.period === THIS && r.state === "due") && !fm.latinDigitsInCard,
      { outstanding: fm.outstanding, overdue: fm.overdue, rows: fm.rows.map((r) => `${r.period}:${r.state}`) });
    check(`portal F ${vp}: the months read in Bangla ("${monthLabel(shift(-4))}")`, fm.rows.some((r) => r.text.includes(monthLabel(shift(-4)))));
    check(`portal F ${vp}: no admin note, suspension reason or verifier shown`, !/QA REGISTRY TEST —/.test(fm.page));
    await shotSection(ctx.page, '[data-testid="monthly-contribution"]', `J-portal-f-overdue-${vp}-${theme}`);
    clean(`portal F ${vp}`, ctx.problems);
    await ctx.context.close();
  }

  await setAdminLocale(page, state.adminLocale);
  clean("flow admin", adminCtx.problems);
  await saveState();
  await adminCtx.context.close();
}

try {
  if (phase === "approve") await approvePhase();
  else if (phase === "flow") await flowPhase();
  else throw new Error("--phase approve|flow");
} catch (e) {
  check(`phase ${phase} crashed`, false, String(e?.stack ?? e).slice(0, 800));
} finally {
  await browser.close();
  await saveState();
  await writeFile(resolve(out, `report-dues-${phase}.json`), JSON.stringify({ phase, at: new Date().toISOString(), today: TODAY, failures, results }, null, 2));
  console.log(`\n${phase}: ${results.length - failures}/${results.length} checks passed. Screenshots + report in ${out}`);
  process.exit(failures ? 1 : 0);
}
