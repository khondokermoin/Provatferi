// Production QA for how fast the public membership page follows a membership change (cache invalidation task,
// 2026-10-05). Real headless Chrome, the REAL Admin UI, and a "first request" that is really a first request.
//
//   node scripts/membership-cache-qa.mjs --mode repro|cases --admin-base https://admin.provatferi.org \
//        --site-base https://provatferi.org --admin-email … --admin-password … \
//        [--phase core|boundary|fee-boundary|all] [--expect LM=500/200,GM=100/0,ST=0/0] [--shots dir] [--out report.json]
//
// The admin credentials come from the command line only (never stored, never printed). Every change is made through the
// Admin UI; every public observation is a fresh Chrome context that has never seen the site (so nothing is cached in
// the browser), asked for BOTH /membership and /en/membership at once, so neither can borrow the other's refresh.
//
//   repro   BEFORE the fix. Which layer serves stale membership data, and for how long (R1: season opened while the
//           site's cache is warm; R2: the incident — an OLD cache entry, then a season opened). Disposable season only;
//           aborts, touching nothing, if a real season is open.
//
//   cases   AFTER the fix. Needs the disposable type QZ (server-side: deploy/qa/membership-cache-qa.php seed-type).
//             A  season closed                          form hidden, "contact us" message, in both languages
//             B  Admin opens a season                   the FIRST request shows the form
//             D  type visibility / self-apply / enabled the FIRST request reflects each change
//             E  Admin changes TODAY's fee              the FIRST request quotes the new fee (cards and form panel)
//             F  Admin schedules a FUTURE fee           the page still quotes today's fee
//             S  a visitor applies while it is open     the application is accepted (the form and Laravel agree)
//             C  Admin closes the season                the FIRST request hides the form; a form that was already open on
//                                                       someone's screen is refused by Laravel and SAYS so
//             B2 Admin reopens it                       the FIRST request shows the form again
//             W  Admin edits the season's end date      the FIRST request follows
//             G  (phase boundary) the season's start and end pass by themselves, no Admin action: the FIRST request
//                after each boundary is correct
//             H  (phase fee-boundary, start it 2-15 min before 18:00 UTC) a fee policy takes effect at midnight in
//                Dhaka by itself: the FIRST request after it quotes the new fee
//           Each step reports the time from the Admin's "saved" to the first public request, and whether it was right.
//
// Nothing here prints or stores the credentials. Disposable rows carry the marker "QA CACHE TEST"; the server-side
// script's `cleanup` removes exactly those.

import { existsSync } from "node:fs";
import { mkdir, writeFile } from "node:fs/promises";
import { dirname, resolve } from "node:path";
import puppeteer from "puppeteer-core";

const arg = (n, d = null) => {
  const i = process.argv.indexOf(`--${n}`);
  return i === -1 ? d : process.argv[i + 1];
};
const mode = arg("mode", "repro");
const phase = arg("phase", "all");
const adminBase = (arg("admin-base", "") ?? "").replace(/\/+$/, "");
const siteBase = (arg("site-base", "") ?? "").replace(/\/+$/, "");
const email = arg("admin-email");
const password = arg("admin-password");
const outFile = arg("out");
const shotsDir = arg("shots");
const expectArg = arg("expect", "LM=500/200,GM=100/0,ST=0/0");
const REAL_FEES = Object.fromEntries(expectArg.split(",").map((pair) => { const [code, fees] = pair.split("="); const [r, m] = fees.split("/"); return [code, { registration: r, monthly: m }]; }));
if (!adminBase || !siteBase || !email || !password) {
  console.error("usage: node scripts/membership-cache-qa.mjs --mode repro|cases --admin-base <url> --site-base <url> --admin-email … --admin-password … [--phase core|boundary|fee-boundary|all] [--shots dir] [--out file]");
  process.exit(2);
}

const chrome = [process.env.CHROME_PATH, "C:/Program Files/Google/Chrome/Application/chrome.exe", "C:/Program Files (x86)/Google/Chrome/Application/chrome.exe", "/usr/bin/google-chrome", "/usr/bin/chromium"].filter(Boolean).find(existsSync);
if (!chrome) throw new Error("No Chrome found; set CHROME_PATH");

const QA_SEASON_NAME = "QA CACHE TEST repro";
const CASE_SEASON_NAME = "QA CACHE TEST case season";
const NAMES = {
  LM: { bn: "আজীবন সদস্য", en: "Lifetime Member" },
  GM: { bn: "সাধারণ সদস্য", en: "General Member" },
  ST: { bn: "শিক্ষার্থী সদস্য", en: "Student Member" },
  QZ: { bn: "QA CACHE TEST type", en: "QA CACHE TEST type" },
};
const REAL_TYPE_NAMES = [NAMES.LM.bn, NAMES.GM.bn, NAMES.ST.bn];
const BN_DIGITS = "০১২৩৪৫৬৭৮৯";
const taka = (n, locale) => `৳${locale === "bn" ? String(n).replace(/[0-9]/g, (d) => BN_DIGITS[Number(d)]) : n}`;
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const stamp = (ms = Date.now()) => new Date(ms).toISOString().slice(11, 23);
const say = (...a) => console.log(`[${stamp()}]`, ...a);

// ------------------------------------------------------------------------------------------------ reading the public state

const decode = (s) => String(s).replace(/&amp;/g, "&").replace(/&lt;/g, "<").replace(/&gt;/g, ">").replace(/&quot;/g, '"').replace(/&#x27;|&#39;/g, "'").trim();

/** What a membership page (HTML) says: is the application form there, which types does it offer, what do the cards quote. */
export function readState(html) {
  const hasForm = /<form[^>]*class="[^"]*application-form/.test(html);
  const fallback = html.includes("বর্তমানে কোনো নিবন্ধন সিজন চলমান নেই") || html.includes("There is no membership registration season open right now");
  const selectBlock = /id="membership_type_id"[\s\S]*?<\/select>/.exec(html)?.[0] ?? "";
  const options = [...selectBlock.matchAll(/<option[^>]*>([^<]*)<\/option>/g)].map((m) => decode(m[1])).filter((t) => !/^(নির্বাচন করুন|Select one)$/.test(t));
  const cards = {};
  for (const chunk of html.split('class="info-card"').slice(1)) {
    const name = /<h3>([^<]*)<\/h3>/.exec(chunk)?.[1];
    if (!name) continue;
    cards[decode(name)] = {
      registration: /data-fee="registration">([^<]*)</.exec(chunk)?.[1] ?? null,
      monthly: /data-fee="monthly">([^<]*)</.exec(chunk)?.[1] ?? null,
    };
  }
  return { hasForm, fallback, options, cards };
}

