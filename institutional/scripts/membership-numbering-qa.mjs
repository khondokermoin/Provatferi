// Real-Chrome acceptance of Membership task 3 (2026-10-07): member numbers PLCC-{type code}-{year}-{nnnn} and
// application numbers APP-{year}-{nnnn} from their own counters, on the real public site and the real admin panel.
// Only disposable "QA REGISTRY TEST" data is created; admin-erp/deploy/qa/membership-registry-qa.php `cleanup` removes it
// and gives back the numbers only QA rows held. Admin credentials are CLI arguments only (never stored); the QA member's
// portal password is generated here and never printed.
//
//   node scripts/membership-numbering-qa.mjs --phase <apply|review|portal> --site <public url> --admin <admin url> \
//        --email <admin e-mail> --password <admin password> --state <state.json> --out <dir> \
//        [--season-id <id>] [--invite-url <url>] [--fresh]
//
//   apply   three applications through the REAL public form one after the other — Student (bn, desktop), General (en,
//           390 px), Lifetime (bn, 390 px, dark): consecutive APP numbers; then TWO Student applications sent at the same
//           instant through the site's own submit endpoint: two different, consecutive numbers
//   review  admin: the approval check names the next member number; Student, General (cash recorded + verified),
//           Lifetime (the same) approved → PLCC-ST/GM/LM-{year}-0001 (with --fresh: exactly 0001); the Student's approval
//           sent twice at once and retried → one number; the two simultaneous Students approved at the same instant →
//           0002 and 0003 in some order; numbers in the applications list, the review page, the registry (bn/en,
//           desktop/390 px), exact / partial / short search, the type filter, the member page, the type pages (the type
//           without a code says why it cannot issue numbers)
//   portal  the first Student sets a password through the real reset page and signs in: the dashboard shows the number
// Every phase also fails on console errors, page errors and failed requests.

import { existsSync } from "node:fs";
import { mkdir, readFile, writeFile } from "node:fs/promises";
import { resolve } from "node:path";
import { randomBytes } from "node:crypto";
import puppeteer from "puppeteer-core";

const arg = (n, d = null) => {
  const i = process.argv.indexOf(`--${n}`);
  return i === -1 ? d : process.argv[i + 1];
};
const flag = (n) => process.argv.includes(`--${n}`);
const phase = arg("phase");
const site = (arg("site") ?? "").replace(/\/+$/, "");
const admin = (arg("admin") ?? "").replace(/\/+$/, "");
const adminEmail = arg("email");
const adminPassword = arg("password");
const fresh = flag("fresh"); // the counters were untouched before this run: the first numbers must be exactly 0001
const stateFile = resolve(arg("state", "./numbering-qa-state.json"));
const out = resolve(arg("out", "./numbering-qa"));
const chrome = [process.env.CHROME_PATH, "C:/Program Files/Google/Chrome/Application/chrome.exe", "C:/Program Files (x86)/Google/Chrome/Application/chrome.exe", "/usr/bin/google-chrome", "/usr/bin/chromium"].filter(Boolean).find(existsSync);
if (!chrome) throw new Error("No Chrome found; set CHROME_PATH");
await mkdir(out, { recursive: true });

