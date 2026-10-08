// Real-Chrome acceptance of the admin date/time task (2026-10-08, admin-erp/docs/DATES_AND_TIMES.md): every admin date
// and time reads Asia/Dhaka while the database keeps UTC. The expected text is computed HERE, independently of the PHP
// formatter (Intl with timeZone "Asia/Dhaka", then Bengali digits / month names), from the RAW stored values printed by
// `admin-erp/deploy/qa/membership-registry-qa.php time-probe`. Admin credentials are CLI arguments only (never stored).
//
//   node scripts/admin-time-qa.mjs --phase <act|verify> --admin <admin url> --email <e-mail> --password <password> \
//        --probe <time-probe JSON> --state <state.json> --out <dir>
//
//   act     on the disposable QA application ("QA REGISTRY TEST time A", created_at 2026-10-07 18:30 UTC): the
//           application page reads 8 October 00:30 (the owner's example); the registration-payment form proposes the
//           Dhaka day; ৳500 recorded with that date and verified; approved; ৳200 monthly contribution recorded and
//           verified — each time checked against the browser's own clock (±1 minute) and photographed (bn and en).
//   verify  with a fresh probe: every QA timestamp exactly (application, registration payment, approval, monthly payment,
//           the whole history timeline) and the real screens, read only: recruitment applications (list, detail,
//           filter), committee submissions and registration links, notices, activities (wall clock: NOT converted),
//           seasons, fee-policy history, admin users. bn and en. Lists every value that crosses Dhaka midnight.

import { existsSync, readFileSync } from "node:fs";
import { mkdir, readFile, writeFile } from "node:fs/promises";
import { resolve } from "node:path";
import puppeteer from "puppeteer-core";

const arg = (n, d = null) => {
  const i = process.argv.indexOf(`--${n}`);
  return i === -1 ? d : process.argv[i + 1];
};
const phase = arg("phase");
const admin = (arg("admin") ?? "").replace(/\/+$/, "");
const adminEmail = arg("email");
const adminPassword = arg("password");
const readJson = (file) => {
  const raw = readFileSync(resolve(file), "utf8");
  return JSON.parse(raw.slice(raw.indexOf("{")));
};
const probe = readJson(arg("probe"));
const stateFile = resolve(arg("state", "./admin-time-qa-state.json"));
const out = resolve(arg("out", "./admin-time-qa"));
const chrome = [process.env.CHROME_PATH, "C:/Program Files/Google/Chrome/Application/chrome.exe", "C:/Program Files (x86)/Google/Chrome/Application/chrome.exe", "/usr/bin/google-chrome", "/usr/bin/chromium"].filter(Boolean).find(existsSync);
if (!chrome) throw new Error("No Chrome found; set CHROME_PATH");
await mkdir(out, { recursive: true });
const state = existsSync(stateFile) ? JSON.parse(await readFile(stateFile, "utf8")) : {};
const saveState = () => writeFile(stateFile, JSON.stringify(state, null, 2));

const results = [];
let failures = 0;
const check = (name, ok, detail) => {
  results.push({ phase, name, ok: !!ok, detail });
  if (!ok) failures++;
  console.log(`${ok ? "  ok  " : "  FAIL"} ${name}${detail !== undefined ? " " + JSON.stringify(detail).slice(0, 400) : ""}`);
};

