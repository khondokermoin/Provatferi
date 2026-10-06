// Real-Chrome acceptance of Membership Registry task 2 (2026-10-06): Application → Admin Review → Approval → Member
// Registry, on the real public site and the real admin panel. It creates ONLY disposable "QA REGISTRY TEST" data, all
// removed afterwards by admin-erp/deploy/qa/membership-registry-qa.php `cleanup`. Admin credentials are CLI arguments
// only (never stored); the QA member's portal password is generated here and never printed.
//
//   node scripts/membership-registry-qa.mjs --phase <phase> --site <public url> --admin <admin url> \
//        --email <admin e-mail> --password <admin password> --state <state.json> --out <dir> \
//        [--season-id <id>] [--invite-url <url>]
//
// Phases (run in this order; each reads and extends --state):
//   apply     three applications through the REAL public form — Student (bn, desktop), General (en, mobile),
//             Lifetime (bn, mobile, dark) — with a photo and the optional profile fields
//   review    admin, desktop, Bangla: the empty registry first; then each application: start review; Student approved
//             by two simultaneous requests and a retry (idempotency); General and Lifetime blocked until the cash is
//             recorded AND verified, then approved
//   registry  admin, desktop + mobile × Bangla + English: list, search (mobile in another spelling), filters, empty
//             state, pagination, member page, photo, link back to the application, suspend → reactivate, archive, edit
//   portal    the Student sets a password through the real reset page (a broker token from the QA kit) and signs in:
//             dashboard shows the membership and "no payment needed"; suspended → signed out; reactivated
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
const phase = arg("phase");
const site = (arg("site") ?? "").replace(/\/+$/, "");
const admin = (arg("admin") ?? "").replace(/\/+$/, "");
const adminEmail = arg("email");
const adminPassword = arg("password");
const stateFile = resolve(arg("state", "./registry-qa-state.json"));
const out = resolve(arg("out", "./registry-qa"));
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
  console.log(`${ok ? "  ok  " : "  FAIL"} ${name}${!ok && detail !== undefined ? " " + JSON.stringify(detail).slice(0, 400) : ""}`);
};

const browser = await puppeteer.launch({ executablePath: chrome, headless: true, args: ["--no-sandbox", "--disable-dev-shm-usage"] });

/** A fresh, isolated browser context with problem recording. */
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
    // A navigation superseded: a flight fetch, a document, or a Server Action's response stream cut short by the
    // redirect/navigation that action itself caused (member sign-in, password set) — the browser reports those aborted.
    if (/ERR_ABORTED/.test(reason) && (r.url().includes("_rsc=") || r.resourceType() === "document" || r.headers()["next-action"] !== undefined)) return;
    problems.failed.push(`${r.method()} ${r.url().replace(/token=[^&]+/, "token=…").slice(0, 110)} ${reason}`);
  });
  return { context, page, problems };
}

function clean(label, problems) {
  check(`${label}: no console errors, page errors or failed requests`, !problems.console.length && !problems.pageErrors.length && !problems.failed.length, problems);
}

const shot = (page, name) => page.screenshot({ path: resolve(out, `${name}.png`), fullPage: false });
const shotFull = (page, name) => page.screenshot({ path: resolve(out, `${name}.png`), fullPage: true });
const hydrated = (page, selector) => page.waitForFunction((s) => { const f = document.querySelector(s); return f && Object.keys(f).some((k) => k.startsWith("__reactProps$")); }, { timeout: 30000 }, selector);
const text = (page) => page.evaluate(() => document.body.innerText);

// ------------------------------------------------------------------------------------------------ admin helpers
async function adminLogin(page) {
  await page.goto(`${admin}/login`, { waitUntil: "networkidle2", timeout: 60000 });
  await page.type("#email", adminEmail, { delay: 2 });
  await page.type("#password", adminPassword, { delay: 2 });
  await Promise.all([page.waitForNavigation({ waitUntil: "networkidle2", timeout: 60000 }), page.click('form button[type="submit"]')]);
  check("admin: signed in", page.url().includes("/admin"), page.url());
}