/** One plain HTTP GET with the timing and the cache-related headers. */
async function httpProbe(url, extraHeaders = {}) {
  const t0 = Date.now();
  const res = await fetch(url, { redirect: "manual", headers: { Accept: "text/html,application/json", "User-Agent": "Mozilla/5.0 membership-cache-qa", ...extraHeaders } });
  const body = await res.text();
  const t1 = Date.now();
  return {
    url,
    status: res.status,
    sentAt: t0,
    doneAt: t1,
    ms: t1 - t0,
    serverDate: res.headers.get("date"),
    cf: res.headers.get("cf-cache-status"),
    age: res.headers.get("age"),
    cacheControl: res.headers.get("cache-control"),
    nextCache: res.headers.get("x-nextjs-cache"),
    body,
  };
}

const pageUrl = (locale, cb = false) => `${siteBase}${locale === "en" ? "/en" : ""}/membership${cb ? `?cb=${Date.now()}${Math.floor(Math.random() * 1e6)}` : ""}`;

/** The two Laravel lookups the site's page is built from, straight from Laravel (no site cache in between). */
async function apiTruth() {
  const [campaigns, types] = await Promise.all([
    httpProbe(`${adminBase}/api/v1/public/membership/campaigns/current`),
    httpProbe(`${adminBase}/api/v1/membership-types`),
  ]);
  const c = JSON.parse(campaigns.body);
  const t = JSON.parse(types.body);
  return {
    at: campaigns.doneAt,
    serverDate: campaigns.serverDate,
    cf: campaigns.cf,
    campaignsOpen: (c.data ?? []).map((x) => x.name),
    campaignTypeNames: (c.data ?? []).flatMap((x) => (x.membership_types ?? []).map((m) => m.name)),
    typeNames: (t.data ?? []).map((x) => x.name),
    metaValidUntil: c.meta?.valid_until ?? undefined,
    typesValidUntil: t.meta?.valid_until ?? undefined,
  };
}

// Offset between this machine's clock and the server's, from the Date headers (second resolution, averaged).
let serverOffsetMs = 0;
async function calibrateServerClock() {
  const samples = [];
  for (let i = 0; i < 4; i += 1) {
    const t0 = Date.now();
    const res = await fetch(`${adminBase}/api/v1/membership-types`, { headers: { Accept: "application/json" } });
    await res.arrayBuffer();
    const t1 = Date.now();
    const server = Date.parse(res.headers.get("date") ?? "");
    if (!Number.isNaN(server)) samples.push(server + 500 - (t0 + t1) / 2); // the header is truncated to the second: +0.5 s is the mean of that
    await sleep(300);
  }
  serverOffsetMs = samples.length ? Math.round(samples.reduce((a, b) => a + b, 0) / samples.length) : 0;
  return serverOffsetMs;
}
const serverNow = () => Date.now() + serverOffsetMs;
async function sleepUntilServer(ms) {
  while (serverNow() < ms) await sleep(Math.min(500, Math.max(20, ms - serverNow())));
}
const dhakaDate = (ms) => new Date(ms + 6 * 3600e3).toISOString().slice(0, 10);
const addDays = (ymd, n) => new Date(Date.parse(`${ymd}T00:00:00Z`) + n * 86400e3).toISOString().slice(0, 10);
/** Admin <input type=datetime-local> values are read in the app's timezone, UTC. */
const utcLocal = (ms) => new Date(ms).toISOString().slice(0, 16);

// ------------------------------------------------------------------------------------------------ browser probes

const consoleErrors = [];
const apiCallsFromBrowser = [];
const trackPage = (page, label) => {
  page.on("console", (m) => { if (m.type() === "error" && !m.text().includes("/_next/hmr")) consoleErrors.push(`${label}: ${m.text().slice(0, 200)}`); });
  page.on("pageerror", (e) => consoleErrors.push(`${label}: ${String(e).slice(0, 200)}`));
  page.on("request", (r) => { if (r.url().startsWith(adminBase)) apiCallsFromBrowser.push(`${label}: ${r.url()}`); });
};

async function waitHydrated(page) {
  await page.waitForFunction(() => {
    const f = document.querySelector("form.application-form");
    return f && document.querySelector("#membership_type_id") && Object.keys(f).some((k) => k.startsWith("__reactProps$") || k.startsWith("__reactFiber$"));
  }, { timeout: 30000 });
}

/** Chooses a type in the open form and reads the fee panel — what a visitor sees after picking it. */
async function readFeePanel(page, optionLabel) {
  await waitHydrated(page);
  const value = await page.evaluate((label) => { const o = [...document.querySelectorAll("#membership_type_id option")].find((x) => x.textContent.trim() === label); return o ? o.value : null; }, optionLabel);
  if (!value) return null;
  await page.select("#membership_type_id", value);
  await page.waitForSelector('[data-testid="fee-summary"]', { timeout: 10000 });
  return page.evaluate(() => {
    const dl = document.querySelector('[data-testid="fee-summary"]');
    return { registration: dl.querySelector('[data-fee="registration"]')?.textContent.trim() ?? null, monthly: dl.querySelector('[data-fee="monthly"]')?.textContent.trim() ?? null };
  });
}

/**
 * A first request made by a browser that has never seen the site (a fresh context): the status and cache headers of the
 * document, what the server-rendered page says, and optionally (panels) what the fee panel shows after choosing a type.
 */
async function chromeFirstRequest(browser, url, { locale = "bn", panels = [], shot = null } = {}) {
  const context = await browser.createBrowserContext();
  try {
    const page = await context.newPage();
    trackPage(page, `first-${locale}`);
    await page.setViewport({ width: 1366, height: 900 });
    const t0 = Date.now();
    const resp = await page.goto(url, { waitUntil: "domcontentloaded", timeout: 60000 });
    const t1 = Date.now();
    const html = await page.content();
    const h = resp?.headers() ?? {};
    const state = readState(html);
    const found = { url, status: resp?.status() ?? 0, sentAt: t0, doneAt: t1, ms: t1 - t0, serverDate: h["date"] ?? null, cf: h["cf-cache-status"] ?? null, age: h["age"] ?? null, state, panel: {} };
    if (state.hasForm) {
      for (const code of panels) found.panel[code] = await readFeePanel(page, NAMES[code][locale]);
    }
    if (shot && shotsDir) {
      await mkdir(shotsDir, { recursive: true });
      await page.screenshot({ path: resolve(shotsDir, `${shot}-${locale}.png`), fullPage: true });
    }
    return found;
  } finally {
    await context.close();
  }
}