// ------------------------------------------------------------------------------------------------ the expected text
const DHAKA = "Asia/Dhaka";
const BN_DIGITS = "০১২৩৪৫৬৭৮৯";
const MONTHS = {
  bn: ["জানুয়ারি", "ফেব্রুয়ারি", "মার্চ", "এপ্রিল", "মে", "জুন", "জুলাই", "আগস্ট", "সেপ্টেম্বর", "অক্টোবর", "নভেম্বর", "ডিসেম্বর"],
  en: ["January", "February", "March", "April", "May", "June", "July", "August", "September", "October", "November", "December"],
};
const digits = (s, loc) => (loc === "bn" ? String(s).replace(/[0-9]/g, (d) => BN_DIGITS[Number(d)]) : String(s));
const instant = (raw) => new Date(String(raw).replace(" ", "T") + (/[zZ]|[+-]\d\d:?\d\d$/.test(raw) ? "" : "Z")); // stored values are UTC
function dhaka(raw) {
  const f = new Intl.DateTimeFormat("en-GB", { timeZone: DHAKA, year: "numeric", month: "numeric", day: "numeric", hour: "2-digit", minute: "2-digit", hourCycle: "h23" });
  const p = Object.fromEntries(f.formatToParts(raw instanceof Date ? raw : instant(raw)).map((x) => [x.type, x.value]));
  return { y: Number(p.year), m: Number(p.month), d: Number(p.day), hm: `${p.hour}:${p.minute}` };
}
const stamp = (raw, loc) => { const x = dhaka(raw); return digits(`${x.d} ${MONTHS[loc][x.m - 1]} ${x.y}, ${x.hm}`, loc); };
const stampDate = (raw, loc) => { const x = dhaka(raw); return digits(`${x.d} ${MONTHS[loc][x.m - 1]} ${x.y}`, loc); };
const calendar = (ymd, loc) => { const [y, m, d] = String(ymd).slice(0, 10).split("-").map(Number); return digits(`${d} ${MONTHS[loc][m - 1]} ${y}`, loc); };
const wallClock = (raw, loc) => `${calendar(raw, loc)}, ${digits(String(raw).slice(11, 16), loc)}`;
const utcDay = (raw) => instant(raw).toISOString().slice(0, 10);
const dhakaDay = (raw) => { const x = dhaka(raw); return `${x.y}-${String(x.m).padStart(2, "0")}-${String(x.d).padStart(2, "0")}`; };
const crossesMidnight = (raw) => raw && utcDay(raw) !== dhakaDay(raw);
const todayDhaka = () => dhakaDay(new Date());
/** The minute shown for an action just taken: this minute or the one before (the click may straddle a minute mark). */
const justNow = (loc) => [new Date(Date.now() - 60000), new Date()].map((d) => stamp(d, loc));

// ------------------------------------------------------------------------------------------------ browser
const browser = await puppeteer.launch({ executablePath: chrome, headless: true, args: ["--no-sandbox", "--disable-dev-shm-usage"] });
const context = await browser.createBrowserContext();
const page = await context.newPage();
await page.setViewport({ width: 1440, height: 900 });
const problems = { console: [], pageErrors: [], failed: [] };
page.on("console", (m) => { if (m.type() === "error") problems.console.push(m.text().slice(0, 200)); });
page.on("pageerror", (e) => problems.pageErrors.push(String(e).slice(0, 200)));
page.on("requestfailed", (r) => { const why = r.failure()?.errorText ?? ""; if (!(/ERR_ABORTED/.test(why) && r.resourceType() === "document")) problems.failed.push(`${r.method()} ${r.url().slice(0, 110)} ${why}`); });

const go = (path) => page.goto(path.startsWith("http") ? path : `${admin}${path}`, { waitUntil: "networkidle2", timeout: 60000 });
const submit = (selector) => Promise.all([page.waitForNavigation({ waitUntil: "networkidle2", timeout: 60000 }), page.click(selector)]);
const text = (selector) => page.$eval(selector, (e) => e.innerText.replace(/\s+/g, " ").trim()).catch(() => null);
const bodyText = () => page.evaluate(() => document.body.innerText.replace(/\s+/g, " "));
const shot = (name, full = false) => page.screenshot({ path: resolve(out, `${name}.png`), fullPage: full });
/** Scrolls the element well below the sticky header; with `mark`, outlines it — the value the screenshot proves. */
const scrollTo = async (selector, mark = false) => {
  await page.$eval(selector, (el, outline) => {
    document.documentElement.style.scrollBehavior = "auto";
    window.scrollTo({ top: el.getBoundingClientRect().top + window.scrollY - 220, behavior: "instant" });
    if (outline) { el.style.outline = "3px solid #e8590c"; el.style.outlineOffset = "3px"; }
  }, mark).catch(() => null);
  await page.evaluate(() => new Promise((r) => requestAnimationFrame(() => requestAnimationFrame(r))));
};