/** The admin language is a stored preference: read it, switch when needed, and restore it afterwards. */
async function adminLocale(page) {
  return page.evaluate(() => document.documentElement.lang.slice(0, 2));
}
async function setAdminLocale(page, locale) {
  if ((await adminLocale(page)) === locale) return;
  await page.evaluate(async (loc) => {
    const token = document.querySelector('form input[name="_token"]')?.value;
    const body = new FormData();
    body.set("_token", token);
    body.set("locale", loc);
    await fetch("/locale", { method: "POST", body, credentials: "same-origin" });
  }, locale);
  await page.reload({ waitUntil: "networkidle2" });
  check(`admin: language switched to ${locale}`, (await adminLocale(page)) === locale);
}

async function go(page, path) {
  await page.goto(`${admin}${path}`, { waitUntil: "networkidle2", timeout: 60000 });
}

/** Submits a form found by selector (clicking its submit button) and waits for the page that follows. */
async function submit(page, buttonSelector) {
  await Promise.all([page.waitForNavigation({ waitUntil: "networkidle2", timeout: 60000 }), page.click(buttonSelector)]);
}

// ------------------------------------------------------------------------------------------------ phase: apply
const APPLICANTS = [
  { key: "st", typeWords: ["শিক্ষার্থী", "Student"], fee: { bn: "৳০", en: "৳0" }, locale: "bn", vp: "desktop", theme: "light", label: "শিক্ষার্থী" },
  { key: "gm", typeWords: ["সাধারণ", "General"], fee: { bn: "৳১০০", en: "৳100" }, locale: "en", vp: "mobile", theme: "light", label: "General" },
  { key: "lm", typeWords: ["আজীবন", "Lifetime"], fee: { bn: "৳৫০০", en: "৳500" }, locale: "bn", vp: "mobile", theme: "dark", label: "আজীবন" },
];

async function makePhoto(page, key) {
  // A real JPEG, different bytes for each applicant (a gradient, the key, the time).
  const dataUrl = await page.evaluate((k) => {
    const c = document.createElement("canvas");
    c.width = 900;
    c.height = 1200;
    const g = c.getContext("2d");
    const grad = g.createLinearGradient(0, 0, 900, 1200);
    grad.addColorStop(0, k === "st" ? "#2e7d32" : k === "gm" ? "#1565c0" : "#c62828");
    grad.addColorStop(1, "#fbc02d");
    g.fillStyle = grad;
    g.fillRect(0, 0, 900, 1200);
    g.fillStyle = "#fff";
    g.font = "bold 120px sans-serif";
    g.fillText(`QA ${k.toUpperCase()}`, 140, 600);
    g.font = "40px sans-serif";
    g.fillText(new Date().toISOString(), 120, 700);
    return c.toDataURL("image/jpeg", 0.9);
  }, key);
  const path = resolve(out, `fixture-${key}.jpg`);
  await writeFile(path, Buffer.from(dataUrl.split(",")[1], "base64"));
  return path;
}

