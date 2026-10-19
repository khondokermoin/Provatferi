// Real-Chrome acceptance of committee-submission review (admin-erp/docs/COMMITTEE_SUBMISSION_REVIEW.md): the applicant's photo in
// the admin review page and the approval of a submission. It drives the REAL public registration form, the REAL admin screens and
// the REAL approval button, on disposable data only. Run it against production or a local pair:
//
//   node scripts/committee-approval-qa.mjs --phase reproduce|verify|status|readonly --admin https://admin.provatferi.org \
//        --site https://provatferi.org --env <file[,file]> --prefix PROD_QA_ADMIN --out <evidence dir> --tag <6 hex> \
//        --committee <QA committee id> [--submit form|api] [--api <laravel url>] [--position <id>] [--offset <n>] [--spacing <s>]
//        [--seed '<json from the kit seed>'] [--pre-pending A,C] [--pre-approved B,D,E] [--other-committee <id>] [--set draft|upcoming]
//   The env file(s) hold <PREFIX>_EMAIL and <PREFIX>_PASSWORD (and, for `reproduce`, <PREFIX>_TOKEN: the raw registration token of
//   the QA committee's link). Nothing secret travels on a command line.
//
// WHAT IT TOUCHES. Only a QA committee (made by deploy/qa/committee-approval-qa.php `setup`) and the applications it files into it:
// every name starts with QA-CMT-<tag> and every address carries "qacmt"; plus, in `verify`, two throw-away staff accounts and
// their roles (qa-cmt-<tag>-*@example.test, "QA-CMT-<tag> ..."), all removed by the kit's `purge`. It never opens, approves,
// rejects or edits anything else. The admin login is read from the env file only and never printed. Nothing printed or saved
// here is applicant data: the photo is a generated picture and the names are the QA markers. The QA admin is NEVER signed out
// (a logout rotates that account's remember token); the browser is simply closed, and the admin language is put back.
//
// reproduce  (BEFORE the fix) the two defects of the report, on QA data: the photo is not shown while the application is pending,
//            and a repeated approval (a stale page, a second tab) ends on a bare HTTP 422 page.
// verify     (AFTER the fix) the whole matrix: photo for a reviewer, approval, repetition, invalid states, authorization, the
//            missing-photo and malformed-path cases, Bangla and English, desktop and phone, light and dark.
// status     moves the QA committee between draft and upcoming through the real lifecycle form.
// readonly   GET-only look at ONE real application (--committee, --submission): status, photo, which forms are offered. No click,
//            no screenshot, no name or address.
import { createRequire } from "node:module";
import { existsSync, mkdirSync, readFileSync, statSync, writeFileSync } from "node:fs";
import { join } from "node:path";
import { randomBytes } from "node:crypto";

const arg = (name, fallback) => {
  const i = process.argv.indexOf(`--${name}`);
  return i > -1 ? process.argv[i + 1] : fallback;
};
const PHASE = arg("phase", "verify");
const ADMIN = (arg("admin") ?? "").replace(/\/+$/, "");
const SITE = (arg("site") ?? "").replace(/\/+$/, "");
const API = (arg("api") ?? ADMIN).replace(/\/+$/, "");
const OUT = arg("out");
const ENV_FILE = arg("env");
const PREFIX = arg("prefix");
const TAG = (arg("tag") ?? randomBytes(3).toString("hex")).toLowerCase();
const COMMITTEE = arg("committee");
const SUBMIT = arg("submit", "form");
const POSITION = arg("position");
const SPACING = Number(arg("spacing", 14)) * 1000;
const env = Object.fromEntries((ENV_FILE ?? "").split(",").filter(Boolean).flatMap((file) => readFileSync(file, "utf8").split(/\r?\n/)).filter((l) => l.includes("=")).map((l) => [l.slice(0, l.indexOf("=")).trim(), l.slice(l.indexOf("=") + 1).trim()]));
const ADMIN_EMAIL = env[`${PREFIX}_EMAIL`];
const ADMIN_PASSWORD = env[`${PREFIX}_PASSWORD`];
let registrationToken = env[`${PREFIX}_TOKEN`] ?? null;
const needsSite = ["reproduce", "verify"].includes(PHASE) && SUBMIT === "form";
if (!ADMIN || !OUT || !ENV_FILE || !PREFIX || !COMMITTEE || !ADMIN_EMAIL || !ADMIN_PASSWORD || !/^[a-f0-9]{6}$/.test(TAG) || (needsSite && !SITE) || (SUBMIT === "api" && !POSITION && PHASE === "verify") || (PHASE === "reproduce" && !registrationToken)) {
  console.error("usage: --phase reproduce|verify|status|readonly --admin <url> --env <file[,file]> --prefix <KEY_PREFIX> --out <dir> --tag <6 hex> --committee <id> [--site <url> | --submit api --api <url> --position <id>] [...]");
  process.exit(2);
}
mkdirSync(OUT, { recursive: true });
const SHOTS = join(OUT, "shots");
mkdirSync(SHOTS, { recursive: true });

const require = createRequire(import.meta.url);
const puppeteer = require("puppeteer-core");
const chrome = [process.env.CHROME_PATH, "C:/Program Files/Google/Chrome/Application/chrome.exe", "C:/Program Files (x86)/Google/Chrome/Application/chrome.exe", "/usr/bin/google-chrome", "/usr/bin/chromium"].filter(Boolean).find(existsSync);
if (!chrome) throw new Error("No Chrome found; set CHROME_PATH");

const MARK = `QA-CMT-${TAG}`;
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const secrets = [ADMIN_PASSWORD, registrationToken].filter(Boolean);
const mask = (text) => secrets.reduce((s, secret) => s.split(secret).join("<secret>"), String(text)).replace(/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/gi, "<uuid>");
const isBangla = (text) => /[\u0980-\u09FF]/.test(text);

// ---------------------------------------------------------------------------------------------------------- reporting
const checks = [];
const notes = [];
const check = (id, description, ok, detail = "") => {
  const row = { id, description, ok: Boolean(ok), detail: mask(detail).slice(0, 700) };
  checks.push(row);
  console.log(`${row.ok ? "PASS" : "FAIL"}  ${id.padEnd(5)} ${description}${row.ok || !row.detail ? "" : `  -> ${row.detail.slice(0, 320)}`}`);
};
const note = (text) => { notes.push(mask(text)); console.log(`       ${mask(text)}`); };
const finish = (code = null) => {
  const failed = checks.filter((c) => !c.ok);
  writeFileSync(join(OUT, `report-${PHASE}.json`), JSON.stringify({ phase: PHASE, tag: TAG, passed: checks.length - failed.length, failed: failed.length, checks, notes }, null, 2));
  console.log(`\n${checks.length - failed.length}/${checks.length} checks passed${failed.length ? `; FAILED: ${failed.map((c) => c.id).join(", ")}` : ""}`);
  process.exit(code ?? (failed.length ? 1 : 0));
};

// ---------------------------------------------------------------------------------------------------------- browser + helpers
const browser = await puppeteer.launch({ executablePath: chrome, headless: true, args: ["--no-sandbox", "--disable-dev-shm-usage", "--hide-scrollbars"] });
const problems = [];
function watch(page, label) {
  page.on("console", (m) => { if (m.type() === "error" && !/status of (419|422|403|404)/.test(m.text())) problems.push(`${label} console: ${mask(m.text()).slice(0, 160)}`); });
  page.on("pageerror", (e) => problems.push(`${label} pageerror: ${mask(String(e)).slice(0, 160)}`));
}
const shot = async (page, name) => { await page.screenshot({ path: join(SHOTS, `${name}.png`) }).catch(() => {}); };
async function newPage(label, viewport = { width: 1366, height: 1000 }, scheme = "light") {
  const context = await browser.createBrowserContext();
  const page = await context.newPage();
  await page.setViewport(viewport);
  await page.emulateMediaFeatures([{ name: "prefers-color-scheme", value: scheme }]);
  page.on("dialog", (d) => d.accept().catch(() => {}));
  watch(page, label);
  return { page, context };
}
async function login(page, email, password) {
  await page.goto(`${ADMIN}/login`, { waitUntil: "networkidle2", timeout: 90000 });
  await page.type("#email", email, { delay: 1 });
  await page.type("#password", password, { delay: 1 });
  await Promise.all([page.waitForNavigation({ waitUntil: "networkidle2", timeout: 90000 }), page.click('form button[type="submit"]')]);
  return !page.url().includes("/login");
}
const strongPassword = () => `Qa-${randomBytes(9).toString("base64url")}-9aZ#`;