async function login() {
  await go("/login");
  await page.type("#email", adminEmail, { delay: 2 });
  await page.type("#password", adminPassword, { delay: 2 });
  await Promise.all([page.waitForNavigation({ waitUntil: "networkidle2", timeout: 60000 }), page.click('form button[type="submit"]')]);
  check("admin: signed in", page.url().includes("/admin"));
}
const locale = () => page.evaluate(() => document.documentElement.lang.slice(0, 2));
async function setLocale(loc) {
  if ((await locale()) === loc) return;
  await page.evaluate(async (l) => {
    const body = new FormData();
    body.set("_token", document.querySelector('form input[name="_token"]')?.value);
    body.set("locale", l);
    await fetch("/locale", { method: "POST", body, credentials: "same-origin" });
  }, loc);
  await page.reload({ waitUntil: "networkidle2" });
  check(`admin: language ${loc}`, (await locale()) === loc);
}

/** Every history entry on the page: its machine-readable UTC instant against the text shown next to it. */
async function historyAgrees(loc, label) {
  const entries = await page.$$eval("time[datetime]", (ts) => ts.map((t) => ({ utc: t.getAttribute("datetime"), shown: t.textContent.trim() })));
  const wrong = entries.filter((e) => e.utc && stamp(e.utc, loc) !== e.shown);
  check(`${label}: every history time (${entries.length}) is its UTC instant on the Dhaka clock`, entries.length > 0 && wrong.length === 0, wrong.slice(0, 3));
  return entries;
}

// ------------------------------------------------------------------------------------------------ phase: act
async function actPhase() {
  const qa = probe.qa?.application;
  if (!qa) throw new Error("run time-setup first");
  await login();
  state.adminLocale ??= await locale();
  await saveState();

  for (const loc of ["bn", "en"]) {
    await setLocale(loc);
    await go(`/admin/membership/${qa.id}`);
    const shown = await text('[data-testid="application-submitted-at"]');
    check(`application ${loc}: submitted ${qa.created_at} UTC reads "${stamp(qa.created_at, loc)}" (Dhaka) — the owner's example`, shown === stamp(qa.created_at, loc), shown);
    check(`application ${loc}: … and is not the UTC day`, crossesMidnight(qa.created_at) && !shown?.includes(calendar(utcDay(qa.created_at), loc)), { utcDay: utcDay(qa.created_at) });
    await scrollTo('[data-testid="application-submitted-at"]', true);
    await shot(`1-application-submitted-crosses-midnight-${loc}`);
  }

  await setLocale("bn");
  await go(`/admin/membership/${qa.id}`);
  if (!(await page.$('[data-testid="payment-row"]'))) {
    await page.evaluate(() => { document.querySelector('[data-testid="record-payment-form"]').closest("details").open = true; });
    const proposed = await page.$eval("#field-received_at", (i) => i.value);
    check(`registration payment: the form proposes the Dhaka day (${todayDhaka()})`, proposed === todayDhaka(), proposed);
    await page.$eval("#field-amount_received", (i) => { i.value = "500"; });
    await page.$eval("#field-reference", (i) => { i.value = "QA-TIME-REG"; });
    await submit('[data-testid="record-payment-form"] button[type="submit"]');
    await submit('[data-testid="verify-payment"]');
  }
  const received = await text('[data-testid="payment-received-at"]');
  const verified = await text('[data-testid="payment-verified-at"]');
  check("registration payment: received date is the calendar day as stored", received === calendar(todayDhaka(), "bn"), received);
  check("registration payment: verified time is now on the Dhaka clock", justNow("bn").includes(verified), { verified, expected: justNow("bn") });
  await scrollTo('[data-testid="payment-verified-at"]', true);
  await shot("2-registration-payment-bn");

  if (!(await page.$('[data-testid="open-member"]'))) await submit('[data-testid="approve-button"]');
  const memberHref = await page.$eval('[data-testid="open-member"]', (a) => a.href);
  state.memberUrl = memberHref;
  await saveState();
  await page.goto(memberHref, { waitUntil: "networkidle2" });
  const approved = await text('[data-testid="member-approved-at"]');
  check("member page: approved just now, on the Dhaka clock", justNow("bn").includes(approved), { approved, expected: justNow("bn") });

  if (!(await page.$('[data-testid="monthly-payment-row"]'))) {
    await page.evaluate(() => { document.querySelector('[data-testid="record-monthly-payment"]').open = true; });
    const id = await page.$eval("#f-dues-month option[data-period]", (o) => o.value);
    await page.select("#f-dues-month", id);
    await page.$eval("#f-dues-amount", (i) => { i.value = "200"; });
    await page.$eval("#f-dues-reference", (i) => { i.value = "QA-TIME-MONTHLY"; });
    check("monthly payment: the form proposes the Dhaka day", (await page.$eval("#f-dues-received-at", (i) => i.value)) === todayDhaka());
    await submit('[data-testid="record-monthly-payment-submit"]');
    const pid = await page.$eval('[data-testid="monthly-payment-row"]', (r) => r.dataset.paymentId);
    await submit(`[data-testid="verify-monthly-${pid}"]`);
  }
  const monthly = (await text('[data-testid="monthly-verified-at"]')) ?? "";
  check("monthly payment: verified time is now on the Dhaka clock", justNow("bn").some((t) => monthly.endsWith(t)), { monthly, expected: justNow("bn") });
  await historyAgrees("bn", "member page bn");
  await scrollTo('[data-testid="monthly-verified-at"]', true);
  await shot("3-monthly-payment-financial-bn");
  await scrollTo('[data-testid="member-history"]');
  await shot("4-history-timeline-bn");

  await setLocale("en");
  await page.goto(memberHref, { waitUntil: "networkidle2" });
  await historyAgrees("en", "member page en");
  await scrollTo('[data-testid="monthly-verified-at"]', true);
  await shot("3-monthly-payment-financial-en");
  await go(`/admin/membership/${qa.id}`);
  await scrollTo('[data-testid="payment-verified-at"]', true);
  await shot("2-registration-payment-en");
  await setLocale(state.adminLocale);
}