const state = existsSync(stateFile) ? JSON.parse(await readFile(stateFile, "utf8")) : { suffix: randomBytes(3).toString("hex"), applications: {} };
const saveState = () => writeFile(stateFile, JSON.stringify(state, null, 2));
const VIEWPORTS = { desktop: { width: 1440, height: 900 }, mobile: { width: 390, height: 844, deviceScaleFactor: 3, isMobile: true, hasTouch: true } };
const results = [];
let failures = 0;
const check = (name, ok, detail) => {
  results.push({ phase, name, ok: !!ok, detail });
  if (!ok) failures++;
  console.log(`${ok ? "  ok  " : "  FAIL"} ${name}${detail !== undefined ? " " + JSON.stringify(detail).slice(0, 300) : ""}`);
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
const text = (page) => page.evaluate(() => document.body.innerText);
const year = () => new Intl.DateTimeFormat("en-CA", { timeZone: "Asia/Dhaka", year: "numeric" }).format(new Date());
const APP = (n) => `APP-${year()}-${String(n).padStart(4, "0")}`;
const MEMBER = (code, n) => `PLCC-${code}-${year()}-${String(n).padStart(4, "0")}`;
const seq = (number) => Number(String(number ?? "").split("-").pop());

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
const go = (page, path) => page.goto(`${admin}${path}`, { waitUntil: "networkidle2", timeout: 60000 });
const submit = (page, selector) => Promise.all([page.waitForNavigation({ waitUntil: "networkidle2", timeout: 60000 }), page.click(selector)]);
const rows = (page) => page.$$eval('[data-testid="registry-row"]', (r) => r.map((x) => x.dataset.memberCode));

// ------------------------------------------------------------------------------------------------ phase: apply
const APPLICANTS = [
  { key: "st1", typeWords: ["শিক্ষার্থী", "Student"], locale: "bn", vp: "desktop", theme: "light", label: "শিক্ষার্থী ১" },
  { key: "gm", typeWords: ["সাধারণ", "General"], locale: "en", vp: "mobile", theme: "light", label: "General" },
  { key: "lm", typeWords: ["আজীবন", "Lifetime"], locale: "bn", vp: "mobile", theme: "dark", label: "আজীবন" },
];

function person(key, en) {
  return {
    name: `QA REGISTRY TEST ${key} ${state.suffix}`,
    email: `khondokermoin2k23+qareg-num-${key}-${state.suffix}@gmail.com`,
    phone: `0199${String(Math.floor(1000000 + Math.random() * 8999999))}`,
    profession: en ? "Teacher" : "শিক্ষক",
  };
}

async function offeredSeason(page, en, seasonId) {
  for (let attempt = 0; attempt < 8; attempt++) {
    await page.goto(`${site}${en ? "/en" : ""}/membership`, { waitUntil: "networkidle0", timeout: 90000 });
    await hydrated(page, "form.application-form");
    const field = await page.$eval('input[name="membership_season_id"]', (i) => i.value).catch(() => null);
    if (!seasonId || field === String(seasonId)) return field;
    await new Promise((r) => setTimeout(r, 5000));
  }
  return null;
}

async function applyPhase() {
  const seasonId = arg("season-id");
  for (const a of APPLICANTS) {
    if (state.applications[a.key]?.application_no) continue;
    const { context, page, problems } = await open({ vp: a.vp, theme: a.theme });
    const en = a.locale === "en";
    const p = person(a.key, en);
    const field = await offeredSeason(page, en, seasonId);
    check(`apply ${a.key}: the QA season is the open one`, !seasonId || field === String(seasonId), { field, seasonId });
    const typeValue = await page.$$eval("#membership_type_id option", (opts, words) => opts.find((o) => words.some((w) => o.textContent.includes(w)))?.value ?? null, a.typeWords);
    await page.select("#membership_type_id", typeValue);
    await page.type("#applicant_name", p.name);
    await page.type("#applicant_email", p.email);
    await page.type("#applicant_phone", p.phone);
    await page.type("#profession", p.profession);
    await page.evaluate(() => document.querySelector('form.application-form button[type="submit"]').scrollIntoView({ block: "center", behavior: "instant" }));
    await page.click('form.application-form button[type="submit"]');
    await page.waitForFunction(() => /APP-\d{4}-\d{4,}/.test(document.querySelector(".callout")?.innerText ?? ""), { timeout: 60000 });
    const number = (await page.$eval(".callout", (c) => c.innerText)).match(/APP-\d{4}-\d{4,}/)[0];
    check(`apply ${a.key}: the confirmation shows its application number ${number}`, new RegExp(`^APP-${year()}-\\d{4,}$`).test(number), number);
    await shot(page, `A-apply-${a.key}-${a.locale}-${a.vp}-number`);
    clean(`apply ${a.key}`, problems);
    state.applications[a.key] = { ...p, application_no: number, type: a.key === "gm" ? "GM" : a.key === "lm" ? "LM" : "ST" };
    await saveState();
    await context.close();
  }
  const sequential = ["st1", "gm", "lm"].map((k) => seq(state.applications[k].application_no));
  check("apply: three submissions one after the other got consecutive numbers", sequential[1] === sequential[0] + 1 && sequential[2] === sequential[1] + 1, sequential);
  if (fresh) check("apply: the first application of the year is APP-…-0001 (the counter started clean)", state.applications.st1.application_no === APP(1), state.applications.st1.application_no);

  if (!state.applications.st2?.application_no) {
    // Two Student applications at the same instant, through the site's own submit endpoint (the one the form uses).
    const { context, page, problems } = await open();
    await offeredSeason(page, false, seasonId);
    const typeValue = await page.$$eval("#membership_type_id option", (opts) => opts.find((o) => o.textContent.includes("শিক্ষার্থী"))?.value ?? null);
    const people = [person("st2", false), person("st3", false)];
    const answers = await page.evaluate(async (season, type, ps) => {
      const send = (p) => {
        const body = new FormData();
        body.set("membership_season_id", season);
        body.set("membership_type_id", type);
        body.set("applicant_name", p.name);
        body.set("applicant_email", p.email);
        body.set("applicant_phone", p.phone);
        body.set("website", "");
        return fetch("/api/membership/apply", { method: "POST", body, credentials: "same-origin" }).then(async (r) => ({ status: r.status, body: await r.json().catch(() => null) }));
      };
      return Promise.all(ps.map(send));
    }, await page.$eval('input[name="membership_season_id"]', (i) => i.value), typeValue, people);
    const numbers = answers.map((a) => a.body?.applicationNo ?? null);
    check("apply: two simultaneous submissions were both accepted", answers.every((a) => a.status === 200 && a.body?.status === "success"), answers);
    const top = seq(state.applications.lm.application_no);
    check("apply: … with two different numbers, the next two after the others", new Set(numbers).size === 2 && numbers.map(seq).sort((x, y) => x - y).join() === [top + 1, top + 2].join(), numbers);
    state.applications.st2 = { ...people[0], application_no: numbers[0], type: "ST" };
    state.applications.st3 = { ...people[1], application_no: numbers[1], type: "ST" };
    await saveState();
    clean("apply concurrent", problems);
    await context.close();
  }
}

// ------------------------------------------------------------------------------------------------ phase: review
async function applicationIdOf(page, number) {
  await go(page, `/admin/membership?search=${encodeURIComponent(number)}`);
  return page.$$eval("table a", (links, no) => links.find((l) => l.textContent.trim() === no)?.href ?? null, number);
}

async function memberNumberOn(page) {
  return page.$eval('[data-testid="open-member"]', (a) => a.textContent.match(/PLCC-[A-Z0-9]+-\d{4}-\d{4,}/)?.[0] ?? null).catch(() => null);
}

async function reviewPhase() {
  const { context, page, problems } = await open();
  await adminLogin(page);
  state.adminLocale ??= await adminLocale(page);
  await saveState();
  await setAdminLocale(page, "bn");
  const apps = state.applications;

  await go(page, `/admin/membership?search=${encodeURIComponent("QA REGISTRY TEST")}`);
  const listed = await text(page);
  check("applications list: every QA application number is shown", ["st1", "gm", "lm", "st2", "st3"].every((k) => listed.includes(apps[k].application_no)));
  await shot(page, "B-applications-list-numbers-bn");

  // the three types, one after the other
  for (const key of ["st1", "gm", "lm"]) {
    const app = apps[key];
    app.url = await applicationIdOf(page, app.application_no);
    await page.goto(app.url, { waitUntil: "networkidle2" });
    await submit(page, '[data-testid="start-review-form"] button');
    const numbering = await page.$eval('[data-testid="check-numbering"]', (li) => ({ ok: li.dataset.ok, text: li.innerText })).catch(() => null);
    const expectedNext = MEMBER(app.type, 1);
    check(`review ${key}: the approval check says which number comes next`, numbering?.ok === "1" && (!fresh || numbering.text.includes(expectedNext)), numbering);
    if (key === "st1") {
      await shotFull(page, "C-review-st1-number-check-bn");
      const statuses = await page.evaluate(async () => {
        const form = document.querySelector('[data-testid="approve-form"]');
        const post = () => fetch(form.action, { method: "POST", body: new FormData(form), credentials: "same-origin" }).then((r) => `${r.status}${r.redirected ? "+redirected" : ""}`);
        const both = await Promise.all([post(), post()]);
        return [...both, await post()];
      });
      check("review st1: approved by two simultaneous requests and a retry, no server error", statuses.every((s) => s === "200+redirected"), statuses);
      await page.reload({ waitUntil: "networkidle2" });
    } else {
      const fee = key === "gm" ? "100" : "500";
      await page.evaluate(() => { document.querySelector('[data-testid="record-payment-form"]').closest("details").open = true; });
      await page.$eval("#field-amount_received", (i, v) => { i.value = v; }, fee);
      await page.$eval("#field-reference", (i) => { i.value = "QA-RECEIPT"; });
      await submit(page, '[data-testid="record-payment-form"] button[type="submit"]');
      await submit(page, '[data-testid="verify-payment"]');
      await submit(page, '[data-testid="approve-button"]');
    }
    app.member_code = await memberNumberOn(page);
    app.member_url = await page.$eval('[data-testid="open-member"]', (a) => a.href).catch(() => null);
    check(`review ${key}: approved as ${app.member_code}`, fresh ? app.member_code === expectedNext : new RegExp(`^PLCC-${app.type}-${year()}-\\d{4,}$`).test(app.member_code ?? ""), app.member_code);
    await shotFull(page, `D-review-${key}-approved-bn`);
    await saveState();
  }

  // two Students approved at the same instant
  for (const key of ["st2", "st3"]) {
    apps[key].url = await applicationIdOf(page, apps[key].application_no);
    await page.goto(apps[key].url, { waitUntil: "networkidle2" });
    await submit(page, '[data-testid="start-review-form"] button');
    apps[key].approveAction = await page.$eval('[data-testid="approve-form"]', (f) => f.action);
  }
  // Each application's own approve form, posted at the same instant (one session = one CSRF token for both).
  const answers = await page.evaluate(async (actions) => {
    const form = document.querySelector('[data-testid="approve-form"]');
    const approve = (action) => fetch(action, { method: "POST", body: new FormData(form), credentials: "same-origin" }).then((r) => `${r.status}${r.redirected ? "+redirected" : ""}`);
    return Promise.all(actions.map(approve));
  }, [apps.st2.approveAction, apps.st3.approveAction]);
  check("review: two different Students approved at the same instant, no server error", answers.every((s) => s === "200+redirected"), answers);
  for (const key of ["st2", "st3"]) {
    await page.goto(apps[key].url, { waitUntil: "networkidle2" });
    apps[key].member_code = await memberNumberOn(page);
    apps[key].member_url = await page.$eval('[data-testid="open-member"]', (a) => a.href).catch(() => null);
  }
  const concurrent = [apps.st2.member_code, apps.st3.member_code];
  const base = seq(apps.st1.member_code);
  check("review: … they received two different numbers, the next two after the first Student (no number taken by the retries)",
    new Set(concurrent).size === 2 && concurrent.map(seq).sort((x, y) => x - y).join() === [base + 1, base + 2].join() && concurrent.every((c) => c?.startsWith(`PLCC-ST-${year()}-`)), concurrent);
  await shotFull(page, "E-review-concurrent-student-approved-bn");
  await saveState();

  // where the numbers appear
  await go(page, `/admin/membership?search=${encodeURIComponent("QA REGISTRY TEST")}`);
  await shot(page, "F-applications-list-approved-bn");
  for (const combo of [{ vp: "desktop", locale: "bn" }, { vp: "desktop", locale: "en" }, { vp: "mobile", locale: "bn" }, { vp: "mobile", locale: "en" }]) {
    const tag = `${combo.vp}-${combo.locale}`;
    const ctx = combo.vp === "desktop" && combo.locale === "bn" ? { page } : await open({ vp: combo.vp });
    if (ctx !== null && ctx.page !== page) await adminLogin(ctx.page);
    const p = ctx.page;
    await setAdminLocale(p, combo.locale);
    await go(p, `/admin/membership/members?search=${encodeURIComponent("QA REGISTRY TEST")}&per_page=50`);
    const listedCodes = await rows(p);
    check(`registry ${tag}: all five QA members listed with their numbers`, ["st1", "gm", "lm", "st2", "st3"].every((k) => listedCodes.includes(apps[k].member_code)), listedCodes);
    await shot(p, `G-registry-list-${tag}`);
    if (combo.vp === "desktop") {
      for (const [label, term, expected] of [
        ["exact number", apps.lm.member_code, [apps.lm.member_code]],
        ["part of a number", `ST-${year()}`, [apps.st1.member_code, apps.st2.member_code, apps.st3.member_code]],
        ["number without its zeros, lower case", `gm-${year()}-${seq(apps.gm.member_code)}`, [apps.gm.member_code]],
      ]) {
        await go(p, `/admin/membership/members?search=${encodeURIComponent(term)}`);
        const hit = await rows(p);
        check(`registry ${tag}: search by ${label} "${term}"`, expected.every((c) => hit.includes(c)) && hit.length === expected.length, hit);
        if (label === "number without its zeros, lower case") await shot(p, `H-registry-search-short-${tag}`);
      }
      const lmType = await p.$$eval('select[name="type"] option', (opts) => opts.find((o) => /আজীবন|Lifetime/.test(o.textContent))?.value ?? null);
      await go(p, `/admin/membership/members?type=${lmType}&search=${encodeURIComponent("QA REGISTRY TEST")}`);
      const lmOnly = await rows(p);
      check(`registry ${tag}: the type filter keeps only Lifetime numbers`, lmOnly.length >= 1 && lmOnly.every((c) => c.startsWith(`PLCC-LM-`)), lmOnly);
      await p.goto(apps.lm.member_url, { waitUntil: "networkidle2" });
      const shown = await p.$eval('[data-testid="member-code"]', (s) => s.textContent.trim()).catch(() => null);
      check(`member page ${tag}: shows ${apps.lm.member_code}`, shown === apps.lm.member_code, shown);
      await shotFull(p, `I-member-page-lm-${tag}`);
    }
    if (ctx.page !== page) {
      await setAdminLocale(p, state.adminLocale);
      clean(`registry ${tag}`, ctx.problems);
      await ctx.context.close();
    }
  }

  // the type pages
  await setAdminLocale(page, "bn");
  await go(page, "/admin/membership/types");
  const types = await page.$$eval("td", (cells) => cells.map((td) => ({ href: td.querySelector('a[href*="/admin/membership/types/"]')?.href ?? null, badges: [...td.querySelectorAll(".badge")].map((b) => b.textContent.trim()) })).filter((t) => t.href));
  const studentPage = types.find((t) => t.badges.includes("ST"))?.href;
  const noCode = types.find((t) => t.badges.some((b) => /কোড নেই|No code/.test(b)))?.href;
  await page.goto(studentPage, { waitUntil: "networkidle2" });
  const next = await page.$eval('[data-testid="member-number-format"]', (p) => p.innerText).catch(() => "");
  check("type page (Student): shows the next member number", next.includes(MEMBER("ST", seq(apps.st3.member_code) > seq(apps.st2.member_code) ? seq(apps.st3.member_code) + 1 : seq(apps.st2.member_code) + 1)), next);
  await shot(page, "J-type-student-next-number-bn");
  if (noCode) {
    await page.goto(noCode, { waitUntil: "networkidle2" });
    const why = await page.$eval('[data-testid="member-number-format"]', (p) => p.innerText).catch(() => "");
    check("type page (no code): says no member number can be issued until a code is set", /কোড নেই|no code/i.test(why) && !why.includes("PLCC-"), why);
    await shot(page, "K-type-without-code-bn");
    await setAdminLocale(page, "en");
    await page.goto(noCode, { waitUntil: "networkidle2" });
    await shot(page, "K-type-without-code-en");
  } else {
    check("type page (no code): every type has a code", true, "no type without a code on this system");
  }

  await setAdminLocale(page, state.adminLocale);
  clean("review", problems);
  await saveState();
  await context.close();
}

// ------------------------------------------------------------------------------------------------ phase: portal
async function portalPhase() {
  const st = state.applications.st1;
  const reset = new URL(arg("invite-url"));
  const memberPassword = randomBytes(12).toString("base64url"); // never printed or stored
  const { context, page, problems } = await open({ vp: "mobile" });
  await page.goto(`${site}${reset.pathname}${reset.search}`, { waitUntil: "networkidle0", timeout: 90000 });
  await hydrated(page, "form.application-form");
  await page.type("#password", memberPassword);
  await page.type("#password_confirmation", memberPassword);
  await page.click('form.application-form button[type="submit"]');
  await page.waitForFunction(() => document.querySelector(".callout") !== null, { timeout: 60000 });
  await page.goto(`${site}/member/login`, { waitUntil: "networkidle0" });
  await hydrated(page, "form.application-form");
  await page.type("#email", st.email);
  await page.type("#password", memberPassword);
  await page.click('form.application-form button[type="submit"]');
  await page.waitForFunction(() => location.pathname.startsWith("/member/dashboard"), { timeout: 60000 });
  await page.waitForSelector('[data-testid="membership-card"]');
  const dash = await page.evaluate(() => ({ page: document.body.innerText, card: document.querySelector('[data-testid="membership-card"]')?.innerText ?? "" }));
  const mentions = dash.page.split(st.member_code).length - 1;
  check(`portal: the dashboard shows the member number ${st.member_code} (under the greeting and on the membership card)`, mentions >= 2 && dash.card.includes(st.member_code), { mentions, card: dash.card });
  await shotFull(page, "L-portal-dashboard-number");
  clean("portal", problems);
  await context.close();
}

try {
  if (phase === "apply") await applyPhase();
  else if (phase === "review") await reviewPhase();
  else if (phase === "portal") await portalPhase();
  else throw new Error("--phase apply|review|portal");
} catch (e) {
  check(`phase ${phase} crashed`, false, String(e?.stack ?? e).slice(0, 800));
} finally {
  await browser.close();
  await writeFile(resolve(out, `report-numbering-${phase}.json`), JSON.stringify({ phase, at: new Date().toISOString(), failures, results }, null, 2));
  console.log(`\n${phase}: ${results.length - failures}/${results.length} checks passed. Screenshots + report in ${out}`);
  process.exit(failures ? 1 : 0);
}