/** The FIRST requests after a change: bn and en at once, in fresh contexts, with Laravel's own answer beside them. */
async function firstRequests(browser, { panels = [], shot = null } = {}) {
  const [bn, en, truth] = await Promise.all([
    chromeFirstRequest(browser, pageUrl("bn"), { locale: "bn", panels, shot }),
    chromeFirstRequest(browser, pageUrl("en"), { locale: "en", panels, shot }),
    apiTruth(),
  ]);
  return { bn, en, truth };
}

// ------------------------------------------------------------------------------------------------ the real Admin UI

class Admin {
  constructor(page) {
    this.page = page;
  }

  async login() {
    const { page } = this;
    await page.goto(`${adminBase}/login`, { waitUntil: "networkidle0", timeout: 90000 });
    await page.type("#email", email, { delay: 3 });
    await page.type("#password", password, { delay: 3 });
    await Promise.all([page.waitForNavigation({ waitUntil: "networkidle0", timeout: 90000 }), page.click('button[type="submit"]')]);
    if (page.url().includes("/login")) throw new Error("admin login failed");
  }

  /**
   * Submits the form behind `selector` and resolves with the moment the Admin's POST (PUT/PATCH/DELETE are method-spoofed
   * POSTs) response ARRIVED — i.e. when the admin is told it worked. `js: true` clicks a button inside a closed dropdown.
   */
  async submitAndTime(selector, urlPattern, { js = false } = {}) {
    const { page } = this;
    const responded = page.waitForResponse((r) => ["POST", "PUT", "PATCH", "DELETE"].includes(r.request().method()) && urlPattern.test(r.url()), { timeout: 90000 });
    const navigated = page.waitForNavigation({ waitUntil: "networkidle0", timeout: 90000 });
    if (js) await page.$eval(selector, (el) => el.click());
    else await page.click(selector);
    const response = await responded;
    const doneAt = Date.now();
    await navigated;
    const flash = await page.$eval(".alert-success", (el) => el.textContent.replace(/\s+/g, " ").trim()).catch(() => null);
    return { doneAt, status: response.status(), flash };
  }

  async setField(selector, value) {
    await this.page.$eval(selector, (el, v) => { el.value = v; el.dispatchEvent(new Event("input", { bubbles: true })); el.dispatchEvent(new Event("change", { bubbles: true })); }, value);
  }

  async setChecked(selector, on) {
    await this.page.$eval(selector, (el, v) => { el.checked = v; el.dispatchEvent(new Event("change", { bubbles: true })); }, on);
  }

  // ---------------------------------------------------------------- seasons

  /** Creates a season. `typeNames` are the Bangla names of the types to offer (ticks their boxes). */
  async createSeason({ name, status, typeNames, opensAt = "", closesAt = "" }) {
    const { page } = this;
    await page.goto(`${adminBase}/admin/membership/seasons/create`, { waitUntil: "networkidle0", timeout: 90000 });
    await this.setField("#bf-name-bn", name);
    await this.setField("#bf-name-en", name);
    await this.setField("#field-status", status);
    await this.setField("#field-display_order", "99");
    if (opensAt) await this.setField("#field-opens_at", opensAt);
    if (closesAt) await this.setField("#field-closes_at", closesAt);
    const ticked = await page.$$eval('input[name="membership_type_ids[]"]', (boxes, wanted) => {
      const out = [];
      for (const box of boxes) {
        const label = document.querySelector(`label[for="${box.id}"]`)?.textContent.trim();
        const on = wanted.includes(label);
        box.checked = on;
        if (on) out.push(label);
      }
      return out;
    }, typeNames);
    const result = await this.submitAndTime('form[action$="/admin/membership/seasons"] button[type="submit"]', /\/admin\/membership\/seasons$/);
    return { ...result, ticked };
  }

  async seasonIdByName(name) {
    const { page } = this;
    await page.goto(`${adminBase}/admin/membership/seasons`, { waitUntil: "networkidle0", timeout: 90000 });
    return page.$$eval("table tbody tr", (rows, wanted) => {
      for (const tr of rows) {
        if (!tr.textContent.includes(wanted)) continue;
        const m = /seasons\/(\d+)\/edit/.exec(tr.querySelector('a[href*="/edit"]')?.getAttribute("href") ?? "");
        if (m) return Number(m[1]);
      }
      return null;
    }, name);
  }

  /** The season's edit form (PUT): any of status, opensAt, closesAt (a 'YYYY-MM-DDTHH:mm' string, or '' to clear). */
  async editSeason(id, { status, opensAt, closesAt }) {
    const { page } = this;
    await page.goto(`${adminBase}/admin/membership/seasons/${id}/edit`, { waitUntil: "networkidle0", timeout: 90000 });
    if (status !== undefined) await this.setField("#field-status", status);
    if (opensAt !== undefined) await this.setField("#field-opens_at", opensAt);
    if (closesAt !== undefined) await this.setField("#field-closes_at", closesAt);
    return this.submitAndTime(`form[action$="/seasons/${id}"] button[type="submit"]`, new RegExp(`/seasons/${id}$`));
  }

  /** The index page's "change status" dialog (PATCH /status) — what an admin does to open or close a season. */
  async setSeasonStatus(id, status) {
    const { page } = this;
    await page.goto(`${adminBase}/admin/membership/seasons`, { waitUntil: "networkidle0", timeout: 90000 });
    await page.$eval(`button[data-bs-target="#status-${id}"]`, (el) => el.click());
    await page.waitForSelector(`#status-${id}.show`, { visible: true, timeout: 15000 });
    await page.select(`#status-form-${id} select[name="status"]`, status);
    return this.submitAndTime(`button[type="submit"][form="status-form-${id}"]`, new RegExp(`/seasons/${id}/status$`));
  }