/** A generated picture of about the size of a real one (a ~1250 px square PNG of a couple of MB): never a person. */
async function makePhoto(name) {
  const page = await browser.newPage();
  const dataUrl = await page.evaluate(async () => {
    const size = 1254;
    const canvas = document.createElement("canvas");
    canvas.width = canvas.height = size;
    const g = canvas.getContext("2d");
    const gradient = g.createLinearGradient(0, 0, size, size);
    gradient.addColorStop(0, "#c8641e");
    gradient.addColorStop(1, "#1d4f6e");
    g.fillStyle = gradient;
    g.fillRect(0, 0, size, size);
    let seed = 12345;
    const rnd = () => ((seed = (seed * 1664525 + 1013904223) % 4294967296) / 4294967296);
    for (let i = 0; i < 260; i++) {
      g.fillStyle = `rgba(${Math.floor(rnd() * 255)},${Math.floor(rnd() * 255)},${Math.floor(rnd() * 255)},${0.15 + rnd() * 0.5})`;
      g.beginPath();
      g.arc(rnd() * size, rnd() * size, 10 + rnd() * 140, 0, Math.PI * 2);
      g.fill();
    }
    for (let i = 0; i < 60000; i++) {
      g.fillStyle = `rgba(${Math.floor(rnd() * 255)},${Math.floor(rnd() * 255)},${Math.floor(rnd() * 255)},0.55)`;
      g.fillRect(rnd() * size, rnd() * size, 2, 2);
    }
    g.fillStyle = "#ffffff";
    g.font = "bold 96px sans-serif";
    g.fillText("QA", 80, 200);
    return canvas.toDataURL("image/png");
  });
  await page.close();
  const path = join(OUT, name);
  writeFileSync(path, Buffer.from(dataUrl.split(",")[1], "base64"));
  return { path, bytes: statSync(path).size };
}

// ---- the public form (or, locally, the same endpoint it posts to) ----
// The numeric throttle on the public API is one bucket per client address and ALL visitors reach it from the Next server's address;
// an application costs two hits (the form page, the post), so no more than two per 65 seconds.
const sentAt = [];
const BURST = Number(arg("burst", 2)); // applications per 65 s; a local pair has its own, unshared limit (see --burst 5)
async function throttleGate() {
  for (;;) {
    const now = Date.now();
    while (sentAt.length && now - sentAt[0] > 65000) sentAt.shift();
    if (sentAt.length < BURST) break;
    note(`holding the next submission ${Math.round((sentAt[0] + 65000 - now) / 1000)} s to stay under the shared limit`);
    await sleep(sentAt[0] + 65000 - now + 250);
  }
  sentAt.push(Date.now());
}
let counter = Number(arg("offset", 0));
/** Files one QA application with a photo; resolves with its name and how it ended. `emails` may name an address for a letter. */
async function fileApplication(photoPath, emails = {}) {
  const letter = String.fromCharCode(97 + counter++);
  const person = { letter: letter.toUpperCase(), name: `${MARK} ${letter.toUpperCase()}`, email: emails[letter.toUpperCase()] ?? `qacmt${TAG}${letter}@example.test` };
  await throttleGate();
  if (SUBMIT === "api") {
    const form = new FormData();
    form.set("registration_token", registrationToken);
    form.set("committee_position_id", POSITION);
    form.set("full_name", person.name);
    form.set("email", person.email);
    form.set("phone", "01700000000");
    form.set("provatferi_comment", "Disposable QA data — ignore.");
    form.set("publishing_consent", "1");
    form.set("accuracy_declaration", "1");
    form.set("photo", new Blob([readFileSync(photoPath)], { type: "image/png" }), "qa.png");
    const res = await fetch(`${API}/api/v1/public/committee-submissions`, { method: "POST", body: form, headers: { Accept: "application/json" } });
    return { ...person, ok: res.status === 201, detail: `HTTP ${res.status}` };
  }
  const { page, context } = await newPage("public-form");
  try {
    await page.goto(`${SITE}/committee/register/${registrationToken}`, { waitUntil: "networkidle0", timeout: 90000 });
    await page.waitForSelector("#full_name", { timeout: 30000 });
    await page.select("#committee_position_id", await page.$eval("#committee_position_id", (s) => [...s.options].find((o) => o.value)?.value ?? ""));
    await page.type("#full_name", person.name, { delay: 0 });
    await page.type("#email", person.email, { delay: 0 });
    await page.type("#phone", "01700000000", { delay: 0 });
    await page.type("#provatferi_comment", "Disposable QA data — ignore.", { delay: 0 });
    await (await page.$("#photo")).uploadFile(photoPath);
    await page.click("#publishing_consent");
    await page.click("#accuracy_declaration");
    await sleep(600);
    await page.click('form button[type="submit"]');
    const ended = await page.waitForFunction(() => {
      if (/সফলভাবে জমা হয়েছে/.test(document.body.innerText)) return "done";
      if (document.querySelector("form [role='alert'], .form-field-error")) return "error";
      return null;
    }, { timeout: 90000, polling: 200 }).then((h) => h.jsonValue()).catch(() => "timeout");
    return { ...person, ok: ended === "done", detail: ended };
  } finally {
    await context.close();
  }
}

// ---- disposable inboxes (mail.tm), to prove that the applicant's e-mail still goes out, once, after the answer ----
const MAIL = arg("mail", "mailtm"); // mailtm | none
const MAILTM = "https://api.mail.tm";
async function mailtm(path, { method = "GET", token, body } = {}) {
  for (let attempt = 0; attempt < 6; attempt++) {
    const r = await fetch(MAILTM + path, {
      method,
      headers: { Accept: "application/json", "Content-Type": "application/json", ...(token ? { Authorization: `Bearer ${token}` } : {}) },
      body: body ? JSON.stringify(body) : undefined,
    });
    if (r.status === 429) { await sleep(2500); continue; }
    const text = await r.text();
    let json = null;
    try { json = JSON.parse(text); } catch { /* not JSON */ }
    return { status: r.status, json, text };
  }
  return { status: 429, json: null, text: "" };
}
async function makeInbox(local) {
  const domains = await mailtm("/domains");
  const list = Array.isArray(domains.json) ? domains.json : domains.json?.["hydra:member"] ?? [];
  const domain = list.find((d) => d.isActive !== false)?.domain;
  if (!domain) throw new Error("mail.tm offers no domain");
  const address = `${local}@${domain}`;
  const password = randomBytes(12).toString("hex");
  secrets.push(password);
  const account = await mailtm("/accounts", { method: "POST", body: { address, password } });
  if (account.status !== 201) throw new Error(`mail.tm account: ${account.status}`);
  const token = await mailtm("/token", { method: "POST", body: { address, password } });
  if (token.json?.token) secrets.push(token.json.token);
  return { address, token: token.json?.token };
}
const inboxMessages = async (inbox) => {
  const r = await mailtm("/messages", { token: inbox.token });
  return Array.isArray(r.json) ? r.json : r.json?.["hydra:member"] ?? [];
};
async function waitForMail(inbox, timeoutMs) {
  const until = Date.now() + timeoutMs;
  while (Date.now() < until) {
    const found = await inboxMessages(inbox);
    if (found.length) return found;
    await sleep(5000);
  }
  return [];
}