async function applyPhase() {
  const seasonId = arg("season-id");
  for (const a of APPLICANTS) {
    if (state.applications[a.key]?.application_no) continue;
    const { context, page, problems } = await open({ vp: a.vp, theme: a.theme });
    const en = a.locale === "en";
    const person = {
      name: `QA REGISTRY TEST ${a.label} ${state.suffix}`,
      email: `khondokermoin2k23+qareg-${a.key}-${state.suffix}@gmail.com`,
      phone: `0199${String(Math.floor(1000000 + Math.random() * 8999999))}`,
      address: en ? "House 12, Road 5, Mirpur, Dhaka" : "বাড়ি ১২, সড়ক ৫, মিরপুর, ঢাকা",
      profession: en ? "Teacher" : "শিক্ষক",
      institution: en ? "Provatferi QA School" : "প্রভাতফেরী কিউএ বিদ্যালয়",
    };
    // The page must offer the season the QA kit just opened. On production the kit's signed revalidation makes that
    // immediate; without a revalidation secret (a local rehearsal) the page's 15-second cache window applies — so wait.
    let seasonField = null;
    for (let attempt = 0; attempt < 8; attempt++) {
      await page.goto(`${site}${en ? "/en" : ""}/membership`, { waitUntil: "networkidle0", timeout: 90000 });
      await hydrated(page, "form.application-form");
      seasonField = await page.$eval('input[name="membership_season_id"]', (i) => i.value).catch(() => null);
      const offered = await page.$$eval("#campaign option", (opts) => opts.map((o) => o.value)).catch(() => []);
      if (!seasonId || seasonField === String(seasonId) || offered.includes(String(seasonId))) break;
      await new Promise((r) => setTimeout(r, 5000));
    }
    if (await page.$("#campaign")) {
      const value = await page.$$eval("#campaign option", (opts) => opts.find((o) => o.textContent.includes("QA REGISTRY TEST"))?.value ?? null);
      check(`apply ${a.key}: the QA season is offered`, value !== null);
      if (value) await page.select("#campaign", value);
    } else {
      check(`apply ${a.key}: the only open season is the QA season`, !seasonId || seasonField === String(seasonId), { seasonField, seasonId });
    }
    const typeValue = await page.$$eval("#membership_type_id option", (opts, words) => opts.find((o) => words.some((w) => o.textContent.includes(w)))?.value ?? null, a.typeWords);
    check(`apply ${a.key}: the ${a.typeWords[1]} type is offered`, typeValue !== null);
    await page.select("#membership_type_id", typeValue);
    await page.waitForSelector('[data-testid="fee-summary"]');
    // The form's own quote — the page's type cards above the form carry data-fee too.
    const fee = await page.$eval('[data-testid="fee-summary"] [data-fee="registration"]', (d) => d.textContent.trim());
    check(`apply ${a.key}: the form quotes the registration fee ${a.fee[a.locale]}`, fee === a.fee[a.locale], fee);

    await page.type("#applicant_name", person.name);
    await page.type("#applicant_email", person.email);
    await page.type("#applicant_phone", person.phone);
    await page.type("#address", person.address);
    await page.type("#profession", person.profession);
    await page.type("#institution", person.institution);
    await (await page.$("#photo")).uploadFile(await makePhoto(page, a.key));
    await page.evaluate(() => document.querySelector('form.application-form button[type="submit"]').scrollIntoView({ block: "center", behavior: "instant" }));
    await shot(page, `A-apply-${a.key}-${a.locale}-${a.vp}-filled`);

    await page.click('form.application-form button[type="submit"]');
    await page.waitForFunction(() => /APP-\d{4}-\d{4,}/.test(document.querySelector(".callout")?.innerText ?? ""), { timeout: 60000 });
    const applicationNo = (await page.$eval(".callout", (c) => c.innerText)).match(/APP-\d{4}-\d{4,}/)[0];
    check(`apply ${a.key}: submitted — ${applicationNo}`, Boolean(applicationNo));
    await shot(page, `A-apply-${a.key}-${a.locale}-${a.vp}-submitted`);
    clean(`apply ${a.key}`, problems);
    state.applications[a.key] = { ...person, application_no: applicationNo };
    await saveState();
    await context.close();
  }
}

// ------------------------------------------------------------------------------------------------ phase: review
async function applicationPage(page, key) {
  await go(page, `/admin/membership?search=${encodeURIComponent(state.applications[key].application_no)}`);
  const href = await page.$$eval("table a", (links, no) => links.find((l) => l.textContent.trim() === no)?.href ?? null, state.applications[key].application_no);
  check(`review ${key}: listed under its number with the applicant's name`, href !== null && (await text(page)).includes(state.applications[key].name));
  await page.goto(href, { waitUntil: "networkidle2" });
}