  async deleteSeason(id) {
    const { page } = this;
    await page.goto(`${adminBase}/admin/membership/seasons`, { waitUntil: "networkidle0", timeout: 90000 });
    await page.$eval(`button[data-bs-target="#delete-${id}"]`, (el) => el.click());
    await page.waitForSelector(`#delete-${id}.show`, { visible: true, timeout: 15000 });
    return this.submitAndTime(`#delete-${id} button[type="submit"]`, new RegExp(`/seasons/${id}$`));
  }

  // ---------------------------------------------------------------- types and fee policies

  async typeIdByCode(code) {
    const { page } = this;
    await page.goto(`${adminBase}/admin/membership/types`, { waitUntil: "networkidle0", timeout: 90000 });
    return page.$$eval("table tbody tr", (rows, wanted) => {
      for (const tr of rows) {
        const tds = tr.querySelectorAll("td");
        if (tds.length < 8) continue;
        if (tds[1].querySelector(".badge.bg-primary-subtle")?.textContent.trim() !== wanted) continue;
        const m = /types\/(\d+)/.exec(tds[1].querySelector("a")?.getAttribute("href") ?? "");
        if (m) return Number(m[1]);
      }
      return null;
    }, code);
  }

  /** The type's edit form (PUT): visibility and self-apply checkboxes, the rest left as it was. */
  async setTypeFlags(id, { visible, selfApply }) {
    const { page } = this;
    await page.goto(`${adminBase}/admin/membership/types/${id}/edit`, { waitUntil: "networkidle0", timeout: 90000 });
    if (visible !== undefined) await this.setChecked("#is_public_visible", visible);
    if (selfApply !== undefined) await this.setChecked("#is_public_self_apply", selfApply);
    return this.submitAndTime(`form[action$="/membership/types/${id}"] button[type="submit"]`, new RegExp(`/types/${id}$`));
  }

  /** The list's enable/disable action (PATCH /toggle). */
  async toggleType(id) {
    const { page } = this;
    await page.goto(`${adminBase}/admin/membership/types`, { waitUntil: "networkidle0", timeout: 90000 });
    return this.submitAndTime(`form[action$="/types/${id}/toggle"] button`, new RegExp(`/types/${id}/toggle$`), { js: true });
  }

  /** The type page's "new fee policy" form. */
  async createFeePolicy(id, { registration, monthly, from, note }) {
    const { page } = this;
    await page.goto(`${adminBase}/admin/membership/types/${id}`, { waitUntil: "networkidle0", timeout: 90000 });
    await this.setField("#field-registration_fee", registration);
    await this.setField("#field-monthly_contribution", monthly);
    await this.setField("#field-effective_from", from);
    await page.type("#field-note", note);
    return this.submitAndTime('form[action$="/fee-policies"] button[type="submit"]', new RegExp(`/types/${id}/fee-policies$`));
  }
}

// ------------------------------------------------------------------------------------------------ the reproduction (before the fix)