// ------------------------------------------------------------------------------------------------ phase: verify
async function verifyPhase() {
  await login();
  state.adminLocale ??= await locale();
  const qa = probe.qa;
  const crossing = [];
  const note = (what, raw) => { if (crossesMidnight(raw)) crossing.push({ what, utc: raw, dhaka: stamp(raw, "en") }); };

  for (const loc of ["bn", "en"]) {
    await setLocale(loc);

    if (qa?.application) {
      await go(`/admin/membership/${qa.application.id}`);
      check(`[${loc}] application: submitted`, (await text('[data-testid="application-submitted-at"]')) === stamp(qa.application.created_at, loc));
      note("application submitted", qa.application.created_at);
      for (const p of qa.registration_payments) {
        check(`[${loc}] registration payment: received (calendar date ${p.received_at})`, (await text('[data-testid="payment-received-at"]')) === calendar(p.received_at, loc));
        check(`[${loc}] registration payment: verified ${p.verified_at} UTC`, (await text('[data-testid="payment-verified-at"]')) === stamp(p.verified_at, loc));
        note("registration payment verified", p.verified_at);
      }
      await historyAgrees(loc, `[${loc}] application history`);
      if (qa.membership) {
        await go(`/admin/membership/members/${qa.membership.id}`);
        check(`[${loc}] member: approved ${qa.membership.approved_at} UTC`, (await text('[data-testid="member-approved-at"]')) === stamp(qa.membership.approved_at, loc));
        check(`[${loc}] member: joining date is the calendar date ${qa.membership.start_date}`, (await bodyText()).includes(calendar(qa.membership.start_date, loc)));
        for (const p of qa.monthly_payments) {
          check(`[${loc}] monthly payment: verified ${p.verified_at} UTC`, ((await text('[data-testid="monthly-verified-at"]')) ?? "").endsWith(stamp(p.verified_at, loc)));
          check(`[${loc}] monthly payment: received date ${p.received_at}`, (await bodyText()).includes(calendar(p.received_at, loc)));
          note("monthly payment verified", p.verified_at);
        }
        const entries = await historyAgrees(loc, `[${loc}] member history`);
        const stored = new Set(qa.history.map((h) => instant(h.created_at).toISOString().slice(0, 19)));
        check(`[${loc}] member history: every instant shown is one stored in approval_history`, entries.every((e) => stored.has(new Date(e.utc).toISOString().slice(0, 19))));
        if (loc === "en") { await scrollTo('[data-testid="member-history"]'); await shot("5-history-timeline-verified-en"); }
      }
      await go(`/admin/membership?search=${encodeURIComponent(qa.application.application_no)}`);
      const row = await page.$$eval("table tbody tr", (rows, no) => rows.find((r) => r.innerText.includes(no))?.innerText ?? "", qa.application.application_no);
      check(`[${loc}] applications list: the Dhaka day of the submission`, row.includes(stampDate(qa.application.created_at, loc)), row.slice(0, 160));
      if (loc === "bn") {
        await go(`/admin/membership?date=${dhakaDay(qa.application.created_at)}`);
        const hit = (await bodyText()).includes(qa.application.application_no);
        await go(`/admin/membership?date=${utcDay(qa.application.created_at)}`);
        const miss = !(await bodyText()).includes(qa.application.application_no);
        check(`applications filter: found under ${dhakaDay(qa.application.created_at)} (Dhaka), not under ${utcDay(qa.application.created_at)} (UTC)`, hit && miss);
      }
    }

    for (const a of probe.recruitment_applications) {
      const at = a.submitted_at ?? a.created_at;
      await go(`/admin/recruitment-applications/${a.id}`);
      check(`[${loc}] recruitment application ${a.application_no}: applied ${at} UTC`, (await text('[data-testid="applied-at"]')) === stamp(at, loc));
      note(`recruitment ${a.application_no}`, at);
      if (loc === "bn" && a === probe.recruitment_applications[0]) {
        await scrollTo('[data-testid="applied-at"]', true);
        await shot("6-recruitment-application-bn");
        await go(`/admin/recruitment-applications?from=${dhakaDay(a.created_at)}&to=${dhakaDay(a.created_at)}`);
        check(`recruitment filter: ${a.application_no} under its Dhaka day ${dhakaDay(a.created_at)}`, (await bodyText()).includes(a.application_no));
      }
    }
    if (probe.recruitment_applications.length) {
      await go("/admin/recruitment-applications");
      const page1 = await bodyText();
      check(`[${loc}] recruitment list: the Dhaka day of each listed application`, probe.recruitment_applications.every((a) => !page1.includes(a.application_no) || page1.includes(stampDate(a.created_at, loc))));
    }

    for (const s of probe.committee_submissions) {
      await go(`/admin/organization/committees/${s.committee_id}/submissions/${s.id}`);
      const body = await bodyText();
      check(`[${loc}] committee submission ${s.id}: submitted ${s.submitted_at} UTC`, !s.submitted_at || body.includes(stamp(s.submitted_at, loc)));
      if (s.reviewed_at) check(`[${loc}] committee submission ${s.id}: reviewed ${s.reviewed_at} UTC`, body.includes(stamp(s.reviewed_at, loc)));
      await historyOrNone(loc, `[${loc}] committee submission ${s.id}`);
      note(`committee submission ${s.id}`, s.submitted_at);
    }
    for (const l of probe.registration_links) {
      await go(`/admin/organization/committees/${l.committee_id}`);
      const body = await bodyText();
      check(`[${loc}] registration link ${l.id}: created ${l.created_at} UTC`, body.includes(stamp(l.created_at, loc)));
      if (l.expires_at) check(`[${loc}] registration link ${l.id}: expires ${l.expires_at} UTC`, body.includes(stamp(l.expires_at, loc)));
      if (loc === "bn" && l === probe.registration_links[0]) await shot("7-committee-links-bn", true);
      note(`registration link ${l.id}`, l.created_at);
    }
    for (const n of probe.notices) {
      await go(`/admin/notices/${n.id}`);
      const body = await bodyText();
      if (n.published_at) check(`[${loc}] notice ${n.id}: published ${n.published_at} UTC`, body.includes(stamp(n.published_at, loc)));
      check(`[${loc}] notice ${n.id}: last updated ${n.updated_at} UTC`, body.includes(stamp(n.updated_at, loc)));
      note(`notice ${n.id} published`, n.published_at);
    }
    for (const a of probe.activities) {
      await go(`/admin/activities/${a.id}`);
      const body = await bodyText();
      if (a.start_datetime) {
        check(`[${loc}] activity ${a.id}: start ${a.start_datetime} is a wall-clock time — shown as typed`, body.includes(wallClock(a.start_datetime, loc)), wallClock(a.start_datetime, loc));
      }
      if (a.published_at) check(`[${loc}] activity ${a.id}: published ${a.published_at} UTC`, body.includes(stamp(a.published_at, loc)));
    }
    if (probe.seasons.some((s) => s.opens_at || s.closes_at)) {
      await go("/admin/membership/seasons");
      const body = await bodyText();
      for (const s of probe.seasons) {
        if (s.opens_at) check(`[${loc}] season ${s.id}: opens ${s.opens_at} UTC`, body.includes(stamp(s.opens_at, loc)));
        if (s.closes_at) check(`[${loc}] season ${s.id}: closes ${s.closes_at} UTC`, body.includes(stamp(s.closes_at, loc)));
      }
    }
    const typeIds = [...new Set(probe.fee_policies.map((p) => p.membership_type_id))];
    for (const typeId of typeIds) {
      await go(`/admin/membership/types/${typeId}`);
      const body = await bodyText();
      for (const p of probe.fee_policies.filter((x) => x.membership_type_id === typeId)) {
        check(`[${loc}] fee policy ${p.id}: created ${p.created_at} UTC`, body.includes(stamp(p.created_at, loc)));
        check(`[${loc}] fee policy ${p.id}: effective from the calendar date ${p.effective_from}`, body.includes(calendar(p.effective_from, loc)));
      }
    }
    for (const u of probe.users.filter((x) => x.last_login_at).slice(-3)) {
      const res = await go(`/admin/system/users/${u.id}`);
      if (res?.status() !== 200) continue; // a removed account
      const body = await bodyText();
      // The signed-in QA admin's own sign-in moved when this run signed in: then it is "just now".
      check(`[${loc}] user ${u.id}: last sign-in ${u.last_login_at} UTC`, body.includes(stamp(u.last_login_at, loc)) || justNow(loc).some((t) => body.includes(t)), stamp(u.last_login_at, loc));
      check(`[${loc}] user ${u.id}: created ${u.created_at} UTC (its Dhaka day)`, body.includes(stampDate(u.created_at, loc)));
    }
  }
  state.crossing = crossing;
  check("evidence: values that cross Dhaka midnight (UTC day ≠ Dhaka day) were among those compared", crossing.length > 0, crossing.slice(0, 6));
  await setLocale(state.adminLocale);
}

async function historyOrNone(loc, label) {
  const has = await page.$("time[datetime]");
  if (has) await historyAgrees(loc, label);
}

try {
  if (phase === "act") await actPhase();
  else if (phase === "verify") await verifyPhase();
  else throw new Error("--phase act|verify");
  check(`${phase}: no console errors, page errors or failed requests`, !problems.console.length && !problems.pageErrors.length && !problems.failed.length, problems);
} catch (e) {
  check(`phase ${phase} crashed`, false, String(e?.stack ?? e).slice(0, 800));
} finally {
  await browser.close();
  await saveState();
  await writeFile(resolve(out, `report-admin-time-${phase}.json`), JSON.stringify({ phase, at: new Date().toISOString(), failures, results, crossing: state.crossing ?? [] }, null, 2));
  console.log(`\n${phase}: ${results.length - failures}/${results.length} checks passed. Screenshots + report in ${out}`);
  process.exit(failures ? 1 : 0);
}