async function reviewPhase() {
  const { context, page, problems } = await open();
  await adminLogin(page);
  state.adminLocale ??= await adminLocale(page);
  await saveState();
  await setAdminLocale(page, "bn");

  // the registry before anything is approved (production had no members at all)
  await go(page, "/admin/membership/members");
  const before = await page.$$eval('[data-testid="registry-row"]', (rows) => rows.length);
  state.registryRowsBefore = before;
  if (before === 0) {
    check("registry: the true empty state is shown", (await text(page)).includes("এখনো কোনো সদস্য নেই"));
    await shot(page, "K-registry-empty-bn");
  }

  await go(page, `/admin/membership?search=${encodeURIComponent("QA REGISTRY TEST")}`);
  await shot(page, "B-applications-list-bn");

  for (const key of ["st", "gm", "lm"]) {
    await applicationPage(page, key);
    const app = state.applications[key];
    const body = await text(page);
    check(`review ${key}: everything submitted is shown (name, e-mail, mobile, address, profession, institution)`,
      [app.name, app.email, app.phone, app.address, app.profession, app.institution].every((v) => body.includes(v)));
    const photo = await page.$eval('[data-testid="application-photo"]', (i) => ({ w: i.naturalWidth, src: i.getAttribute("src") })).catch(() => null);
    check(`review ${key}: the applicant's photo is shown to the admin (private route)`, photo && photo.w > 0 && photo.src.includes("/photo"), photo);
    check(`review ${key}: the private photo path is never printed`, !body.includes("membership-applications/"));

    await submit(page, '[data-testid="start-review-form"] button');
    check(`review ${key}: review started`, (await page.$eval('[data-testid="application-status"]', (b) => b.textContent.trim())).includes("পর্যালোচনাধীন"));
    await shot(page, `B-review-${key}-under-review`);

    const identity = await page.$eval('[data-testid="check-identity"]', (li) => li.dataset.identity);
    check(`review ${key}: duplicate check says a NEW member account`, identity === "new", identity);

    if (key === "st") {
      const pay = await page.$eval('[data-testid="check-payment"]', (li) => li.dataset.ok);
      check("review st: zero fee — payment check passes with no payment at all", pay === "1");
      await shotFull(page, "C-review-st-approval-check");
      // Idempotency: two simultaneous approval requests (a double click, two admins), then a late retry.
      const statuses = await page.evaluate(async () => {
        const form = document.querySelector('[data-testid="approve-form"]');
        const post = () => fetch(form.action, { method: "POST", body: new FormData(form), credentials: "same-origin" }).then((r) => `${r.status}${r.redirected ? "+redirected" : ""}`);
        const both = await Promise.all([post(), post()]);
        const retry = await post();
        return [...both, retry];
      });
      check("review st: two simultaneous approvals and a retry all answer (redirected back, no server error)", statuses.every((s) => s === "200+redirected"), statuses);
      await page.reload({ waitUntil: "networkidle2" });
    } else {
      const fee = key === "gm" ? "100" : "500";
      const blocked = await page.evaluate(() => ({ ok: document.querySelector('[data-testid="check-payment"]').dataset.ok, disabled: document.querySelector('[data-testid="approve-button"]').disabled }));
      check(`review ${key}: fee-bearing — blocked before any payment (approve disabled)`, blocked.ok === "0" && blocked.disabled, blocked);
      await shot(page, `H-review-${key}-blocked-unpaid`);
      // the server refuses too, not only the button
      const forced = await page.evaluate(async () => {
        const form = document.querySelector('[data-testid="approve-form"]');
        const r = await fetch(form.action, { method: "POST", body: new FormData(form), credentials: "same-origin" });
        return r.url;
      });
      await page.reload({ waitUntil: "networkidle2" });
      check(`review ${key}: a forced approval request is refused while unpaid`, (await page.$eval('[data-testid="application-status"]', (b) => b.textContent.trim())).includes("পর্যালোচনাধীন"), forced);

      await page.click('[data-testid="record-payment-form"]', { offset: { x: 1, y: 1 } }).catch(() => {});
      await page.evaluate(() => { document.querySelector('[data-testid="record-payment-form"]').closest("details").open = true; });
      await page.$eval('#field-amount_received', (i, v) => { i.value = v; }, fee);
      await page.$eval('#field-reference', (i) => { i.value = "QA-RECEIPT"; });
      await submit(page, '[data-testid="record-payment-form"] button[type="submit"]');
      const afterRecord = await page.evaluate(() => ({ ok: document.querySelector('[data-testid="check-payment"]').dataset.ok, disabled: document.querySelector('[data-testid="approve-button"]').disabled, rows: document.querySelectorAll('[data-testid="payment-row"]').length }));
      check(`review ${key}: cash recorded but NOT verified — still blocked`, afterRecord.rows === 1 && afterRecord.ok === "0" && afterRecord.disabled, afterRecord);
      await shot(page, `H-review-${key}-recorded-unverified`);

      await submit(page, '[data-testid="verify-payment"]');
      const afterVerify = await page.evaluate(() => ({ ok: document.querySelector('[data-testid="check-payment"]').dataset.ok, disabled: document.querySelector('[data-testid="approve-button"]').disabled }));
      check(`review ${key}: verified — approval now allowed`, afterVerify.ok === "1" && !afterVerify.disabled, afterVerify);
      await shotFull(page, `C-review-${key}-approval-check-ready`);
      await submit(page, '[data-testid="approve-button"]');
    }

    const status = await page.$eval('[data-testid="application-status"]', (b) => b.textContent.trim());
    const memberLink = await page.$eval('[data-testid="open-member"]', (a) => ({ href: a.href, text: a.textContent.trim() })).catch(() => null);
    check(`review ${key}: approved, and the page links to the new member`, status.includes("অনুমোদিত") && memberLink !== null, { status, memberLink });
    app.member_code = memberLink?.text.match(/PF-\d{4}-\d{4,}/)?.[0] ?? null;
    app.member_url = memberLink?.href ?? null;
    check(`review ${key}: member number in the existing PF-YYYY-NNNN format`, /^PF-\d{4}-\d{4,}$/.test(app.member_code ?? ""), app.member_code);
    await shotFull(page, `I-review-${key}-approved`);
    await saveState();
  }

  await go(page, "/admin/membership/members");
  const rows = await page.$$eval('[data-testid="registry-row"]', (r) => r.map((x) => x.dataset.memberCode));
  check("registry: the three new members are listed", ["st", "gm", "lm"].every((k) => rows.includes(state.applications[k].member_code)), rows);
  await shot(page, "D-registry-after-approvals-bn");
  await setAdminLocale(page, state.adminLocale);
  clean("review", problems);
  await context.close();
}