async function repro(browser, admin) {
  const out = { startedAt: new Date().toISOString(), r1: {}, r2: {} };

  // R0: the baseline. Refuse to run if a real season is open: this experiment must not touch what visitors can use.
  const truth0 = await apiTruth();
  out.baseline = { api: truth0 };
  say("baseline from Laravel:", JSON.stringify({ campaignsOpen: truth0.campaignsOpen, types: truth0.typeNames.length, cf: truth0.cf }));
  if (truth0.campaignsOpen.length > 0) throw new Error(`a season is already open (${truth0.campaignsOpen.join(", ")}) — not touching it`);

  // warm the site's cache (two rounds so the second is certainly served from it)
  for (let i = 0; i < 2; i += 1) {
    for (const locale of ["bn", "en"]) {
      const p = await httpProbe(pageUrl(locale));
      const s = readState(p.body);
      if (i === 1) {
        say(`warm ${locale}: HTTP ${p.status} ${p.ms} ms hasForm=${s.hasForm} fallback=${s.fallback} cf=${p.cf} age=${p.age} cache-control="${p.cacheControl}"`);
        out.baseline[`warm_${locale}`] = { status: p.status, cf: p.cf, age: p.age, cacheControl: p.cacheControl, nextCache: p.nextCache, hasForm: s.hasForm, fallback: s.fallback, serverDate: p.serverDate };
      }
    }
  }
  const warmedAt = Date.now();

  // ---- R1: open a season while the cache is warm
  say("R1: creating a disposable OPEN season through the real Admin form …");
  const created = await admin.createSeason({ name: QA_SEASON_NAME, status: "open", typeNames: REAL_TYPE_NAMES });
  const changeAt = created.doneAt;
  const seasonId = await admin.seasonIdByName(QA_SEASON_NAME);
  say(`R1: Admin answered HTTP ${created.status} at ${stamp(changeAt)} (${((changeAt - warmedAt) / 1000).toFixed(1)} s after the cache was warmed); season id ${seasonId}; ticked ${created.ticked.length} types`);
  out.r1.admin = { status: created.status, flash: created.flash, seasonId, ticked: created.ticked, at: new Date(changeAt).toISOString() };

  out.r1.polls = [];
  let flippedAt = null;
  const deadline = changeAt + 200000;
  let n = 0;
  while (Date.now() < deadline) {
    const [api, bn, en, bnCb] = await Promise.all([apiTruth(), httpProbe(pageUrl("bn")), httpProbe(pageUrl("en")), httpProbe(pageUrl("bn", true))]);
    const sBn = readState(bn.body);
    const sEn = readState(en.body);
    const sCb = readState(bnCb.body);
    const row = { n: (n += 1), sinceChangeS: Number(((bn.sentAt - changeAt) / 1000).toFixed(1)), api_season_open: api.campaignsOpen.length > 0, bn_form: sBn.hasForm, bn_cb_form: sCb.hasForm, en_form: sEn.hasForm, cf: bn.cf, age: bn.age, bn_ms: bn.ms };
    out.r1.polls.push(row);
    say(`R1 poll ${row.n} +${row.sinceChangeS}s  Laravel says open=${row.api_season_open}  page bn form=${row.bn_form}  bn(?cb) form=${row.bn_cb_form}  en form=${row.en_form}  cf=${row.cf}`);
    if (row.bn_form && row.en_form && flippedAt === null) {
      flippedAt = Date.now();
      break;
    }
    await sleep(2000);
  }
  out.r1.staleForS = flippedAt === null ? null : Number(((flippedAt - changeAt) / 1000).toFixed(1));
  out.r1.firstProbe = out.r1.polls[0];
  say(`R1 result: the public page reflected the opened season ${out.r1.staleForS === null ? "NEVER within 200 s" : `${out.r1.staleForS} s after the Admin's save`}`);

  // ---- R2: the incident. Close the season, wait until the site shows it closed (its cache now holds "closed"), go idle
  // so that entry grows OLD, then open the season again and look at the very FIRST request.
  say("R2: closing the season through the real Admin status dialog …");
  const closed = await admin.setSeasonStatus(seasonId, "closed");
  const closeAt = closed.doneAt;
  out.r2.close = { status: closed.status, at: new Date(closeAt).toISOString() };
  let closedSeenAt = null;
  out.r2.closePolls = [];
  while (Date.now() < closeAt + 200000) {
    const [bn, en] = await Promise.all([httpProbe(pageUrl("bn")), httpProbe(pageUrl("en"))]);
    const sBn = readState(bn.body);
    const sEn = readState(en.body);
    out.r2.closePolls.push({ sinceCloseS: Number(((bn.sentAt - closeAt) / 1000).toFixed(1)), bn_form: sBn.hasForm, en_form: sEn.hasForm });
    say(`R2 (closing) +${((bn.sentAt - closeAt) / 1000).toFixed(1)}s  bn form=${sBn.hasForm}  en form=${sEn.hasForm}`);
    if (!sBn.hasForm && !sEn.hasForm) {
      closedSeenAt = Date.now();
      break;
    }
    await sleep(2000);
  }
  out.r2.staleAfterCloseS = closedSeenAt === null ? null : Number(((closedSeenAt - closeAt) / 1000).toFixed(1));
  say(`R2: after the Admin CLOSED it, the page kept showing the form for ${out.r2.staleAfterCloseS} s`);

  const IDLE_S = 80;
  say(`R2: now idle for ${IDLE_S} s with NO request to the site (the cached "closed" entry grows old) …`);
  await sleep(IDLE_S * 1000);

  say("R2: opening the season again through the Admin status dialog, then the FIRST request …");
  const opened = await admin.setSeasonStatus(seasonId, "open");
  const openAt = opened.doneAt;
  const first = await firstRequests(browser);
  out.r2.open = { status: opened.status, at: new Date(openAt).toISOString(), idleBeforeS: IDLE_S };
  out.r2.first = {
    laravel_says_open: first.truth.campaignsOpen.length > 0,
    bn: { sinceOpenS: Number(((first.bn.sentAt - openAt) / 1000).toFixed(2)), status: first.bn.status, hasForm: first.bn.state.hasForm, cf: first.bn.cf, age: first.bn.age, serverDate: first.bn.serverDate },
    en: { sinceOpenS: Number(((first.en.sentAt - openAt) / 1000).toFixed(2)), status: first.en.status, hasForm: first.en.state.hasForm, cf: first.en.cf, age: first.en.age, serverDate: first.en.serverDate },
  };
  say(`R2 FIRST requests (real Chrome, parallel): Laravel says open=${out.r2.first.laravel_says_open}; bn form=${out.r2.first.bn.hasForm} en form=${out.r2.first.en.hasForm}`);
  const seconds = [];
  for (let i = 0; i < 4; i += 1) {
    const [bn, en] = await Promise.all([httpProbe(pageUrl("bn")), httpProbe(pageUrl("en"))]);
    seconds.push({ sinceOpenS: Number(((bn.sentAt - openAt) / 1000).toFixed(2)), bn_form: readState(bn.body).hasForm, en_form: readState(en.body).hasForm });
    await sleep(1500);
  }
  out.r2.then = seconds;
  say("R2 following requests:", JSON.stringify(seconds));

  // ---- leave the site as found: season closed, then removed from the Admin's list
  const closedAgain = await admin.setSeasonStatus(seasonId, "closed");
  say(`closed the disposable season again (HTTP ${closedAgain.status})`);
  const deleted = await admin.deleteSeason(seasonId);
  say(`removed it from the Admin list (HTTP ${deleted.status}, soft delete); the server-side cleanup script force-deletes it later`);
  out.endedAt = new Date().toISOString();
  return out;
}

// ------------------------------------------------------------------------------------------------ the cases (after the fix)

async function cases(browser, admin) {
  const out = { startedAt: new Date().toISOString(), phase, steps: [] };
  const rows = [];
  const expect = (group, name, ok, detail = "") => {
    rows.push({ group, name, ok: Boolean(ok), detail: ok ? "" : String(detail).slice(0, 400) });
    if (!ok) say(`  FAIL ${group} — ${name} [${String(detail).slice(0, 300)}]`);
  };

  /** Verifies one pair of first requests against what the page must say, in both languages. */
  const verify = (group, first, adminDoneAt, want) => {
    const timing = {};
    for (const locale of ["bn", "en"]) {
      const probe = first[locale];
      const g = `${group} [${locale}]`;
      const s = probe.state;
      timing[locale] = Number(((probe.sentAt - adminDoneAt) / 1000).toFixed(2));
      expect(g, "HTTP 200", probe.status === 200, probe.status);
      expect(g, "served live, not from a CDN copy (cf-cache-status DYNAMIC, no Age)", (probe.cf === null || probe.cf === "DYNAMIC") && !probe.age, `${probe.cf} age=${probe.age}`);
      if (want.form !== undefined) {
        expect(g, want.form ? "the application form is shown" : "no application form", s.hasForm === want.form, `hasForm=${s.hasForm}`);
        if (!want.form) expect(g, "the 'no season open — contact us' message is shown", s.fallback, "fallback text missing");
      }
      for (const code of want.optionsHave ?? []) expect(g, `the form offers ${code}`, s.options.includes(NAMES[code][locale]), JSON.stringify(s.options));
      for (const code of want.optionsLack ?? []) expect(g, `the form does NOT offer ${code}`, !s.options.includes(NAMES[code][locale]), JSON.stringify(s.options));
      for (const [code, [r, m]] of Object.entries(want.cardsHave ?? {})) {
        const card = s.cards[NAMES[code][locale]];
        expect(g, `the ${code} card quotes ${taka(r, locale)} / ${taka(m, locale)}`, card && card.registration === taka(r, locale) && card.monthly === taka(m, locale), JSON.stringify(card));
      }
      for (const code of want.cardsLack ?? []) expect(g, `no ${code} card`, !s.cards[NAMES[code][locale]], JSON.stringify(Object.keys(s.cards)));
      for (const [code, [r, m]] of Object.entries(want.panelHave ?? {})) {
        const panel = probe.panel[code];
        expect(g, `choosing ${code} in the form shows ${taka(r, locale)} / ${taka(m, locale)}`, panel && panel.registration === taka(r, locale) && panel.monthly === taka(m, locale), JSON.stringify(panel));
      }
    }
    expect(group, "Laravel and the page agree about whether a season is open", (first.truth.campaignsOpen.length > 0) === first.bn.state.hasForm && (first.truth.campaignsOpen.length > 0) === first.en.state.hasForm, `laravel=${first.truth.campaignsOpen.length} bn=${first.bn.state.hasForm} en=${first.en.state.hasForm}`);
    out.steps.push({ group, sinceAdminSaveS: timing, correct: rows.filter((r) => r.group.startsWith(group)).every((r) => r.ok) });
    say(`${group}: first requests ${timing.bn}s / ${timing.en}s after the Admin's "saved" — ${out.steps.at(-1).correct ? "correct" : "WRONG"}`);
  };

  await calibrateServerClock();
  say(`server clock is ${serverOffsetMs} ms ahead of this machine`);
  const today = dhakaDate(serverNow());

  // ---- preconditions: nothing real is open, and the disposable type exists
  const truth0 = await apiTruth();
  if (truth0.campaignsOpen.length > 0 && !truth0.campaignsOpen.includes(CASE_SEASON_NAME)) throw new Error(`a real season is open (${truth0.campaignsOpen.join(", ")}) — not touching it`);
  const qzId = await admin.typeIdByCode("QZ");
  if (!qzId) throw new Error("the disposable type QZ is missing — run: php deploy/qa/membership-cache-qa.php seed-type");
  let seasonId = await admin.seasonIdByName(CASE_SEASON_NAME);
  const fullFees = (extra = {}) => ({ ...Object.fromEntries(Object.entries(REAL_FEES).map(([code, f]) => [code, [f.registration, f.monthly]])), ...extra });

  if (phase === "core" || phase === "all") {
    if (seasonId) throw new Error(`the disposable season already exists (#${seasonId}) — run cleanup first`);

    // ---- A: closed
    say("A: season closed — the baseline");
    let first = await firstRequests(browser, { shot: "A-closed" });
    verify("A closed", first, Date.now() - 1000, { form: false, cardsHave: fullFees(), cardsLack: ["QZ"] });

    // ---- B: the Admin opens a season (QZ is offered by it but still hidden from the public)
    say("B: the Admin creates and opens a season …");
    const created = await admin.createSeason({ name: CASE_SEASON_NAME, status: "open", typeNames: [...REAL_TYPE_NAMES, NAMES.QZ.bn] });
    seasonId = await admin.seasonIdByName(CASE_SEASON_NAME);
    expect("B setup", "the Admin saved the season", created.status === 302 && created.flash, JSON.stringify(created));
    first = await firstRequests(browser, { panels: ["LM"], shot: "B-opened" });
    verify("B opened", first, created.doneAt, { form: true, optionsHave: ["LM", "GM", "ST"], optionsLack: ["QZ"], cardsHave: fullFees(), cardsLack: ["QZ"], panelHave: { LM: [REAL_FEES.LM.registration, REAL_FEES.LM.monthly] } });

    // ---- D2: the Admin makes QZ visible
    say("D2: the Admin makes the type visible …");
    let saved = await admin.setTypeFlags(qzId, { visible: true });
    first = await firstRequests(browser, { panels: ["QZ"], shot: "D2-visible" });
    verify("D2 type made visible", first, saved.doneAt, { form: true, optionsHave: ["LM", "GM", "ST", "QZ"], cardsHave: fullFees({ QZ: ["111", "22"] }), panelHave: { QZ: ["111", "22"] } });

    // ---- E: the Admin changes TODAY's fee
    say("E: the Admin changes today's fee (111/22 -> 333/44) …");
    saved = await admin.createFeePolicy(qzId, { registration: "333", monthly: "44", from: today, note: "QA CACHE TEST policy — today's fee changed by the Admin" });
    expect("E setup", "the Admin saved the policy", saved.status === 302 && saved.flash, JSON.stringify(saved));
    first = await firstRequests(browser, { panels: ["QZ"], shot: "E-fee-changed" });
    verify("E current fee changed", first, saved.doneAt, { form: true, cardsHave: fullFees({ QZ: ["333", "44"] }), panelHave: { QZ: ["333", "44"] } });

    // ---- F: the Admin schedules a FUTURE fee: nothing changes today
    say("F: the Admin schedules a future fee …");
    saved = await admin.createFeePolicy(qzId, { registration: "555", monthly: "66", from: addDays(today, 3), note: "QA CACHE TEST policy — a future fee" });
    expect("F setup", "the Admin saved the future policy", saved.status === 302 && saved.flash, JSON.stringify(saved));
    first = await firstRequests(browser, { panels: ["QZ"], shot: "F-future-fee" });
    verify("F future fee scheduled", first, saved.doneAt, { form: true, cardsHave: fullFees({ QZ: ["333", "44"] }), panelHave: { QZ: ["333", "44"] } });

    // ---- D3: self-apply off, then on
    say("D3: the Admin turns self-apply off, then on …");
    saved = await admin.setTypeFlags(qzId, { selfApply: false });
    first = await firstRequests(browser);
    verify("D3 self-apply off", first, saved.doneAt, { form: true, optionsLack: ["QZ"], optionsHave: ["LM"], cardsHave: fullFees({ QZ: ["333", "44"] }) });
    saved = await admin.setTypeFlags(qzId, { selfApply: true });
    first = await firstRequests(browser, { panels: ["QZ"] });
    verify("D3 self-apply back on", first, saved.doneAt, { form: true, optionsHave: ["QZ"], panelHave: { QZ: ["333", "44"] } });

    // ---- D4: disable, then enable (the list's toggle)
    say("D4: the Admin disables, then enables the type …");
    saved = await admin.toggleType(qzId);
    first = await firstRequests(browser);
    verify("D4 type disabled", first, saved.doneAt, { form: true, optionsLack: ["QZ"], optionsHave: ["LM"], cardsLack: ["QZ"] });
    saved = await admin.toggleType(qzId);
    first = await firstRequests(browser, { panels: ["QZ"] });
    verify("D4 type enabled", first, saved.doneAt, { form: true, optionsHave: ["QZ"], cardsHave: fullFees({ QZ: ["333", "44"] }), panelHave: { QZ: ["333", "44"] } });

    // ---- S: a visitor applies while the season is open; the form and Laravel agree
    say("S: a visitor applies through the form while the season is open …");
    const apply = await applyThroughTheForm(browser, "en", "QA CACHE TEST accepted", "QZ");
    expect("S apply while open", "the application is accepted and a number shown", apply.applicationNo, apply.text);

    // ---- C: the Admin closes the season; a form already open on someone's screen must be refused, and say so
    say("C: a visitor has the form open; the Admin closes the season …");
    const stale = await openFormPage(browser, "en");
    saved = await admin.setSeasonStatus(seasonId, "closed");
    first = await firstRequests(browser, { shot: "C-closed" });
    verify("C closed", first, saved.doneAt, { form: false, cardsHave: fullFees({ QZ: ["333", "44"] }) });
    const refused = await submitOpenForm(stale, "QA CACHE TEST stale-form", "GM");
    expect("C stale form", "Laravel refuses an application to the closed season, and the form SAYS so", refused.notice && /not being accepted for this season/.test(refused.notice), JSON.stringify(refused));
    expect("C stale form", "no success message was shown", !refused.success, JSON.stringify(refused));
    await stale.context.close();

    // ---- B2: the Admin reopens it
    say("B2: the Admin reopens the season …");
    saved = await admin.setSeasonStatus(seasonId, "open");
    first = await firstRequests(browser, { shot: "B2-reopened" });
    verify("B2 reopened", first, saved.doneAt, { form: true, optionsHave: ["LM", "GM", "ST", "QZ"] });

    // ---- W: the Admin edits the season's end date (open by status, ended by date) and puts it back
    say("W: the Admin sets the end date to two minutes ago, then clears it …");
    saved = await admin.editSeason(seasonId, { opensAt: utcLocal(serverNow() - 86400e3), closesAt: utcLocal(serverNow() - 120e3) });
    expect("W setup", "the Admin saved the dates", saved.status === 302 && saved.flash, JSON.stringify(saved));
    first = await firstRequests(browser);
    verify("W end date in the past", first, saved.doneAt, { form: false });
    saved = await admin.editSeason(seasonId, { closesAt: "" });
    first = await firstRequests(browser);
    verify("W end date cleared", first, saved.doneAt, { form: true, optionsHave: ["LM", "QZ"] });

    // ---- R: nothing polls — the page makes no requests of its own once loaded, and the browser never talks to Laravel
    say("R: a loaded page makes no requests of its own …");
    const quiet = await quietPage(browser, "en");
    expect("R no polling", `after load the page made ${quiet.after - quiet.atLoad} further request(s) in 5 s (HMR/analytics none expected)`, quiet.after === quiet.atLoad, JSON.stringify(quiet));
  }

  if (phase === "boundary" || phase === "all") {
    if (!seasonId) throw new Error("the disposable season does not exist — run phase core first");
    // ---- G: the season starts and ends by itself — NO Admin action at the boundary
    const minute = 60000;
    const opensAt = Math.ceil((serverNow() + 80000) / minute) * minute; // the next whole minute, at least 80 s away
    const closesAt = opensAt + 2 * minute;
    say(`G: the Admin sets the season to start at ${stamp(opensAt)} and end at ${stamp(closesAt)} (server time) — and then touches nothing`);
    const saved = await admin.editSeason(seasonId, { status: "open", opensAt: utcLocal(opensAt), closesAt: utcLocal(closesAt) });
    expect("G setup", "the Admin saved the window", saved.status === 302 && saved.flash, JSON.stringify(saved));
    let first = await firstRequests(browser);
    verify("G before it starts", first, saved.doneAt, { form: false });
    expect("G before it starts", "Laravel names the instant the answer will change (valid_until = the start)", first.truth.metaValidUntil && Math.abs(Date.parse(first.truth.metaValidUntil) - opensAt) < 1000, `${first.truth.metaValidUntil} vs ${new Date(opensAt).toISOString()}`);

    await sleepUntilServer(opensAt - 4000);
    first = await firstRequests(browser);
    verify("G just before the start (-4 s)", first, saved.doneAt, { form: false });

    await sleepUntilServer(opensAt + 2500);
    first = await firstRequests(browser, { shot: "G-after-start" });
    verify("G just after the start (+2.5 s), no Admin action", first, saved.doneAt, { form: true, optionsHave: ["LM", "GM", "ST"] });
    out.steps.at(-1).boundary = { instant: new Date(opensAt).toISOString(), firstRequestSecondsAfter: Number(((first.bn.sentAt + serverOffsetMs - opensAt) / 1000).toFixed(2)) };

    await sleepUntilServer(closesAt - 4000);
    first = await firstRequests(browser);
    verify("G just before the end (-4 s)", first, saved.doneAt, { form: true });

    await sleepUntilServer(closesAt + 2500);
    first = await firstRequests(browser, { shot: "G-after-end" });
    verify("G just after the end (+2.5 s), no Admin action", first, saved.doneAt, { form: false });
    out.steps.at(-1).boundary = { instant: new Date(closesAt).toISOString(), firstRequestSecondsAfter: Number(((first.bn.sentAt + serverOffsetMs - closesAt) / 1000).toFixed(2)) };
  }

  if (phase === "fee-boundary") {
    // ---- H: a fee policy takes effect at midnight on the organisation's calendar (Asia/Dhaka) — NO Admin action then
    const tomorrow = addDays(today, 1);
    const midnight = Date.parse(`${tomorrow}T00:00:00+06:00`);
    const away = midnight - serverNow();
    if (away > 15 * 60e3 || away < 100e3) throw new Error(`the next Dhaka midnight is ${Math.round(away / 1000)} s away — start this phase between 15 min and 100 s before it`);
    say(`H: the Admin schedules a fee from ${tomorrow} (00:00 in Dhaka = ${new Date(midnight).toISOString()}) and shows the type; then touches nothing`);
    let saved = await admin.createFeePolicy(qzId, { registration: "777", monthly: "88", from: tomorrow, note: "QA CACHE TEST policy — takes effect at the next Dhaka midnight" });
    expect("H setup", "the Admin saved the policy for tomorrow", saved.status === 302 && saved.flash, JSON.stringify(saved));
    saved = await admin.setTypeFlags(qzId, { visible: true });
    let first = await firstRequests(browser);
    verify("H before midnight", first, saved.doneAt, { cardsHave: fullFees({ QZ: ["333", "44"] }) });
    expect("H before midnight", "Laravel names midnight in Dhaka as the instant the type list changes by itself", first.truth.typesValidUntil && Date.parse(first.truth.typesValidUntil) === midnight, `${first.truth.typesValidUntil} vs ${new Date(midnight).toISOString()}`);

    await sleepUntilServer(midnight - 4000);
    first = await firstRequests(browser);
    verify("H just before midnight (-4 s)", first, saved.doneAt, { cardsHave: fullFees({ QZ: ["333", "44"] }) });

    await sleepUntilServer(midnight + 2500);
    first = await firstRequests(browser, { shot: "H-after-midnight" });
    verify("H just after midnight (+2.5 s), no Admin action", first, saved.doneAt, { cardsHave: fullFees({ QZ: ["777", "88"] }) });
    out.steps.at(-1).boundary = { instant: new Date(midnight).toISOString(), firstRequestSecondsAfter: Number(((first.bn.sentAt + serverOffsetMs - midnight) / 1000).toFixed(2)) };
  }

  // ---- leave the site as found: the season closed and the type hidden (the server-side cleanup then removes both)
  if (seasonId) {
    const closed = await admin.setSeasonStatus(seasonId, "closed");
    say(`finished: season closed again (HTTP ${closed.status})`);
  }
  const hid = await admin.setTypeFlags(qzId, { visible: false });
  say(`finished: the disposable type hidden again (HTTP ${hid.status})`);
  const last = await firstRequests(browser);
  verify("end state", last, hid.doneAt, { form: false, cardsHave: fullFees(), cardsLack: ["QZ"] });

  expect("console", "no console error or page error on any public page", consoleErrors.length === 0, consoleErrors.join(" | "));
  expect("browser", "the browser never calls Laravel's API itself (all data is fetched server-side)", apiCallsFromBrowser.length === 0, apiCallsFromBrowser.slice(0, 3).join(" | "));
  out.checks = rows;
  out.passed = rows.filter((r) => r.ok).length;
  out.failed = rows.filter((r) => !r.ok).length;
  out.endedAt = new Date().toISOString();
  return out;
}

// ---- helpers for the form-driven steps

/** Opens the public form in a fresh context and waits until it is interactive. The caller closes `.context`. */
async function openFormPage(browser, locale) {
  const context = await browser.createBrowserContext();
  const page = await context.newPage();
  trackPage(page, `form-${locale}`);
  await page.setViewport({ width: 1366, height: 900 });
  await page.goto(pageUrl(locale), { waitUntil: "domcontentloaded", timeout: 60000 });
  await waitHydrated(page);
  return { context, page, locale };
}

/** Fills and submits the open form; returns what the visitor sees next. */
async function submitOpenForm({ page, locale }, applicantName, typeCode) {
  const value = await page.evaluate((label) => { const o = [...document.querySelectorAll("#membership_type_id option")].find((x) => x.textContent.trim() === label); return o ? o.value : null; }, NAMES[typeCode][locale]);
  if (!value) return { error: `type ${typeCode} is not offered in the form` };
  await page.select("#membership_type_id", value);
  await page.type("#applicant_name", applicantName);
  await page.type("#applicant_email", "qa-cache-test@example.com");
  await page.type("#applicant_phone", "01712345678");
  await page.click('button[type="submit"]');
  await page.waitForFunction(() => document.querySelector('[data-testid="season-closed"]') || /Application number|আবেদন নম্বর/.test(document.body.innerText) || document.querySelector(".form-field-error"), { timeout: 30000 });
  return page.evaluate(() => {
    const body = document.body.innerText;
    return {
      notice: document.querySelector('[data-testid="season-closed"]')?.textContent.trim() ?? null,
      success: /Application number|আবেদন নম্বর/.test(body),
      applicationNo: (/(APP-\d{4}-\d{4})/.exec(body) ?? [])[1] ?? null,
      firstError: document.querySelector(".form-field-error")?.textContent.trim() ?? null,
    };
  });
}

async function applyThroughTheForm(browser, locale, applicantName, typeCode) {
  const form = await openFormPage(browser, locale);
  try {
    const result = await submitOpenForm(form, applicantName, typeCode);
    return { applicationNo: result.applicationNo ?? null, text: JSON.stringify(result) };
  } finally {
    await form.context.close();
  }
}

/** Loads the page and counts the requests it makes before and after a 5-second quiet period. */
async function quietPage(browser, locale) {
  const context = await browser.createBrowserContext();
  try {
    const page = await context.newPage();
    trackPage(page, `quiet-${locale}`);
    let count = 0;
    page.on("request", (r) => { if (!r.url().includes("/_next/hmr")) count += 1; });
    await page.goto(pageUrl(locale), { waitUntil: "networkidle0", timeout: 60000 });
    const atLoad = count;
    await sleep(5000);
    return { atLoad, after: count };
  } finally {
    await context.close();
  }
}

// ------------------------------------------------------------------------------------------------ main

const browser = await puppeteer.launch({ executablePath: chrome, headless: true, args: ["--no-sandbox", "--disable-dev-shm-usage"] });
let report;
let failure = null;
try {
  const context = await browser.createBrowserContext();
  const page = await context.newPage();
  await page.setViewport({ width: 1366, height: 900 });
  const admin = new Admin(page);
  await admin.login();
  if (mode === "repro") report = await repro(browser, admin);
  else if (mode === "cases") report = await cases(browser, admin);
  else throw new Error(`unknown mode ${mode}`);
} catch (err) {
  failure = err;
} finally {
  await browser.close();
}

if (outFile) {
  await mkdir(dirname(resolve(outFile)), { recursive: true }).catch(() => {});
  await writeFile(outFile, JSON.stringify({ mode, report: report ?? null, error: failure ? String(failure.message ?? failure) : null }, null, 2));
}
if (report?.checks) {
  for (const r of report.checks) console.log(`${r.ok ? "PASS" : "FAIL"}  ${r.group} — ${r.name}${r.ok ? "" : `  [${r.detail}]`}`);
  console.log(`\n${report.passed}/${report.checks.length} checks passed`);
}
if (failure) {
  console.error("FAILED:", failure.message ?? failure);
  process.exit(1);
}
process.exit(report?.failed ? 1 : 0);