// ---- the admin screens ----
const committeeBase = (id = COMMITTEE) => `${ADMIN}/admin/organization/committees/${id}`;
async function findSubmissionId(page, name) {
  for (let attempt = 0; attempt < 4; attempt++) {
    for (let p = 1; p <= 4; p++) {
      await page.goto(`${committeeBase()}/submissions?page=${p}`, { waitUntil: "networkidle2", timeout: 90000 });
      const id = await page.evaluate((who) => {
        const link = [...document.querySelectorAll("tbody a[href*='/submissions/']")].find((a) => a.innerText.includes(who));
        return link ? (link.getAttribute("href").match(/submissions\/(\d+)/) ?? [])[1] ?? null : null;
      }, name);
      if (id) return Number(id);
    }
    await sleep(2000);
  }
  return null;
}
const reviewUrl = (id, committee = COMMITTEE) => `${committeeBase(committee)}/submissions/${id}`;
const photoUrl = (id, committee = COMMITTEE) => `${reviewUrl(id, committee)}/photo`;
const readReview = (page) => page.evaluate(() => {
  const kindOfEl = (a) => ["success", "danger", "warning", "info", "secondary"].find((k) => a.classList.contains(`alert-${k}`)) ?? "";
  const forms = [...document.querySelectorAll("form")].filter((f) => /\/submissions\//.test(f.getAttribute("action") ?? "")).map((f) => ({
    action: (f.getAttribute("action") ?? "").replace(/^https?:\/\/[^/]+/, "").replace(/\/\d+(?=\/|$)/g, "/<id>"),
    spoof: f.querySelector('input[name="_method"]')?.value ?? null,
    button: f.querySelector("button")?.innerText.trim() ?? null,
    guarded: f.hasAttribute("data-form-guard"),
  }));
  const wrap = document.querySelector("[data-photo-state]");
  const applicantImg = wrap ? wrap.querySelector("img") : [...document.querySelectorAll("img")].find((i) => /\/photo\b|\/storage\/committee\//.test(i.getAttribute("src") ?? ""));
  return {
    path: location.pathname.replace(/\/\d+/g, "/<id>"),
    lang: document.documentElement.lang,
    status: document.querySelector(".badge")?.innerText.trim() ?? null,
    forms,
    photoState: wrap?.getAttribute("data-photo-state") ?? null,
    photo: applicantImg ? { src: (applicantImg.getAttribute("src") ?? "").replace(/^https?:\/\/[^/]+/, "").replace(/\/\d+/g, "/<id>").replace(/[0-9a-f]{8}-[0-9a-f-]{27}/gi, "<uuid>"), complete: applicantImg.complete, naturalWidth: applicantImg.naturalWidth, naturalHeight: applicantImg.naturalHeight } : null,
    placeholder: !!document.querySelector(".bg-secondary-subtle .ti-user"),
    alerts: [...document.querySelectorAll(".alert")].map((a) => a.innerText.replace(/\s+/g, " ").trim().slice(0, 240)),
    alertKinds: [...document.querySelectorAll(".alert")].map((a) => kindOfEl(a)),
    historyEntries: document.querySelectorAll("ul.list-unstyled li.border-bottom").length,
    approvedNotice: !!document.querySelector(".alert-success[role='status']"),
    publicPhotoUrlInHtml: document.documentElement.outerHTML.includes("/storage/committee/"),
    h1: document.querySelector("h1")?.innerText.trim().slice(0, 60) ?? null,
    bodyStart: document.body.innerText.replace(/\s+/g, " ").trim().slice(0, 120),
    bodyText: document.body.innerText,
  };
});
const strip = ({ bodyText, ...rest }) => rest; // what may be printed

/** Moves the QA committee between draft and upcoming through the real lifecycle form; refuses a page without the QA marker. */
async function setCommitteeStatus(page, target) {
  await page.goto(committeeBase(), { waitUntil: "networkidle2", timeout: 90000 });
  const isQa = await page.evaluate((mark) => document.body.innerText.includes(mark), MARK);
  if (!isQa) return { ok: false, state: "not-a-qa-committee" };
  const offered = await page.evaluate((to) => [...(document.querySelector('form[action$="/status"] select[name="status"]')?.options ?? [])].map((o) => o.value).includes(to), target);
  if (offered) {
    await page.select('form[action$="/status"] select[name="status"]', target);
    await Promise.all([page.waitForNavigation({ waitUntil: "networkidle2", timeout: 90000 }), page.click('form[action$="/status"] button[type="submit"]')]);
  }
  await page.goto(committeeBase(), { waitUntil: "networkidle2", timeout: 90000 });
  const options = await page.evaluate(() => [...(document.querySelector('form[action$="/status"] select[name="status"]')?.options ?? [])].map((o) => o.value));
  // The lifecycle form offers exactly the legal next states: draft → upcoming|active, upcoming → active|draft.
  const state = options.includes("draft") ? "upcoming" : options.includes("upcoming") ? "draft" : "other";
  return { ok: state === target, state };
}

// ============================================================================================================ STATUS
// An "upcoming" committee is listed on the public website, so the QA committee is kept in "draft" whenever it is not being filled.
if (PHASE === "status") {
  const target = arg("set");
  if (!["draft", "upcoming"].includes(target)) { console.error("--set draft|upcoming"); process.exit(2); }
  const { page } = await newPage("admin");
  check("S0", "the QA admin signs in", await login(page, ADMIN_EMAIL, ADMIN_PASSWORD));
  const r = await setCommitteeStatus(page, target);
  check("S1", `the QA committee (${MARK}) is now ${target}`, r.ok, r.state);
  await browser.close();
  finish();
}