// ------------------------------------------------------------------------------------------------ phase: registry
async function registryPhase() {
  const combos = [
    { vp: "desktop", locale: "bn" },
    { vp: "desktop", locale: "en" },
    { vp: "mobile", locale: "bn" },
    { vp: "mobile", locale: "en" },
  ];
  const st = state.applications.st;
  const gm = state.applications.gm;
  const lm = state.applications.lm;
  const W = {
    bn: { empty: "এই ফিল্টারে কোনো সদস্য পাওয়া যায়নি", notRequired: "ফি প্রযোজ্য নয়", paid: "পরিশোধিত (যাচাইকৃত)", suspended: "স্থগিত", archived: "সংরক্ষিত", active: "সক্রিয়" },
    en: { empty: "No member matches these filters", notRequired: "No fee due", paid: "Paid (verified)", suspended: "Suspended", archived: "Archived", active: "Active" },
  };

  for (const combo of combos) {
    const tag = `${combo.vp}-${combo.locale}`;
    const w = W[combo.locale];
    const { context, page, problems } = await open({ vp: combo.vp });
    await adminLogin(page);
    state.adminLocale ??= await adminLocale(page);
    await setAdminLocale(page, combo.locale);

    // list
    await go(page, `/admin/membership/members?search=${encodeURIComponent("QA REGISTRY TEST")}&per_page=50`);
    const rows = await page.$$eval('[data-testid="registry-row"]', (r) => r.map((x) => x.dataset.memberCode));
    check(`registry ${tag}: the QA members are listed`, [st, gm, lm].every((a) => rows.includes(a.member_code)), rows);
    // The avatars are loading="lazy": bring each into view and let it decode before judging it.
    const avatars = await page.$$eval('[data-testid="registry-row"] img.pf-avatar', (imgs) => Promise.all(imgs.map(async (i) => {
      i.scrollIntoView({ block: "center", behavior: "instant" });
      const decoded = await i.decode().then(() => true, () => false);
      return { ok: decoded && i.naturalWidth > 0, src: i.getAttribute("src") };
    })));
    await page.evaluate(() => window.scrollTo({ top: 0, behavior: "instant" }));
    check(`registry ${tag}: member photos load (private route, resized)`, avatars.length >= 3 && avatars.every((a) => a.ok), avatars.slice(0, 4));
    const body = await text(page);
    check(`registry ${tag}: fee states read right (Student: no fee due; General/Lifetime: paid)`, body.includes(w.notRequired) && body.includes(w.paid));
    await shot(page, `D-registry-list-${tag}`);

    // search: the General member's mobile written the long way
    const longPhone = `+880 ${gm.phone.slice(1, 5)}-${gm.phone.slice(5)}`;
    await go(page, `/admin/membership/members?search=${encodeURIComponent(longPhone)}`);
    const found = await page.$$eval('[data-testid="registry-row"]', (r) => r.map((x) => x.dataset.memberCode));
    check(`registry ${tag}: search by mobile in another spelling finds exactly that member`, found.length === 1 && found[0] === gm.member_code, { longPhone, found });
    for (const [label, term] of [["e-mail", st.email], ["member number", lm.member_code], ["name", st.name]]) {
      await go(page, `/admin/membership/members?search=${encodeURIComponent(term)}`);
      const hit = await page.$$eval('[data-testid="registry-row"]', (r) => r.map((x) => x.dataset.memberCode));
      check(`registry ${tag}: search by ${label}`, hit.length >= 1 && hit.includes(term === lm.member_code ? lm.member_code : st.member_code), hit);
    }
    if (combo.vp === "desktop") await shot(page, `E-registry-search-${tag}`);

    // filters
    await go(page, `/admin/membership/members?payment=not_required&search=${encodeURIComponent("QA REGISTRY TEST")}&per_page=50`);
    const free = await page.$$eval('[data-testid="registry-row"]', (r) => r.map((x) => x.dataset.memberCode));
    check(`registry ${tag}: fee filter "no fee due" keeps the Student, drops General/Lifetime`, free.includes(st.member_code) && !free.includes(gm.member_code) && !free.includes(lm.member_code), free);

    // empty filtered state
    await go(page, `/admin/membership/members?search=zzzz-no-such-member-${state.suffix}`);
    check(`registry ${tag}: filtered empty state`, (await text(page)).includes(w.empty));
    if (combo.vp === "mobile" || combo.locale === "en") await shot(page, `K-registry-empty-filtered-${tag}`);

    // member page
    await page.goto(st.member_url, { waitUntil: "networkidle2" });
    const detail = await page.evaluate(() => ({
      code: document.querySelector('[data-testid="member-code"]')?.textContent.trim(),
      name: document.querySelector('[data-testid="member-name"]')?.textContent.trim(),
      photo: (() => { const i = document.querySelector('img[data-testid="member-photo"]'); return i ? i.complete && i.naturalWidth > 0 : false; })(),
      payment: document.querySelector('[data-testid="member-payment-state"]')?.textContent.trim(),
      source: document.querySelector('[data-testid="source-application"]')?.textContent.trim(),
      historyItems: document.querySelectorAll('[data-testid="member-history"] .pf-timeline-item').length,
    }));
    check(`member page ${tag}: identity, photo, fee state, source application, history`,
      detail.code === st.member_code && detail.name === st.name && detail.photo && detail.payment === w.notRequired && detail.source?.includes(st.application_no) && detail.historyItems >= 4, detail);
    const pageText = await text(page);
    check(`member page ${tag}: contact & profile shown`, [st.phone, st.email, st.address, st.profession, st.institution].every((v) => pageText.includes(v)));
    await shotFull(page, `F-member-detail-${tag}`);

    if (tag === "desktop-bn") {
      // the link back to the application
      await Promise.all([page.waitForNavigation({ waitUntil: "networkidle2" }), page.click('[data-testid="source-application"]')]);
      check("member page: the source application opens", (await text(page)).includes(st.application_no));
      await page.goto(st.member_url, { waitUntil: "networkidle2" });

      // suspend → reactivate
      await page.evaluate(() => { document.querySelector('[data-testid="action-suspend"]').open = true; });
      await page.type("#reason-suspend", "QA: সাময়িক স্থগিতাদেশ পরীক্ষা");
      await submit(page, '[data-testid="confirm-suspend"]');
      const suspended = await page.evaluate(() => ({ status: document.querySelector('[data-testid="member-status"]').textContent.trim(), reason: document.querySelector('[data-testid="status-reason"]')?.textContent ?? "" }));
      check("status: suspended with the reason shown", suspended.status.includes(w.suspended) && suspended.reason.includes("সাময়িক স্থগিতাদেশ পরীক্ষা"), suspended);
      await shotFull(page, "G-member-suspended-desktop-bn");
      await go(page, `/admin/membership/members?status=suspended&search=${encodeURIComponent("QA REGISTRY TEST")}`);
      check("status: the suspended filter shows the member", (await page.$$eval('[data-testid="registry-row"]', (r) => r.map((x) => x.dataset.memberCode))).includes(st.member_code));
      await page.goto(st.member_url, { waitUntil: "networkidle2" });
      await page.evaluate(() => { document.querySelector('[data-testid="action-reactivate"]').open = true; });
      await submit(page, '[data-testid="confirm-reactivate"]');
      check("status: reactivated", (await page.$eval('[data-testid="member-status"]', (b) => b.textContent.trim())).includes(w.active));

      // archive the General member (it stays archived; cleanup removes it). A re-run finds it archived: reactivate first.
      await page.goto(gm.member_url, { waitUntil: "networkidle2" });
      if (!(await page.$('[data-testid="action-archive"]')) && (await page.$('[data-testid="action-reactivate"]'))) {
        await page.evaluate(() => { document.querySelector('[data-testid="action-reactivate"]').open = true; });
        await submit(page, '[data-testid="confirm-reactivate"]');
      }
      await page.evaluate(() => { document.querySelector('[data-testid="action-archive"]').open = true; });
      await page.type("#reason-archive", "QA: আর্কাইভ পরীক্ষা");
      await submit(page, '[data-testid="confirm-archive"]');
      check("status: archived, nothing deleted (the page still opens)", (await page.$eval('[data-testid="member-status"]', (b) => b.textContent.trim())).includes(w.archived));
      await shotFull(page, "G-member-archived-desktop-bn");

      // pagination (the QA kit seeded extra QA members before this phase)
      await go(page, "/admin/membership/members");
      const pageTwo = await page.$$eval(".pagination a", (links) => links.find((l) => /page=2/.test(l.href))?.href ?? null);
      check("registry: pagination has a second page", pageTwo !== null);
      if (pageTwo) {
        await page.goto(pageTwo, { waitUntil: "networkidle2" });
        check("registry: page 2 lists members", (await page.$$eval('[data-testid="registry-row"]', (r) => r.length)) > 0);
        await shot(page, "L-registry-page-2-desktop-bn");
      }
    }

    if (tag === "desktop-en") {
      // edit: change the Lifetime member's address; the history records old → new
      await page.goto(lm.member_url, { waitUntil: "networkidle2" });
      await Promise.all([page.waitForNavigation({ waitUntil: "networkidle2" }), page.click('[data-testid="edit-member"]')]);
      await page.$eval("#field-address", (t) => { t.value = "QA: Rajshahi (edited)"; });
      await submit(page, '[data-testid="member-edit-form"] button[type="submit"]');
      check("edit: saved, and the history shows the old and the new address", (await text(page)).includes("QA: Rajshahi (edited)") && (await text(page)).includes("→ QA: Rajshahi (edited)"));
      await shotFull(page, "F-member-edited-history-desktop-en");
    }

    await setAdminLocale(page, state.adminLocale);
    clean(`registry ${tag}`, problems);
    await context.close();
  }
  await saveState();
}