// ============================================================================================================ READ-ONLY
// One real application, looked at the way a reviewer opens it. GET requests only; nothing is clicked, and neither a name, an
// address nor a screenshot is kept.
if (PHASE === "readonly") {
  const submission = arg("submission");
  const { page } = await newPage("admin");
  check("O0", "the admin signs in", await login(page, ADMIN_EMAIL, ADMIN_PASSWORD));
  const response = await page.goto(reviewUrl(submission), { waitUntil: "networkidle2", timeout: 90000 });
  const r = await readReview(page);
  await sleep(1500);
  const again = await page.evaluate(() => { const i = document.querySelector("[data-photo-state] img"); return i ? { complete: i.complete, naturalWidth: i.naturalWidth, naturalHeight: i.naturalHeight } : null; });
  note(`review page of #${submission}: HTTP ${response?.status()}, status "${r.status}", photo state ${r.photoState}, photo ${JSON.stringify(again)}, decision forms offered: ${JSON.stringify(r.forms.map((f) => f.button))}, history entries ${r.historyEntries}, approved notice ${r.approvedNotice}`);
  check("O1", "the review page answers 200", response?.status() === 200);
  check("O2", "the applicant's photo is shown (an image that loaded, through the authenticated route)", r.photoState === "available" && again?.naturalWidth > 0 && /\/photo$/.test(r.photo?.src ?? ""), JSON.stringify(again));
  const facts = await page.evaluate(async (url) => { const res = await fetch(url, { credentials: "include" }); const b = await res.arrayBuffer(); return { status: res.status, type: res.headers.get("content-type"), cache: res.headers.get("cache-control"), bytes: b.byteLength }; }, photoUrl(submission));
  note(`photo route: ${JSON.stringify(facts)}`);
  check("O3", "the photo route answers 200 with an image type and a private, no-store cache header", facts.status === 200 && /^image\//.test(facts.type ?? "") && /no-store/.test(facts.cache ?? "") && /private/.test(facts.cache ?? ""), JSON.stringify(facts));
  check("O4", "the page offers no decision that is not valid for its state (an approved application offers none)", /approved|অনুমোদিত/i.test(r.status ?? "") ? r.forms.length === 0 : r.forms.length > 0, JSON.stringify(r.forms));
  check("O5", "no bare 422 page", !/Unprocessable|Oops! An Error/.test(r.bodyText));
  await browser.close();
  finish();
}

// ============================================================================================================ SWEEP
// A read-only look at EVERY real committee application (never a QA one): GET of the review page and of the photo route, counted by
// state. Nothing is clicked or changed and no name, address or screenshot is kept: ids, statuses and file facts only. It answers
// "does the review page work for the applications that already exist?".
if (PHASE === "sweep") {
  const { page } = await newPage("admin");
  check("W0", "the admin signs in", await login(page, ADMIN_EMAIL, ADMIN_PASSWORD));
  await page.goto(`${ADMIN}/admin/organization/committees`, { waitUntil: "networkidle2", timeout: 90000 });
  const committees = await page.evaluate(() => [...new Set([...document.querySelectorAll("a[href*='/organization/committees/']")].map((a) => (a.getAttribute("href").match(/committees\/(\d+)(?:$|\?|\/)/) ?? [])[1]).filter(Boolean))]);
  const rows = [];
  for (const committee of committees) {
    const isQa = await (async () => { await page.goto(committeeBase(committee), { waitUntil: "networkidle2", timeout: 90000 }); return page.evaluate(() => /QA-CMT-/.test(document.body.innerText)); })();
    if (isQa) { note(`committee ${committee} is a QA committee: skipped`); continue; }
    for (let p = 1; p <= 10; p++) {
      await page.goto(`${committeeBase(committee)}/submissions?page=${p}`, { waitUntil: "networkidle2", timeout: 90000 });
      const found = await page.evaluate(() => [...document.querySelectorAll("tbody tr")].map((tr) => (tr.querySelector("a[href*='/submissions/']")?.getAttribute("href").match(/submissions\/(\d+)/) ?? [])[1]).filter(Boolean));
      rows.push(...found.map((id) => ({ committee, id })));
      if (found.length < 15) break;
    }
  }
  const tally = {};
  const problemsFound = [];
  for (const { committee, id } of rows) {
    const response = await page.goto(reviewUrl(id, committee), { waitUntil: "networkidle2", timeout: 90000 });
    await sleep(200);
    const r = await readReview(page);
    const route = await page.evaluate(async (u) => { const res = await fetch(u, { credentials: "include" }); return { status: res.status, type: res.headers.get("content-type") }; }, photoUrl(id, committee));
    const key = `${(r.status ?? "?").toLowerCase()} | photo ${r.photoState} | route ${route.status}${route.status === 200 ? ` ${route.type}` : ""} | ${r.photoState === "available" ? (r.photo?.naturalWidth > 0 ? "image loaded" : "IMAGE NOT LOADED") : "placeholder"}`;
    tally[key] = (tally[key] ?? 0) + 1;
    const fine = response?.status() === 200 && !/Unprocessable|Oops! An Error/.test(r.bodyText) && (r.photoState === "available" ? r.photo?.naturalWidth > 0 && route.status === 200 : route.status === 404);
    if (!fine) problemsFound.push({ committee, id, http: response?.status(), state: r.photoState, route: route.status });
  }
  note(`${rows.length} real application(s) in ${committees.length} committee(s) looked at (GET only); by state: ${JSON.stringify(tally)}`);
  check("W1", "every real application's review page answers 200 and its photo behaves: an image that loaded through the route, or an honest placeholder with the route answering 404", problemsFound.length === 0, JSON.stringify(problemsFound));
  // The two malformed addresses, asked about a QA application (never a real one): refused, and no image comes back.
  const probeId = arg("probe-id");
  if (probeId) {
    for (const [suffix, what] of [["/photo/..%2f..%2f.env", "dot-dot after /photo"], ["/photo%2f..%2f..%2f.env", "encoded dot-dot"]]) {
      const r = await page.evaluate(async (u) => { const res = await fetch(u, { credentials: "include" }); return { status: res.status, type: res.headers.get("content-type") }; }, `${reviewUrl(probeId)}${suffix}`);
      check("W3", `a malformed photo request (${what}) is refused (404 from Laravel or 403 from the edge) and no image comes back`, [403, 404].includes(r.status) && !/^image\//.test(r.type ?? ""), JSON.stringify(r));
    }
  }
  check("W2", "no console or page error", problems.length === 0, problems.slice(0, 3).join(" | "));
  await browser.close();
  finish();
}

// ============================================================================================================ REPRODUCE
if (PHASE === "reproduce") {
  const photo = await makePhoto("qa-photo.png");
  note(`generated QA photo: ${(photo.bytes / 1024 / 1024).toFixed(2)} MB PNG`);
  const { page, context } = await newPage("admin");
  check("R0", "the QA admin signs in", await login(page, ADMIN_EMAIL, ADMIN_PASSWORD));

  // ---- application A: the photo while pending, then a real double click on Approve ----
  const a = await fileApplication(photo.path);
  check("R1", "a QA application with a photo is filed through the public form", a.ok, a.detail);
  const idA = await findSubmissionId(page, a.name);
  check("R2", "it is listed under the QA committee in Admin", idA !== null, String(idA));
  await page.goto(reviewUrl(idA), { waitUntil: "networkidle2", timeout: 90000 });
  const pending = await readReview(page);
  note(`review page while pending: ${JSON.stringify({ status: pending.status, photo: pending.photo, placeholder: pending.placeholder, forms: pending.forms.map((f) => f.button) })}`);
  await shot(page, "R-pending-review-before-fix");
  check("R3", "DEFECT B reproduced: the applicant's photo is NOT shown while the application is pending (only the placeholder)", pending.photo === null && pending.placeholder === true, JSON.stringify(pending.photo));
  check("R4", "the approve form is offered (PATCH via _method) for the pending application", pending.forms.some((f) => /approve$/.test(f.action) && f.spoof === "PATCH"), JSON.stringify(pending.forms));

  // A real double click (two press/release pairs a few ms apart) on the Approve button, the way a person clicks when nothing seems
  // to happen. The browser sends two requests; the page ends on the answer to the second.
  const statuses = [];
  page.on("response", (res) => { if (res.request().method() !== "GET") statuses.push({ method: res.request().method(), status: res.status(), path: res.url().replace(ADMIN, "").replace(/\/\d+/g, "/<id>") }); });
  const t0 = Date.now();
  await page.click('form[action$="/approve"] button[type="submit"]', { count: 2, delay: 20 }).catch((e) => note(`click ended early: ${String(e).slice(0, 80)}`));
  await sleep(8000);
  const landed = await readReview(page);
  note(`double click, settled ${Date.now() - t0} ms later on: ${JSON.stringify({ path: landed.path, h1: landed.h1, start: landed.bodyStart })}; answers seen: ${JSON.stringify(statuses)}`);
  await shot(page, "R-double-click-result");
  const landedOn422 = /Unprocessable|^422|Oops! An Error/i.test(landed.bodyStart) && landed.status === null;
  const trail = await (async () => { await page.goto(reviewUrl(idA), { waitUntil: "networkidle2", timeout: 90000 }); return readReview(page); })();
  note(`review page after the double click: ${JSON.stringify({ status: trail.status, history: trail.historyEntries, photo: trail.photo, forms: trail.forms.length, alerts: trail.alerts })}`);
  await shot(page, "R-approved-review-before-fix");
  note(`double click: the page the browser ended on ${landedOn422 ? "IS the bare 422 page" : "is the review page"}; history entries ${trail.historyEntries}`);
  check("R6", "the application WAS approved by the first request (status Approved, one audit entry)", /approved|অনুমোদিত/i.test(trail.status ?? "") && trail.historyEntries === 1, JSON.stringify({ status: trail.status, history: trail.historyEntries }));

  // ---- application B: the review page open in TWO tabs; one click in the first (timed), then Approve in the stale second tab ----
  await sleep(SPACING);
  const b = await fileApplication(photo.path);
  check("R7", "a second QA application is filed", b.ok, b.detail);
  const idB = await findSubmissionId(page, b.name);
  await page.goto(reviewUrl(idB), { waitUntil: "networkidle2", timeout: 90000 });
  const second = await context.newPage();
  await second.setViewport({ width: 1366, height: 1000 });
  watch(second, "second-tab");
  await second.goto(reviewUrl(idB), { waitUntil: "networkidle2", timeout: 90000 });
  await page.bringToFront();
  const t1 = Date.now();
  await Promise.all([page.waitForNavigation({ waitUntil: "load", timeout: 90000 }), page.click('form[action$="/approve"] button[type="submit"]')]);
  const oneClick = Date.now() - t1;
  const afterOne = await readReview(page);
  note(`one click: ${oneClick} ms from the click until the review page was back; it shows ${JSON.stringify({ status: afterOne.status, alerts: afterOne.alerts })}`);
  check("R8", "a single click approves and returns to the review page (the flow itself works for a pending application)", /approved|অনুমোদিত/i.test(afterOne.status ?? "") && afterOne.alerts.length >= 1, JSON.stringify(afterOne.alerts));

  // The second tab still shows the page as it was while the application was pending, Approve button included.
  const staleOffered = await second.evaluate(() => !!document.querySelector('form[action$="/approve"] button[type="submit"]'));
  check("R9a", "the second tab still offers Approve (a stale page)", staleOffered);
  await second.bringToFront();
  await Promise.all([second.waitForNavigation({ waitUntil: "load", timeout: 90000 }).catch(() => {}), second.click('form[action$="/approve"] button[type="submit"]')]);
  const afterStale = await readReview(second);
  note(`stale tab: Approve clicked after the first tab had approved: ${JSON.stringify({ path: afterStale.path, start: afterStale.bodyStart })}`);
  await shot(second, "R-stale-tab-result");
  check("R9", "DEFECT A (stale page): the stale tab ends on the bare 'Unprocessable Content' page", /Unprocessable|^422|Oops! An Error/i.test(afterStale.bodyStart), afterStale.bodyStart);

  // The back button after an approval: does the browser bring the old page, Approve button and all, back?
  await page.bringToFront();
  await page.goBack({ waitUntil: "load", timeout: 60000 }).catch(() => {});
  await sleep(1000);
  const backOffered = await page.evaluate(() => !!document.querySelector('form[action$="/approve"] button[type="submit"]')).catch(() => false);
  note(`back button after the approval: the old page ${backOffered ? "IS" : "is not"} offered again with its Approve button (${page.url().replace(ADMIN, "").replace(/\/\d+/g, "/<id>")})`);

  check("T", "no unexpected console or page error", problems.length === 0, problems.slice(0, 4).join(" | "));
  await browser.close();
  finish();
}

// ============================================================================================================ VERIFY
if (PHASE === "verify") {
  const OTHER = arg("other-committee");
  const PRE_PENDING = (arg("pre-pending") ?? "").split(",").filter(Boolean);
  const PRE_APPROVED = (arg("pre-approved") ?? "").split(",").filter(Boolean);
  const SEED = arg("seed") ? JSON.parse(arg("seed")) : null;
  const photo = await makePhoto("qa-photo.png");
  note(`generated QA photo: ${photo.bytes} bytes`);
  const { page: admin, context: adminContext } = await newPage("admin");
  check("V0", "the QA admin signs in", await login(admin, ADMIN_EMAIL, ADMIN_PASSWORD));
  const originalLang = await admin.evaluate(() => document.documentElement.lang);
  const accounts = [];
  const fail = (id, error) => check(`${id}!`, "step ended with an error", false, error?.stack ?? error);

  // ---------------------------------------------------------------- helpers
  const setLanguage = async (code) => {
    await admin.goto(`${ADMIN}/admin`, { waitUntil: "networkidle2", timeout: 90000 });
    if ((await admin.evaluate(() => document.documentElement.lang)) === code) return;
    await Promise.all([admin.waitForNavigation({ waitUntil: "networkidle2", timeout: 90000 }), admin.evaluate((c) => {
      [...document.querySelectorAll('form[action$="/locale"]')].find((f) => f.querySelector('input[name="locale"]').value === c).submit();
    }, code)]);
  };
  /** Sends a decision exactly as a stale page would (same form fields, the page's own token) and reads the answer's alerts. */
  const replay = (page, action, id, fields = {}) => page.evaluate(async (url, extra) => {
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content ?? document.querySelector('input[name="_token"]')?.value ?? "";
    const res = await fetch(url, { method: "POST", body: new URLSearchParams({ _method: "PATCH", _token: csrf, ...extra }), credentials: "include", redirect: "follow" });
    const text = await res.text();
    const doc = new DOMParser().parseFromString(text, "text/html");
    const kind = (a) => ["success", "danger", "warning", "info", "secondary"].find((k) => a.classList.contains(`alert-${k}`)) ?? "";
    return {
      status: res.status, finalPath: new URL(res.url).pathname.replace(/\/\d+/g, "/<id>"),
      alerts: [...doc.querySelectorAll(".alert")].map((a) => ({ kind: kind(a), text: a.textContent.replace(/\s+/g, " ").trim().slice(0, 260) })),
      badge: doc.querySelector(".badge")?.textContent.trim() ?? null,
      bare422: /Unprocessable|Oops! An Error/.test(text),
    };
  }, `${reviewUrl(id)}/${action}`, fields);
  const open = async (page, id) => {
    const r = await page.goto(reviewUrl(id), { waitUntil: "networkidle2", timeout: 90000 });
    await sleep(300);
    return { http: r?.status(), ...(await readReview(page)) };
  };
  const click = (page, sel, wait = true) => Promise.all([wait ? page.waitForNavigation({ waitUntil: "load", timeout: 90000 }) : Promise.resolve(), page.click(sel)]);
  const photoFacts = (page, id, committee = COMMITTEE) => page.evaluate(async (url) => {
    const res = await fetch(url, { credentials: "include" });
    const bytes = (await res.arrayBuffer()).byteLength;
    return { status: res.status, type: res.headers.get("content-type"), cache: res.headers.get("cache-control"), nosniff: res.headers.get("x-content-type-options"), csp: res.headers.get("content-security-policy"), disposition: res.headers.get("content-disposition"), bytes };
  }, photoUrl(id, committee));
  const approveSel = 'form[action$="/approve"] button[type="submit"]';
  const alertText = (r, kind) => r.alerts.filter((_, i) => r.alertKinds[i] === kind).join(" | ");
  const createAccount = async ({ roleName, permissions, email, name }) => {
    const password = strongPassword();
    await admin.goto(`${ADMIN}/admin/system/roles/create`, { waitUntil: "networkidle2", timeout: 90000 });
    await admin.evaluate((n, slugs) => {
      const input = document.querySelector('input[name="name"]');
      input.value = n;
      input.dispatchEvent(new Event("input", { bubbles: true }));
      for (const slug of slugs) {
        const code = [...document.querySelectorAll("label code")].find((c) => c.textContent.trim() === slug);
        if (!code) throw new Error(`the role form has no permission ${slug}`);
        document.getElementById(code.closest("label").getAttribute("for")).checked = true;
      }
    }, roleName, permissions);
    await click(admin, 'form[action$="/system/roles"] button[type="submit"]');
    const roleOk = await admin.evaluate(() => !!document.querySelector(".alert-success"));
    await admin.goto(`${ADMIN}/admin/system/users/create`, { waitUntil: "networkidle2", timeout: 90000 });
    await admin.evaluate((n, mail, pw, role) => {
      const set = (field, value) => { const el = document.querySelector(`input[name="${field}"]`); el.value = value; el.dispatchEvent(new Event("input", { bubbles: true })); };
      set("name", n); set("email", mail); set("password", pw); set("password_confirmation", pw);
      for (const box of document.querySelectorAll('input[name="roles[]"]')) {
        box.checked = (document.querySelector(`label[for="${box.id}"]`)?.textContent.replace(/\s+/g, " ").trim() ?? "").includes(role);
      }
    }, name, email, password, roleName);
    await click(admin, 'form[action$="/system/users"] button[type="submit"]');
    const ok = roleOk && (await admin.evaluate(() => !!document.querySelector(".alert-success")));
    accounts.push(email);
    return { email, password, ok };
  };
  const allProblems = [];

  try {
    // ---------------------------------------------------------------- 1. photos filed BEFORE the release switch
    const preIds = {};
    if (PRE_PENDING.length + PRE_APPROVED.length) {
      for (const letter of PRE_PENDING) {
        const id = await findSubmissionId(admin, `${MARK} ${letter}`);
        preIds[letter] = id;
        const r = id ? await open(admin, id) : null;
        check("V1", `application ${letter}, filed BEFORE the release switch and still pending, shows its photo now (the private file survived the switch)`, r && r.photoState === "available" && r.photo?.complete && r.photo.naturalWidth > 0 && /\/photo$/.test(r.photo.src), JSON.stringify(r && strip(r)));
        if (id) check("V1", `the photo route serves application ${letter}'s original`, (await photoFacts(admin, id)).status === 200);
      }
      for (const letter of PRE_APPROVED) {
        const id = await findSubmissionId(admin, `${MARK} ${letter}`);
        const r = id ? await open(admin, id) : null;
        check("V1", `application ${letter}, approved BEFORE the fix (public copy made then), still shows its photo and says it is approved`, r && r.photoState === "available" && r.photo?.naturalWidth > 0 && r.approvedNotice && r.forms.length === 0, JSON.stringify(r && strip(r)));
      }
    }

    // ---------------------------------------------------------------- 2. two throw-away accounts (a reviewer who may only look, and one with no committee permission)
    const viewer = await createAccount({ roleName: `${MARK} viewer role`, permissions: ["organization.view"], email: `qa-cmt-${TAG}-viewer@example.test`, name: `${MARK} viewer` });
    const outsider = await createAccount({ roleName: `${MARK} outsider role`, permissions: ["membership.view"], email: `qa-cmt-${TAG}-outsider@example.test`, name: `${MARK} outsider` });
    check("V2", "two throw-away accounts are created through the Roles and Users screens (view-only reviewer; no committee permission)", viewer.ok && outsider.ok);

    // ---------------------------------------------------------------- 3. open the committee, take a registration link, file the applications, close it again
    const upcoming = await setCommitteeStatus(admin, "upcoming");
    check("V3", "the QA committee is set to upcoming (so its registration link works)", upcoming.ok, upcoming.state);
    registrationToken = registrationToken ?? await (async () => {
      await admin.goto(committeeBase(), { waitUntil: "networkidle2", timeout: 90000 });
      await admin.evaluate(() => { const d = document.querySelector('form[action$="/registration-links"]')?.closest("details"); if (d) d.open = true; });
      await click(admin, 'form[action$="/registration-links"] button[type="submit"]');
      return admin.evaluate(() => (document.querySelector("code.text-break")?.innerText.match(/\/committee\/register\/([A-Za-z0-9]+)/) ?? [])[1] ?? null);
    })();
    if (registrationToken) secrets.push(registrationToken);
    check("V3", "a registration link for the QA committee is created through the committee page", Boolean(registrationToken));
    // The first two applicants get disposable inboxes, so the approval e-mail (sent AFTER the answer now) can be seen to arrive, once.
    const inboxes = {};
    if (MAIL === "mailtm") {
      try {
        for (const letter of [String.fromCharCode(65 + counter), String.fromCharCode(66 + counter)]) inboxes[letter] = await makeInbox(`qacmt${TAG}${letter.toLowerCase()}`);
      } catch (error) {
        note(`no disposable inbox (${String(error).slice(0, 80)}): the e-mail check is skipped`);
      }
    }
    const filed = [];
    for (let i = 0; i < 5; i++) {
      const f = await fileApplication(photo.path, Object.fromEntries(Object.entries(inboxes).map(([l, b]) => [l, b.address])));
      filed.push(f);
      check("V3", `QA application ${f.letter} is filed through the ${SUBMIT === "api" ? "registration endpoint" : "public form"} with a ${(photo.bytes / 1024 / 1024).toFixed(1)} MB photo`, f.ok, f.detail);
    }
    const closed = await setCommitteeStatus(admin, "draft");
    check("V3", "the QA committee is set back to draft as soon as the last application is in (an upcoming committee is public)", closed.ok, closed.state);
    const ids = {};
    for (const f of filed) ids[f.letter] = await findSubmissionId(admin, f.name);
    check("V3", "all five applications are listed under the QA committee", Object.values(ids).every(Boolean), JSON.stringify(ids));
    const [A1, A2, A3, A4, A5] = filed.map((f) => ids[f.letter]);

    // ---------------------------------------------------------------- 4. A1: the photo of a PENDING application, who may see it, then a double click on Approve
    const pending = await open(admin, A1);
    note(`A1 pending: ${JSON.stringify(strip(pending))}`);
    await shot(admin, "V-A1-pending-review");
    check("F", "DEFECT B fixed: the pending application shows the applicant's photo — an image that loaded, from the authenticated route, not a public /storage address", pending.photoState === "available" && pending.photo?.complete && pending.photo.naturalWidth > 0 && /\/photo$/.test(pending.photo.src) && !pending.publicPhotoUrlInHtml, JSON.stringify(pending.photo));
    const facts = await photoFacts(admin, A1);
    note(`photo route facts: ${JSON.stringify({ ...facts, disposition: facts.disposition?.replace(/\d+/, "<id>") })}`);
    check("F", "the route answers 200 as image/png with the submitted number of bytes, private + no-store, nosniff, a sandboxing CSP and a generic inline file name", facts.status === 200 && facts.type === "image/png" && facts.bytes === photo.bytes && /private/.test(facts.cache ?? "") && /no-store/.test(facts.cache ?? "") && facts.nosniff === "nosniff" && /sandbox/.test(facts.csp ?? "") && /^inline; filename="?applicant-photo-\d+\.png"?$/.test(facts.disposition ?? ""), JSON.stringify(facts));

    const anon = await fetch(photoUrl(A1), { redirect: "manual", headers: { accept: "image/*,*/*" } }).catch((e) => ({ status: 0, headers: new Headers(), error: String(e) }));
    check("G", "an anonymous request for the photo gets no image (a redirect to the login page, or a refusal)", ![200, 206].includes(anon.status) && !/^image\//.test(anon.headers.get("content-type") ?? "") && ([301, 302, 303, 307, 308].includes(anon.status) ? /\/login/.test(anon.headers.get("location") ?? "") : [401, 403].includes(anon.status)), `${anon.status} ${anon.headers.get("location") ?? ""}`);

    const { page: viewerPage, context: viewerContext } = await newPage("viewer");
    check("H", "the view-only reviewer signs in", await login(viewerPage, viewer.email, viewer.password));
    const asViewer = await open(viewerPage, A1);
    check("H", "an authorised reviewer (organization.view) sees the review page WITH the photo and is offered no decision", asViewer.http === 200 && asViewer.photoState === "available" && asViewer.photo?.naturalWidth > 0 && asViewer.forms.length === 0, JSON.stringify(strip(asViewer)));
    const viewerPhoto = await photoFacts(viewerPage, A1);
    check("H", "...and the photo route answers that reviewer with the image", viewerPhoto.status === 200 && viewerPhoto.type === "image/png", JSON.stringify(viewerPhoto));
    const forged = await replay(viewerPage, "approve", A1);
    const stillPending = await open(admin, A1);
    check("H", "a decision sent by hand by the view-only reviewer is refused (403) and changes nothing", forged.status === 403 && /await|পর্যালোচনা|Awaiting/i.test(stillPending.status ?? "") && stillPending.historyEntries === 0, JSON.stringify({ forged: forged.status, status: stillPending.status, history: stillPending.historyEntries }));
    await viewerContext.close();

    const { page: outsiderPage, context: outsiderContext } = await newPage("outsider");
    check("H", "the account without any committee permission signs in", await login(outsiderPage, outsider.email, outsider.password));
    const outsiderReview = await outsiderPage.goto(reviewUrl(A1), { waitUntil: "networkidle2", timeout: 90000 });
    const outsiderPhoto = await photoFacts(outsiderPage, A1);
    check("H", "that account is refused (403) both the review page and the photo", outsiderReview?.status() === 403 && outsiderPhoto.status === 403 && !/^image\//.test(outsiderPhoto.type ?? ""), JSON.stringify({ review: outsiderReview?.status(), photo: outsiderPhoto.status, type: outsiderPhoto.type }));
    await outsiderContext.close();

    const probes = [
      [`${reviewUrl(A1)}/photo/..%2f..%2f.env`, "dot-dot after /photo"],
      [`${reviewUrl(A1)}/photo%2f..%2f..%2f.env`, "encoded dot-dot"],
      [`${committeeBase()}/submissions/0/photo`, "submission 0"],
      [`${committeeBase()}/submissions/abc/photo`, "a non-numeric submission"],
      [`${committeeBase()}/submissions/9999999/photo`, "a submission that does not exist"],
    ];
    // A '..%2f' path may be refused by the host's edge (a 403, before Laravel sees it) or by Laravel itself (a 404): either is a refusal.
    for (const [url, what] of probes) {
      const r = await admin.evaluate(async (u) => { const res = await fetch(u, { credentials: "include" }); return { status: res.status, type: res.headers.get("content-type") }; }, url);
      const dotted = /\.\.%2f|%2f\.\./i.test(url);
      check("J", `a malformed photo request (${what}) is refused (${dotted ? "404 from Laravel or 403 from the edge" : "404"}) and no image comes back`, (r.status === 404 || (dotted && r.status === 403)) && !/^image\//.test(r.type ?? ""), JSON.stringify(r));
    }
    const smuggled = await admin.evaluate(async (u) => { const res = await fetch(u, { credentials: "include" }); return { status: res.status, type: res.headers.get("content-type"), bytes: (await res.arrayBuffer()).byteLength }; }, `${photoUrl(A1)}?path=../../.env&file=/etc/passwd&photo_path=../../.env`);
    check("J", "a path smuggled in the query string is ignored: the answer is still this application's own photo, byte for byte", smuggled.status === 200 && smuggled.type === "image/png" && smuggled.bytes === photo.bytes, JSON.stringify(smuggled));
    if (OTHER) {
      const cross = await admin.evaluate(async (u) => { const res = await fetch(u, { credentials: "include" }); return { status: res.status, type: res.headers.get("content-type") }; }, photoUrl(A1, OTHER));
      check("J", "the same application asked for through ANOTHER committee's address is not found (404)", cross.status === 404 && !/^image\//.test(cross.type ?? ""), JSON.stringify(cross));
    }

    // A real double click on Approve. The guard sends ONE request; the page lands on the review page with one success message.
    await admin.goto(reviewUrl(A1), { waitUntil: "networkidle2", timeout: 90000 });
    const posts = [];
    const onRequest = (req) => { if (req.method() !== "GET" && /\/approve$/.test(req.url())) posts.push(req.url()); };
    admin.on("request", onRequest);
    const guarded = (await readReview(admin)).forms.every((f) => f.guarded);
    await admin.click(approveSel, { count: 2, delay: 20 }).catch((e) => note(`click ended early: ${String(e).slice(0, 80)}`));
    await admin.waitForFunction(() => /approved|অনুমোদিত/i.test(document.querySelector(".badge")?.innerText ?? ""), { timeout: 60000 }).catch(() => {});
    await sleep(1500);
    admin.off("request", onRequest);
    const doubled = await readReview(admin);
    note(`A1 double click: ${posts.length} approve request(s) left the browser; landed on ${JSON.stringify(strip(doubled))}`);
    await shot(admin, "V-A1-after-double-click");
    check("A", "a real double click on Approve ends on the review page (no 422 page), status Approved, exactly one audit entry, a success message and no error message", /approved|অনুমোদিত/i.test(doubled.status ?? "") && doubled.path.endsWith("/submissions/<id>") && doubled.historyEntries === 1 && !/Unprocessable|Oops! An Error/.test(doubled.bodyStart) && doubled.alertKinds.includes("success") && !doubled.alertKinds.includes("danger"), JSON.stringify(strip(doubled)));
    check("D", "every decision form carries the double-submit guard, so the browser sent ONE approve request for the two clicks", guarded && posts.length === 1, `${posts.length} request(s), guarded=${guarded}`);
    check("F", "after approval the photo is still shown (through the route) and the page says the applicant now holds a seat", doubled.photoState === "available" && doubled.photo?.naturalWidth > 0 && doubled.approvedNotice && doubled.forms.length === 0, JSON.stringify(doubled.photo));

    // The seat exists exactly once on the committee page.
    await admin.goto(committeeBase(), { waitUntil: "networkidle2", timeout: 90000 });
    const seatRows = await admin.evaluate((who) => document.body.innerText.split(who).length - 1, filed[0].name);
    check("D", "the committee page lists the approved QA applicant once (one seat)", seatRows === 1, `${seatRows} mention(s)`);

    // ---------------------------------------------------------------- 5. A2: a stale second tab after the first tab approved
    const tab2 = await adminContext.newPage();
    await tab2.setViewport({ width: 1366, height: 1000 });
    watch(tab2, "stale-tab");
    await tab2.goto(reviewUrl(A2), { waitUntil: "networkidle2", timeout: 90000 });
    await admin.bringToFront();
    await admin.goto(reviewUrl(A2), { waitUntil: "networkidle2", timeout: 90000 });
    const t1 = Date.now();
    await click(admin, approveSel);
    const oneClickMs = Date.now() - t1;
    const approvedA2 = await readReview(admin);
    check("A", "approving a pending application with one click works", /approved|অনুমোদিত/i.test(approvedA2.status ?? "") && approvedA2.alertKinds.includes("success"), JSON.stringify(strip(approvedA2)));
    note(`single click: ${oneClickMs} ms from the click until the review page was back (production before the fix, same photo size: 3433 ms, the e-mail then being sent before the answer)`);
    check("A", "the stale tab still offers Approve (it was opened while the application was pending)", await tab2.evaluate((sel) => !!document.querySelector(sel), approveSel));
    await tab2.bringToFront();
    await click(tab2, approveSel);
    const stale = await readReview(tab2);
    note(`A2 stale tab after the first approved: ${JSON.stringify(strip(stale))}`);
    await shot(tab2, "V-A2-stale-tab-answer");
    check("B", "DEFECT A fixed: Approve in the stale tab lands on the review page with a plain 'already approved' message (info, not an error), not a 422 page", stale.path.endsWith("/submissions/<id>") && !/Unprocessable|Oops! An Error/.test(stale.bodyStart) && stale.alertKinds.includes("info") && !stale.alertKinds.includes("danger") && /approved|অনুমোদিত/i.test(stale.status ?? ""), JSON.stringify(strip(stale)));
    check("G", "...and nothing was written twice: still exactly one audit entry for the application", stale.historyEntries === 1, `${stale.historyEntries}`);
    await tab2.close();

    // ---------------------------------------------------------------- 6. A3: a decision that is no longer possible
    const tab3 = await adminContext.newPage();
    await tab3.setViewport({ width: 1366, height: 1000 });
    watch(tab3, "stale-tab-3");
    await tab3.goto(reviewUrl(A3), { waitUntil: "networkidle2", timeout: 90000 });
    await admin.bringToFront();
    await admin.goto(reviewUrl(A3), { waitUntil: "networkidle2", timeout: 90000 });
    await admin.type('form[action$="/reject"] textarea[name="admin_note"]', "QA: disposable application, rejected on purpose.");
    await click(admin, 'form[action$="/reject"] button[type="submit"]');
    const rejected = await readReview(admin);
    check("B", "rejecting with a reason works (status Rejected, one success message, no decision offered any more)", /rejected|প্রত্যাখ্যাত/i.test(rejected.status ?? "") && rejected.alertKinds.includes("success") && rejected.forms.length === 0, JSON.stringify(strip(rejected)));
    await tab3.bringToFront();
    await click(tab3, approveSel);
    const blocked = await readReview(tab3);
    note(`A3 stale Approve after the rejection: ${JSON.stringify(strip(blocked))}`);
    await shot(tab3, "V-A3-stale-approve-after-reject");
    check("B", "Approve in a stale tab after a rejection lands on the review page with an error message that names the state — no 422 page — and the application stays Rejected with no seat", blocked.path.endsWith("/submissions/<id>") && blocked.alertKinds.includes("danger") && /rejected|প্রত্যাখ্যাত/i.test(blocked.status ?? "") && /rejected|প্রত্যাখ্যাত/i.test(alertText(blocked, "danger")) && !/Unprocessable|Oops! An Error/.test(blocked.bodyStart) && blocked.historyEntries === 1, JSON.stringify(strip(blocked)));
    await tab3.close();
    const rejectAgain = await replay(admin, "reject", A3, { admin_note: "QA: sent again" });
    check("B", "rejecting a second time (a stale form) is answered with 'already rejected' and writes nothing", rejectAgain.status === 200 && rejectAgain.alerts.some((a) => a.kind === "info") && !rejectAgain.alerts.some((a) => a.kind === "danger") && !rejectAgain.bare422, JSON.stringify(rejectAgain));

    // ---------------------------------------------------------------- 7. A4: a correction request, sent twice
    await admin.goto(reviewUrl(A4), { waitUntil: "networkidle2", timeout: 90000 });
    await admin.type('form[action$="/request-correction"] textarea[name="admin_note"]', "QA: please send the photo again (disposable).");
    await click(admin, 'form[action$="/request-correction"] button[type="submit"]');
    const corrected = await readReview(admin);
    check("B", "asking for a correction works: status 'correction requested', a success message and the one-time link card", /correction|সংশোধন/i.test(corrected.status ?? "") && corrected.alertKinds.includes("success") && /\/committee\/register\/correct\//.test(corrected.bodyText), JSON.stringify(strip(corrected)));
    const correctionAgain = await replay(admin, "request-correction", A4, { admin_note: "QA: asked twice" });
    check("B", "asking a second time (a stale form) says it was already requested, issues no second link and writes nothing", correctionAgain.status === 200 && correctionAgain.alerts.some((a) => a.kind === "info") && !correctionAgain.bare422 && !/committee\/register\/correct\//.test(JSON.stringify(correctionAgain.alerts)), JSON.stringify(correctionAgain));
    const correctedAfter = await open(admin, A4);
    check("B", "the correction request left exactly one audit entry", correctedAfter.historyEntries === 1, `${correctedAfter.historyEntries}`);

    // ---------------------------------------------------------------- 8. A5: the 'approve from correction requested' path and approval timing
    await admin.goto(reviewUrl(A5), { waitUntil: "networkidle2", timeout: 90000 });
    const t5 = Date.now();
    await click(admin, approveSel);
    const approvedA5 = await readReview(admin);
    note(`A5 single click: ${Date.now() - t5} ms; ${JSON.stringify({ status: approvedA5.status, kinds: approvedA5.alertKinds })}`);
    check("A", "a second single-click approval (a fresh application) also works", /approved|অনুমোদিত/i.test(approvedA5.status ?? "") && approvedA5.alertKinds.includes("success"), JSON.stringify(strip(approvedA5)));

    // ---------------------------------------------------------------- 8b. the applicant's e-mail: sent after the answer, once per decision
    for (const [letter, inbox] of Object.entries(inboxes)) {
      const first = await waitForMail(inbox, 120000);
      await sleep(15000);
      const all = await inboxMessages(inbox);
      const raw = all[0] ? await fetch(`${MAILTM}/messages/${all[0].id}/download`, { headers: { Authorization: `Bearer ${inbox.token}` } }).then((r) => r.text()).catch(() => "") : "";
      check("E", `applicant ${letter} received the approval e-mail (it names the QA committee) — sent after the answer — and exactly ONE e-mail although ${letter === filed[1].letter ? "a stale second tab asked for the approval again" : "the Approve button was double-clicked"}`, first.length >= 1 && all.length === 1 && raw.includes(MARK), `${all.length} message(s), names the committee: ${raw.includes(MARK)}`);
    }

    // ---------------------------------------------------------------- 9. the edge cases a form cannot make (kit `seed`)
    if (SEED) {
      const edge = async (key) => open(admin, SEED.submissions[key]);
      const nophoto = await edge("nophoto");
      check("I", "an application with no photo at all shows the placeholder and says 'No photo provided'; the photo route answers 404", nophoto.photoState === "none" && nophoto.photo === null && nophoto.placeholder && /No photo provided|ছবি প্রদান করা হয়নি/.test(nophoto.bodyText) && (await photoFacts(admin, SEED.submissions.nophoto)).status === 404, JSON.stringify(strip(nophoto)));
      for (const key of ["lostphoto", "badpath"]) {
        const r = await edge(key);
        const route = await photoFacts(admin, SEED.submissions[key]);
        check("I", `an application whose photo (${key === "lostphoto" ? "file deleted" : "malformed '../..' path on record"}) cannot be read shows the placeholder with a clear note; the route answers 404`, r.photoState === "missing" && r.photo === null && r.placeholder && /no longer available|এখন পাওয়া যাচ্ছে না/.test(r.bodyText) && route.status === 404, JSON.stringify({ state: r.photoState, route: route.status }));
        await shot(admin, `V-${key}-review`);
        await click(admin, approveSel);
        const refused = await readReview(admin);
        check("C", `approving it is answered with a plain message about the photo (not a 422, not a 500) and changes nothing (${key})`, refused.path.endsWith("/submissions/<id>") && refused.alertKinds.includes("danger") && /photo|ছবি/.test(alertText(refused, "danger")) && /await|পর্যালোচনা|Awaiting/i.test(refused.status ?? "") && refused.historyEntries === 0 && refused.forms.some((f) => /approve$/.test(f.action)), JSON.stringify(strip(refused)));
      }
      await admin.goto(reviewUrl(SEED.submissions.nophoto), { waitUntil: "networkidle2", timeout: 90000 });
      await click(admin, approveSel);
      const noPhotoApproved = await readReview(admin);
      check("A", "an application with no photo at all can be approved (no public copy is needed)", /approved|অনুমোদিত/i.test(noPhotoApproved.status ?? "") && noPhotoApproved.alertKinds.includes("success"), JSON.stringify(strip(noPhotoApproved)));
      for (const key of ["rejected", "unpublished"]) {
        const r = await replay(admin, "approve", SEED.submissions[key]);
        check("B", `approving a ${key} application is answered with an error message naming the state, on the review page (no 422 page)`, r.status === 200 && r.alerts.some((a) => a.kind === "danger") && !r.bare422 && r.finalPath.endsWith("/submissions/<id>"), JSON.stringify(r));
      }
    } else {
      note("no --seed given: the no-photo / lost-photo / malformed-path / rejected / unpublished cases were not exercised in this run");
    }

    // ---------------------------------------------------------------- 10. both languages, desktop and phone, light and dark
    const look = async (name, id, { mobile = false, theme = "light", expect = {} } = {}) => {
      await admin.setViewport(mobile ? { width: 390, height: 844, isMobile: true, hasTouch: true, deviceScaleFactor: 1 } : { width: 1366, height: 1000, deviceScaleFactor: 1 });
      await admin.evaluateOnNewDocument((t) => { try { localStorage.setItem("provatferi-admin-theme", t); } catch { /* ignore */ } }, theme);
      problems.length = 0;
      const r = await admin.goto(reviewUrl(id), { waitUntil: "networkidle2", timeout: 90000 });
      await sleep(400);
      const f = await admin.evaluate(() => {
        const img = document.querySelector("[data-photo-state] img");
        const html = document.documentElement;
        return {
          lang: html.lang, overflow: html.scrollWidth > window.innerWidth + 1, themeAttr: html.getAttribute("data-bs-theme"),
          photoOk: img ? img.complete && img.naturalWidth > 0 : null,
          entities: (document.body.innerText.match(/&(#\d+|#x[0-9a-f]+|amp|quot|lt|gt|apos);/gi) ?? []).length,
          rawKeys: /committee_review\.|admin\.(fields|flash|errors)\./.test(document.body.innerText),
          buttons: [...document.querySelectorAll("form[data-guard-group] button[type=submit]")].map((b) => b.innerText.trim()),
        };
      });
      const fine = r?.status() === 200 && !f.overflow && f.entities === 0 && !f.rawKeys && (expect.photo === undefined || f.photoOk === expect.photo) && problems.length === 0;
      check("N", `${name}: HTTP 200, no sideways scroll, no entity text, no raw translation key${expect.photo ? ", the photo loaded" : ""}, no console error`, fine, JSON.stringify({ status: r?.status(), f, problems: problems.slice(0, 3) }));
      allProblems.push(...problems);
      await shot(admin, name);
      return f;
    };
    // A review page that still has its decision forms: a pre-switch pending application, else the lost-photo seed, else the correction request.
    const withForms = Object.values(preIds).find(Boolean) ?? SEED?.submissions?.lostphoto ?? A4;
    check("X", "no unexpected console or page error before the layout pass", problems.length === 0, problems.slice(0, 5).join(" | "));
    allProblems.push(...problems);
    const languages = originalLang === "bn" ? ["bn", "en"] : ["en", "bn"];
    for (const code of languages) {
      await setLanguage(code);
      for (const [vp, theme] of [["desktop", "light"], ["mobile", "dark"], ["desktop", "dark"], ["mobile", "light"]]) {
        const f = await look(`${code}-${vp}-${theme}-review-with-decisions`, withForms, { mobile: vp === "mobile", theme });
        if (vp === "desktop" && theme === "light") check("N", `the admin language is ${code} (page lang attribute)`, f.lang === code, f.lang);
      }
      await look(`${code}-desktop-light-approved-review`, A1, { expect: { photo: true } });
      await look(`${code}-mobile-dark-approved-review`, A1, { mobile: true, theme: "dark", expect: { photo: true } });
      await look(`${code}-desktop-light-rejected-review`, A3);
      if (SEED) {
        await look(`${code}-desktop-light-lost-photo-review`, SEED.submissions.lostphoto);
        await look(`${code}-mobile-dark-lost-photo-review`, SEED.submissions.lostphoto, { mobile: true, theme: "dark" });
      }
      await admin.setViewport({ width: 1366, height: 1000 });
      // The messages themselves, in this language.
      await admin.goto(reviewUrl(A1), { waitUntil: "networkidle2", timeout: 90000 });
      const again = await replay(admin, "approve", A1);
      const again2 = await replay(admin, "approve", A3);
      const again3 = await replay(admin, "reject", A3, { admin_note: "QA: again" });
      const again4 = await replay(admin, "request-correction", A4, { admin_note: "QA: again" });
      const texts = [again, again2, again3, again4].map((r) => r.alerts.map((a) => a.text).join(" "));
      check("T", `${code}: the four messages (already approved / no longer possible / already rejected / already requested) are in ${code === "bn" ? "Bangla" : "English"}, plain, without a raw key`, texts.every((t) => t.length > 10 && !/committee_review|admin\./.test(t) && (code === "bn" ? isBangla(t) : !isBangla(t))) && again.alerts.some((a) => a.kind === "info") && again2.alerts.some((a) => a.kind === "danger") && again3.alerts.some((a) => a.kind === "info") && again4.alerts.some((a) => a.kind === "info"), JSON.stringify(texts));
      note(`${code} messages: ${texts.join("  ||  ")}`);
    }

    check("X", "no unexpected console or page error in any tab, in either language", allProblems.length === 0 && problems.length === 0, [...allProblems, ...problems].slice(0, 5).join(" | "));
    note(`created accounts to purge with the kit: ${accounts.join(", ")}`);
    note(`QA application ids: ${JSON.stringify(ids)}`);
  } catch (error) {
    fail("V", error);
  } finally {
    // The admin language is put back where it was; the browser is closed without signing the account out.
    try { await setLanguage(originalLang); } catch { /* the browser is closed next */ }
    writeFileSync(join(OUT, "verify-ids.json"), JSON.stringify({ tag: TAG, accounts }, null, 2));
    await browser.close();
    finish();
  }
}

console.error(`phase ${PHASE} is not implemented in this version of the script`);
await browser.close();
process.exit(2);