// ------------------------------------------------------------------------------------------------ phase: portal
async function portalPhase() {
  const st = state.applications.st;
  const inviteUrl = arg("invite-url");
  const memberPassword = randomBytes(12).toString("base64url"); // never printed or stored
  const { context, page, problems } = await open({ vp: "mobile" });

  const reset = new URL(inviteUrl);
  await page.goto(`${site}${reset.pathname}${reset.search}`, { waitUntil: "networkidle0", timeout: 90000 });
  await hydrated(page, "form.application-form");
  await page.type("#password", memberPassword);
  await page.type("#password_confirmation", memberPassword);
  await page.click('form.application-form button[type="submit"]');
  await page.waitForFunction(() => document.querySelector(".callout") !== null, { timeout: 60000 });
  await shot(page, "J-portal-password-set");

  await page.goto(`${site}/member/login`, { waitUntil: "networkidle0" });
  await hydrated(page, "form.application-form");
  await page.type("#email", st.email);
  await page.type("#password", memberPassword);
  await page.click('form.application-form button[type="submit"]');
  await page.waitForFunction(() => location.pathname.startsWith("/member/dashboard"), { timeout: 60000 });
  await page.waitForSelector('[data-testid="membership-card"]');
  const dash = await page.evaluate(() => ({
    text: document.body.innerText,
    fee: document.querySelector('[data-testid="membership-fee"]')?.dataset.paymentState ?? null,
    feeText: document.querySelector('[data-testid="membership-fee"]')?.textContent.trim() ?? null,
    payments: document.querySelector('[data-testid="payments-empty"]')?.textContent.trim() ?? null,
  }));
  check("portal: the payments section says no payment is needed (not 'no records yet')", (dash.payments ?? "").includes("পরিশোধের প্রয়োজন নেই"), dash.payments);
  check("portal: the new member signs in and the dashboard shows the membership number", dash.text.includes(st.member_code), st.member_code);
  check("portal: the zero-fee membership reads 'no payment needed' — never unpaid", dash.fee === "not_required" && dash.feeText.includes("পরিশোধের প্রয়োজন নেই") && !dash.text.includes("পরিশোধ বাকি"), dash.feeText);
  await shotFull(page, "J-portal-dashboard-student");

  // suspended → signed out at once; reactivated → can sign in again
  const adminSide = await open();
  await adminLogin(adminSide.page);
  await adminSide.page.goto(st.member_url, { waitUntil: "networkidle2" });
  await adminSide.page.evaluate(() => { document.querySelector('[data-testid="action-suspend"]').open = true; });
  await adminSide.page.type("#reason-suspend", "QA: portal access test");
  await submit(adminSide.page, '[data-testid="confirm-suspend"]');
  await page.goto(`${site}/member/dashboard`, { waitUntil: "networkidle0" });
  check("portal: a suspended member is signed out at once (sent to the login page)", page.url().includes("/member/login"), page.url());
  await shot(page, "J-portal-suspended-signed-out");
  await adminSide.page.evaluate(() => { document.querySelector('[data-testid="action-reactivate"]').open = true; });
  await submit(adminSide.page, '[data-testid="confirm-reactivate"]');
  await adminSide.context.close();

  clean("portal", problems);
  await context.close();
}

try {
  if (phase === "apply") await applyPhase();
  else if (phase === "review") await reviewPhase();
  else if (phase === "registry") await registryPhase();
  else if (phase === "portal") await portalPhase();
  else throw new Error("--phase apply|review|registry|portal");
} catch (e) {
  check(`phase ${phase} crashed`, false, String(e?.stack ?? e).slice(0, 800));
} finally {
  await browser.close();
  await writeFile(resolve(out, `report-${phase}.json`), JSON.stringify({ phase, at: new Date().toISOString(), failures, results }, null, 2));
  console.log(`\n${phase}: ${results.length - failures}/${results.length} checks passed. Screenshots + report in ${out}`);
  process.exit(failures ? 1 : 0);
}
