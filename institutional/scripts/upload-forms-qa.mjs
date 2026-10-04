// Real-Chrome acceptance run for the four upload forms that no longer submit through a Server Action: committee
// registration, committee correction, membership application, member profile.
//
// Drives every scenario in a real browser against --base and writes a report (--report) with what each scenario is
// EXPECTED to have created. Its companion scripts/upload-forms-qa-verify.mjs compares that report with the server's own
// view (`php deploy/qa/upload-forms-qa.php inspect`) and with the admin screens.
//
//   node scripts/upload-forms-qa.mjs --base http://127.0.0.1:3100 --state qa-state.json --fixtures <submit-fixtures> \
//        --triggers <trigger-fixtures> --shots <dir> --report run.json [--forms registration,correction,membership,profile]
//        [--pace-ms 12000] [--expect-edge]
//
// Scenarios per form (A-F as the owner listed them):
//   A  a normal photo, entered after a first attempt that fails validation: the form keeps every typed value AND the chosen
//      file, puts focus on the first problem, then the corrected submit succeeds   (A and F together)
//   B  the optional file left untouched (registration's photo is required: omitting it is refused, then recovered from)
//   C  a realistic 12 MP phone photo (~3.7 MB)
//   D  a fixture that holds the bytes Cloudflare's rule hunts for (`"$F` / `'$F` inside the first MiB)
//   E  double-submit protection (three synchronous clicks / a real double click / Enter twice), with the response held in flight
//      so the busy state can be read and photographed: exactly ONE request
// Every submit asserts: one POST to the form's own /api route, no `Next-Action` header on any request, no Cloudflare refusal,
// the button disabled + aria-busy + brand loader within a frame of the click, and a clean console / no failed request.
//
// Laravel allows 5-6 of these POSTs a minute per IP (one counter, shared with every visitor), so --pace-ms spaces the actions
// that reach it (use 0 against a local Laravel with the array cache).

import { existsSync } from "node:fs";
import { createHash } from "node:crypto";
import { mkdir, readFile, writeFile } from "node:fs/promises";
import { resolve } from "node:path";
import puppeteer from "puppeteer-core";

const arg = (n, d = null) => {
  const i = process.argv.indexOf(`--${n}`);
  return i === -1 ? d : process.argv[i + 1];
};
const flag = (n) => process.argv.includes(`--${n}`);
const phase = arg("phase", "run");
const base = arg("base", "http://127.0.0.1:3100").replace(/\/+$/, "");
const stateFile = arg("state");
const fixturesDir = arg("fixtures");
const triggersDir = arg("triggers");
const shotsDir = arg("shots");
const reportFile = arg("report");
const paceMs = Number(arg("pace-ms", "0"));
const holdMs = Number(arg("hold-ms", "4200"));
const only = (arg("forms", "registration,correction,membership,profile") ?? "").split(",").map((s) => s.trim());
const expectEdge = flag("expect-edge");

if (!stateFile || !reportFile) {
  console.error("usage: node scripts/upload-forms-qa.mjs --state <qa-state.json> --report <file> [--fixtures <dir> --triggers <dir>] [--phase run|verify] ...");
  process.exit(2);
}
const state = JSON.parse(await readFile(stateFile, "utf8"));
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const sha = async (path) => createHash("sha256").update(await readFile(path)).digest("hex");
const chrome = [process.env.CHROME_PATH, "C:/Program Files/Google/Chrome/Application/chrome.exe", "C:/Program Files (x86)/Google/Chrome/Application/chrome.exe", "/usr/bin/google-chrome", "/usr/bin/chromium"].filter(Boolean).find(existsSync);

// ---------------------------------------------------------------------------------------------------------------
// Fixtures: the realistic photos and the trigger set
// ---------------------------------------------------------------------------------------------------------------
const FIXTURES = fixturesDir && triggersDir
  ? {
      mid: { path: resolve(fixturesDir, "photo-mid.jpg") },
      phone: { path: resolve(fixturesDir, "photo-phone.jpg") },
      tiny: { path: resolve(fixturesDir, "photo-tiny.png") },
      "trigger-deep": { path: resolve(triggersDir, "trigger-deep.jpg") },
      "trigger-dq-jpg": { path: resolve(triggersDir, "trigger-dq.jpg") },
      "trigger-sq-png": { path: resolve(triggersDir, "trigger-sq.png") },
      "trigger-dq-png": { path: resolve(triggersDir, "trigger-dq.png") },
    }
  : {};
for (const f of Object.values(FIXTURES)) {
  f.sha256 = await sha(f.path);
  f.name = f.path.split(/[\\/]/).pop();
}

// ---------------------------------------------------------------------------------------------------------------
// Pacing against Laravel's per-IP limiter
// ---------------------------------------------------------------------------------------------------------------
let lastGate = 0;
async function gate() {
  if (!paceMs) return;
  const wait = lastGate + paceMs - Date.now();
  if (wait > 0) await sleep(wait);
  lastGate = Date.now();
}

// ---------------------------------------------------------------------------------------------------------------
// Browser plumbing
// ---------------------------------------------------------------------------------------------------------------
const FORM = "form.application-form";
const SUBMIT = `${FORM} button[type="submit"]`;

function instrument(page) {
  const rec = { posts: [], nextAction: [], consoleErrors: [], pageErrors: [], failed: [], refused: [] };
  page.on("request", (req) => {
    if (req.headers()["next-action"] !== undefined) rec.nextAction.push(`${req.method()} ${req.url()}`);
    if (req.method() === "POST" && req.url().includes("/api/")) rec.posts.push({ url: req.url(), at: Date.now() });
  });
  page.on("response", async (res) => {
    const req = res.request();
    if (res.status() === 403 && /cloudflare/i.test(res.headers().server ?? "")) rec.refused.push(res.url());
    if (req.method() === "POST" && req.url().includes("/api/")) {
      const post = rec.posts.find((p) => p.url === req.url() && p.status === undefined);
      if (post) {
        post.status = res.status();
        post.server = res.headers().server ?? null;
        post.json = await res.json().catch(() => null);
      }
    }
  });
  page.on("console", (m) => { if (m.type() === "error") rec.consoleErrors.push(m.text().slice(0, 220)); });
  page.on("pageerror", (e) => rec.pageErrors.push(String(e).slice(0, 220)));
  page.on("requestfailed", (r) => {
    const reason = r.failure()?.errorText ?? "";
    if (r.url().includes("_rsc=") && /ERR_ABORTED/.test(reason)) return; // a prefetch the router cancelled: benign
    rec.failed.push(`${r.method()} ${r.url().slice(0, 110)} ${reason}`);
  });
  return rec;
}

async function newPage(browser, { context, viewport } = {}) {
  const ctx = context ?? (await browser.createBrowserContext());
  const page = await ctx.newPage();
  await page.setViewport(viewport ?? { width: 1280, height: 900 });
  const rec = instrument(page);
  const ctl = { holdMs: 0 };
  await page.setRequestInterception(true);
  page.on("request", async (req) => {
    try {
      if (ctl.holdMs && req.method() === "POST" && req.url().includes("/api/")) {
        const ms = ctl.holdMs;
        ctl.holdMs = 0;
        await sleep(ms); // the response is held in flight, so the busy state can be read and photographed
      }
      await req.continue();
    } catch {
      // already handled, or the page is gone
    }
  });
  return { ctx, page, rec, ctl };
}

const hydrated = (page, timeout = 30000) =>
  page.waitForFunction((s) => {
    const f = document.querySelector(s);
    return f && Object.keys(f).some((k) => k.startsWith("__reactProps$") || k.startsWith("__reactFiber$"));
  }, { timeout }, FORM);

/**
 * Opens a form page. The membership page's campaign data is cached by the site for a minute (stale-while-revalidate), so a
 * page served right after the QA season was created — or after an earlier one was removed — can be out of date: it is retried
 * until it shows THIS run's season (`ready`).
 */
async function openForm(page, url, { retries = 0, ready = null } = {}) {
  for (let i = 0; ; i++) {
    await page.goto(i ? `${url}${url.includes("?") ? "&" : "?"}qa=${Date.now()}` : url, { waitUntil: "networkidle0", timeout: 90000 });
    if ((await page.$(FORM)) && (!ready || (await ready(page)))) break;
    if (i >= retries) throw new Error(`no usable ${FORM} on ${url} (title: ${await page.title()})`);
    await sleep(6000);
  }
  await hydrated(page);
}

const fillIn = (page, { fields = {}, checks = [] }) =>
  page.evaluate(({ fields, checks }) => {
    const set = (id, value) => {
      const el = document.getElementById(id);
      if (!el) throw new Error(`no #${id}`);
      const proto = el.tagName === "TEXTAREA" ? HTMLTextAreaElement.prototype : el.tagName === "SELECT" ? HTMLSelectElement.prototype : HTMLInputElement.prototype;
      Object.getOwnPropertyDescriptor(proto, "value").set.call(el, value);
      el.dispatchEvent(new Event("input", { bubbles: true }));
      el.dispatchEvent(new Event("change", { bubbles: true }));
    };
    for (const [id, value] of Object.entries(fields)) set(id, value);
    for (const id of checks) {
      const el = document.getElementById(id);
      if (el && !el.checked) el.click();
    }
  }, { fields, checks });

const snapshotForm = (page) =>
  page.evaluate((s) => {
    const f = document.querySelector(s);
    const out = { values: {}, checked: {}, files: {} };
    if (!f) return out;
    for (const el of f.querySelectorAll("input, select, textarea")) {
      const key = el.name || el.id;
      if (!key || el.type === "hidden") continue;
      if (el.type === "file") out.files[key] = [...el.files].map((x) => `${x.name}:${x.size}`);
      else if (el.type === "checkbox") out.checked[key] = el.checked;
      else out.values[key] = el.value;
    }
    return out;
  }, FORM);

const readBusy = (page) =>
  page.evaluate((s) => {
    const f = document.querySelector(s);
    if (!f) return null;
    const b = f.querySelector('button[type="submit"]');
    const st = f.querySelector(".form-submit-status");
    const active = document.activeElement;
    return {
      disabled: b.disabled, ariaBusy: b.getAttribute("aria-busy"), label: b.innerText.trim(), loader: !!b.querySelector(".brand-loader"),
      formBusy: f.getAttribute("aria-busy"), fieldsetDisabled: !!f.querySelector("fieldset.form-body")?.disabled,
      statusRole: st?.getAttribute("role") ?? null, statusText: st?.innerText.trim() ?? "",
      active: active?.classList?.contains("form-submit-status") ? "status-line" : active?.id || active?.tagName?.toLowerCase() || null,
    };
  }, FORM);

const installProbes = (page) =>
  page.evaluate((s) => {
    const pf = (window.__pf = { clicks: 0, submits: 0, t0: null, busyAt: null, paintedAt: null, snapshot: null });
    const form = document.querySelector(s);
    const btn = form.querySelector('button[type="submit"]');
    form.addEventListener("submit", () => { pf.submits++; if (pf.t0 === null) pf.t0 = performance.now(); }, true);
    btn.addEventListener("click", () => { pf.clicks++; if (pf.t0 === null) pf.t0 = performance.now(); }, true);
    new MutationObserver(() => {
      if (pf.busyAt === null && btn.disabled && btn.getAttribute("aria-busy") === "true") {
        pf.busyAt = performance.now();
        requestAnimationFrame(() => requestAnimationFrame(() => {
          pf.paintedAt = performance.now();
          pf.snapshot = { loader: !!btn.querySelector(".brand-loader"), label: btn.innerText.trim(), fieldsetDisabled: !!form.querySelector("fieldset.form-body")?.disabled, formBusy: form.getAttribute("aria-busy") };
        }));
      }
    }).observe(btn, { attributes: true, childList: true, subtree: true });
  }, FORM);

async function submitVia(page, how) {
  if (how === "sync3") await page.evaluate((s) => { const b = document.querySelector(s); b.click(); b.click(); b.click(); }, SUBMIT);
  else if (how === "dblclick") await page.click(SUBMIT, { count: 2, delay: 0 });
  else if (how === "enter2") {
    await page.focus(`${FORM} input[type="text"]`);
    await Promise.all([page.keyboard.press("Enter"), page.keyboard.press("Enter")]);
  } else await page.click(SUBMIT);
}

async function shot(page, name, { scroll = "button" } = {}) {
  if (!shotsDir) return null;
  await mkdir(shotsDir, { recursive: true });
  if (scroll === "button") await page.evaluate((s) => document.querySelector(s)?.scrollIntoView({ block: "center", behavior: "instant" }), SUBMIT).catch(() => {});
  else if (scroll === "top") await page.evaluate(() => window.scrollTo({ top: 0, behavior: "instant" })).catch(() => {});
  else if (scroll === "error") await page.evaluate(() => (document.querySelector("form.application-form .form-field-error") ?? document.querySelector("form.application-form button[type=submit]"))?.scrollIntoView({ block: "center", behavior: "instant" })).catch(() => {});
  await sleep(120);
  const file = resolve(shotsDir, `${name}.png`);
  await page.screenshot({ path: file }).catch(() => {});
  return file;
}

const OUTCOME = () => {
  if (document.querySelector('.callout[role="status"][tabindex="-1"]')) return "success";
  const btn = document.querySelector('form.application-form button[type="submit"]');
  if (btn && !btn.disabled && document.querySelector("form.application-form .form-field-error")) return "error";
  return null;
};

/**
 * One submit attempt, observed from the browser: what the visitor sees while it is in flight, what it sends, what comes back.
 */
async function attempt(page, rec, ctl, { how = "click", hold = 0, name, bucket = true, extraWhileBusy = false }) {
  await installProbes(page);
  const postsBefore = rec.posts.length;
  const before = await snapshotForm(page);
  ctl.holdMs = hold;
  if (bucket) await gate();
  await submitVia(page, how);

  await sleep(450);
  const busyEarly = await readBusy(page);
  let busyLate = null;
  if (hold) {
    if (extraWhileBusy) {
      // While it works: more Enter presses and another double click must change nothing.
      await page.keyboard.press("Enter").catch(() => {});
      await page.keyboard.press("Enter").catch(() => {});
      await page.click(SUBMIT, { count: 2, delay: 0 }).catch(() => {});
    }
    await sleep(Math.max(0, Math.min(2900, hold - 1000) - 450));
    busyLate = await readBusy(page);
    await shot(page, `${name}-busy`);
  }
  const outcome = await page.waitForFunction(OUTCOME, { timeout: 120000, polling: 60 }).then((h) => h.jsonValue()).catch(() => "timeout");
  await sleep(500); // the second request of a failed lock would arrive by now
  const probe = await page.evaluate(() => window.__pf ?? null).catch(() => null);
  const after = await snapshotForm(page);
  const afterBusy = await readBusy(page);
  const focus = await page.evaluate(() => {
    const a = document.activeElement;
    return { id: a?.id || null, tag: a?.tagName?.toLowerCase() ?? null, role: a?.getAttribute?.("role") ?? null };
  });
  const errors = await page.evaluate(() => [...document.querySelectorAll("form.application-form .form-field-error")].map((e) => ({ text: e.innerText.trim(), field: e.closest(".form-field")?.querySelector("input,select,textarea")?.id ?? null })));
  const successText = outcome === "success" ? await page.evaluate(() => document.querySelector('.callout[role="status"][tabindex="-1"]')?.innerText.trim() ?? null) : null;
  await shot(page, `${name}-${outcome}`, { scroll: outcome === "success" ? "none" : "error" });

  const posts = rec.posts.slice(postsBefore);
  return {
    how, hold, outcome, posts: posts.length, status: posts[0]?.status ?? null, answer: posts[0]?.json ?? null,
    clicks: probe?.clicks ?? null, submitEvents: probe?.submits ?? null,
    feedbackMs: probe?.busyAt !== null && probe?.t0 !== null ? Math.round(probe.busyAt - probe.t0) : null,
    paintedMs: probe?.paintedAt !== null && probe?.t0 !== null ? Math.round(probe.paintedAt - probe.t0) : null,
    busyProbe: probe?.snapshot ?? null, busyEarly, busyLate, afterBusy, before, after, focus, errors, successText,
  };
}

// ---------------------------------------------------------------------------------------------------------------
// Checks
// ---------------------------------------------------------------------------------------------------------------
const results = [];
let current = null;
function check(name, ok, detail = "") {
  current.checks.push({ name, ok: Boolean(ok), detail: ok ? "" : String(detail) });
}
function beginScenario(form, id, extra = {}) {
  current = { form, id, ...extra, checks: [], startedAt: new Date().toISOString() };
  results.push(current);
  return current;
}
function endScenario(rec) {
  const bad = current.checks.filter((c) => !c.ok);
  check("no Server Action request (Next-Action header) from the page", rec.nextAction.length === 0, rec.nextAction.join("; "));
  check("no Cloudflare refusal", rec.refused.length === 0, rec.refused.join("; "));
  check("no console error / page error", rec.consoleErrors.length === 0 && rec.pageErrors.length === 0, [...rec.consoleErrors, ...rec.pageErrors].join(" | "));
  check("no failed request", rec.failed.length === 0, rec.failed.join(" | "));
  void bad;
  current.pass = current.checks.every((c) => c.ok);
  console.log(`${current.pass ? "PASS" : "FAIL"}  ${current.form.padEnd(12)} ${current.id.padEnd(4)} ${current.title ?? ""}`);
  for (const c of current.checks.filter((c) => !c.ok)) console.log(`        x ${c.name}${c.detail ? ` — ${c.detail}` : ""}`);
}

/** The checks every SUCCESSFUL submit must pass. */
function expectSuccess(r, { text, hold = false, how = "click" }) {
  check("exactly ONE request to the form's route", r.posts === 1, `${r.posts} posts`);
  check("route answered 200 with status success", r.status === 200 && r.answer?.status === "success", JSON.stringify({ status: r.status, answer: r.answer }));
  check("confirmation shown", r.outcome === "success" && text.test(r.successText ?? ""), `${r.outcome}: ${r.successText}`);
  check("button disabled + aria-busy + brand loader + parked fields within a frame of the click", r.busyProbe && r.busyProbe.loader && r.busyProbe.fieldsetDisabled && r.busyProbe.formBusy === "true" && r.feedbackMs !== null && r.feedbackMs < 250, JSON.stringify({ probe: r.busyProbe, ms: r.feedbackMs }));
  if (hold) {
    // Only readable when the response is held in flight: a fast backend answers before any sample could be taken.
    check("while busy: disabled, aria-busy, loader, a localized label, one live region", r.busyEarly && r.busyEarly.disabled && r.busyEarly.ariaBusy === "true" && r.busyEarly.loader && r.busyEarly.label.length > 0 && r.busyEarly.statusRole === "status", JSON.stringify(r.busyEarly));
    check("after ~2 s the helper sentence is shown", r.busyLate && r.busyLate.statusText.length > 10, JSON.stringify(r.busyLate));
    // A real double click's second press lands on the button that has just been disabled, and the browser moves focus to the
    // nearest focusable ancestor (<main>) — that is the browser, not the form; the live region announces regardless.
    const focusOk = how === "dblclick" ? ["status-line", "main-content"].includes(r.busyEarly?.active) : r.busyEarly?.active === "status-line";
    check("focus moved to the status line while busy (a real double click may leave it on <main>)", focusOk, String(r.busyEarly?.active));
  }
}

/** The checks a REFUSED (validation) attempt must pass: nothing lost, problem shown, control handed back. */
function expectRecovery(r, { firstInvalidId, mustKeepFile = true }) {
  check("exactly ONE request, answered with a validation state", r.posts === 1 && r.status === 200 && r.answer?.status === "validation", JSON.stringify({ posts: r.posts, status: r.status, answer: r.answer?.status }));
  check("the problem is shown next to its field", r.errors.some((e) => e.field === firstInvalidId), JSON.stringify(r.errors));
  check("focus is on the first problem", r.focus.id === firstInvalidId, JSON.stringify(r.focus));
  check("button usable again, not busy", r.afterBusy && !r.afterBusy.disabled && r.afterBusy.ariaBusy === null && !r.afterBusy.loader && r.afterBusy.formBusy === null && !r.afterBusy.fieldsetDisabled, JSON.stringify(r.afterBusy));
  check("every typed value, ticked box and chosen file is still there", JSON.stringify(r.before.values) === JSON.stringify(r.after.values) && JSON.stringify(r.before.checked) === JSON.stringify(r.after.checked) && (!mustKeepFile || JSON.stringify(r.before.files) === JSON.stringify(r.after.files)), JSON.stringify({ before: r.before, after: r.after }));
  if (mustKeepFile) check("the chosen file survived the error", Object.values(r.after.files).some((f) => f.length === 1), JSON.stringify(r.after.files));
}

// ---------------------------------------------------------------------------------------------------------------
// The four forms
// ---------------------------------------------------------------------------------------------------------------
const email = (tag) => `khondokermoin2k23+${state.suffix}-${tag}@gmail.com`;
const marker = (form, id) => `QA-${form}${id}-${state.suffix}`;

const FORMS = {
  registration: {
    key: "R",
    bucketOnLoad: true,
    url: () => `${base}/committee/register/${state.registrationToken}`,
    text: /আপনার তথ্য সফলভাবে জমা হয়েছে/,
    firstInvalid: "email",
    kind: "registration",
    values: (m) => ({
      fields: { committee_position_id: String(state.positions[1].id), full_name: m, name_en: `${m} EN`, email: email("r"), phone: "01712345678", bio: "QA bio — disposable.", provatferi_comment: `QA comment ${m}`, facebook_url: "https://www.facebook.com/qa.upload.test" },
      checks: ["publishing_consent", "accuracy_declaration"],
    }),
    invalid: { email: "not-an-email", phone: "" },
  },
  correction: {
    key: "C",
    bucketOnLoad: true,
    url: (sc) => `${base}/committee/register/correct/${state.corrections[sc.token].token}`,
    text: /আপনার সংশোধিত তথ্য জমা হয়েছে/,
    firstInvalid: "email",
    kind: "correction",
    values: (m, sc) => ({ fields: { full_name: m, bio: `QA corrected bio ${m}`, email: email(`c${sc.token.toLowerCase()}`), phone: "01711111111" }, checks: [] }),
    invalid: { email: "bad-email" },
  },
  membership: {
    key: "M",
    bucketOnLoad: false,
    url: (sc) => `${base}${sc.locale === "en" ? "/en" : ""}/membership`,
    text: (sc) => (sc.locale === "en" ? /Your application has been submitted successfully/ : /আপনার আবেদন সফলভাবে জমা হয়েছে/),
    firstInvalid: "applicant_email",
    kind: "membership",
    values: (m) => ({ fields: { membership_type_id: String(state.membership.typeId), applicant_name: m, applicant_email: email("a"), applicant_phone: "01712345678" }, checks: [] }),
    invalid: { applicant_email: "not-an-email" },
  },
  profile: {
    key: "P",
    bucketOnLoad: false,
    bucket: false, // an authenticated route with no IP throttle
    url: () => `${base}/member/dashboard/profile`,
    text: /সংরক্ষণ করা হয়েছে/,
    firstInvalid: "facebook_url",
    kind: "profile",
    values: (m) => ({ fields: { profession: m, bio: `QA bio ${m}`, facebook_url: "https://www.facebook.com/qa.upload.test" }, checks: [] }),
    invalid: { facebook_url: "this is not a url" },
  },
};

const SCENARIOS = {
  registration: [
    { id: "A", title: "normal photo, after a failed first attempt", fixture: "mid", invalidFirst: true },
    { id: "B", title: "required photo omitted: refused, then recovered with the smallest valid image", fixture: "tiny", omitFirst: true },
    { id: "C", title: "realistic 12 MP phone photo", fixture: "phone" },
    { id: "D", title: "trigger fixture: `\"$F` ~1,000,619 bytes in (inside the rule's 1 MiB)", fixture: "trigger-deep" },
    { id: "E", title: "Enter pressed twice at once, response held in flight", fixture: "tiny", how: "enter2", hold: holdMs },
  ],
  correction: [
    { id: "A", title: "normal photo, after a failed first attempt", fixture: "mid", token: "A", invalidFirst: true },
    { id: "B", title: "photo left untouched: the photo on file must stay", fixture: null, token: "B" },
    { id: "C", title: "realistic 12 MP phone photo", fixture: "phone", token: "C" },
    { id: "D", title: "trigger fixture: `\"$F` at byte 551", fixture: "trigger-dq-jpg", token: "D" },
    { id: "E", title: "three synchronous clicks, response held in flight", fixture: "tiny", token: "E", how: "sync3", hold: holdMs },
  ],
  membership: [
    { id: "A", title: "normal photo, after a failed first attempt", fixture: "mid", invalidFirst: true },
    { id: "B", title: "optional photo omitted", fixture: null },
    { id: "C", title: "realistic 12 MP phone photo", fixture: "phone" },
    { id: "D", title: "trigger fixture: `'$F` PNG at byte 102", fixture: "trigger-sq-png" },
    { id: "E", title: "real double-click on /en, response held in flight, 390px wide", fixture: "tiny", how: "dblclick", hold: holdMs, locale: "en", viewport: { width: 390, height: 844, deviceScaleFactor: 2, isMobile: true, hasTouch: true } },
  ],
  profile: [
    { id: "A", title: "normal photo, after a failed first attempt", fixture: "mid", invalidFirst: true },
    { id: "B", title: "optional photo omitted", fixture: null },
    { id: "C", title: "realistic 12 MP phone photo", fixture: "phone" },
    { id: "D", title: "trigger fixture: `\"$F` PNG at byte 102", fixture: "trigger-dq-png" },
    { id: "E", title: "three synchronous clicks, response held in flight", fixture: "tiny", how: "sync3", hold: holdMs },
    { id: "U", title: "session gone while the page is open: sent to the login page, nothing created", fixture: "tiny", noSession: true },
  ],
};

async function memberLogin(page) {
  await page.goto(`${base}/member/login`, { waitUntil: "networkidle0", timeout: 90000 });
  await hydrated(page);
  await page.type("#email", state.member.email, { delay: 5 });
  await page.type("#password", state.member.password, { delay: 5 });
  await Promise.all([page.waitForFunction(() => location.pathname.startsWith("/member/dashboard"), { timeout: 60000 }), page.click(`${FORM} button[type="submit"]`)]);
}

async function runScenario(browser, formName, sc, memberCtx) {
  const spec = FORMS[formName];
  const text = typeof spec.text === "function" ? spec.text(sc) : spec.text;
  const m = marker(spec.key, sc.id);
  const scenario = beginScenario(formName, sc.id, { title: sc.title, marker: m, fixture: sc.fixture, how: sc.how ?? "click", hold: sc.hold ?? 0, locale: sc.locale ?? "bn", token: sc.token ?? null });
  const { ctx, page, rec, ctl } = await newPage(browser, { context: memberCtx, viewport: sc.viewport });
  const name = `${formName}-${sc.id}${sc.locale === "en" ? "-en" : ""}${sc.viewport ? "-mobile" : ""}`;
  try {
    if (spec.bucketOnLoad) await gate();
    await openForm(page, spec.url(sc), {
      retries: formName === "membership" ? 24 : 0,
      ready: formName === "membership" ? async (pg) => Boolean(await pg.$(`#membership_type_id option[value="${state.membership.typeId}"]`)) : null,
    });
    const fill = spec.values(m, sc);
    await fillIn(page, fill);
    const file = sc.fixture ? FIXTURES[sc.fixture] : null;
    if (file) await (await page.$("#photo")).uploadFile(file.path);
    scenario.fixtureName = file?.name ?? null;
    scenario.fixtureSha256 = file?.sha256 ?? null;
    const bucket = spec.bucket !== false;

    if (sc.noSession) {
      // The session ends while the page is open: the next save must land on the login page, creating nothing.
      await page.deleteCookie(...(await page.cookies()).filter((c) => c.name === "member_session"));
      await installProbes(page);
      await page.click(SUBMIT);
      await page.waitForFunction(() => location.pathname.includes("/member/login"), { timeout: 30000 }).catch(() => {});
      check("the route answered 401 'unauthenticated' (nothing reached Laravel)", rec.posts.length === 1 && rec.posts[0].status === 401 && rec.posts[0].json?.status === "unauthenticated", JSON.stringify(rec.posts));
      check("the visitor is sent to the member login page", page.url().includes("/member/login"), page.url());
      rec.consoleErrors = rec.consoleErrors.filter((e) => !/401/.test(e)); // the browser logs the 401 itself
      scenario.posts = rec.posts.length;
    } else {
      if (sc.invalidFirst || sc.omitFirst) {
        const good = await snapshotForm(page);
        if (sc.invalidFirst) await fillIn(page, { fields: spec.invalid });
        if (sc.omitFirst) await page.evaluate(() => { const i = document.getElementById("photo"); const dt = new DataTransfer(); i.files = dt.files; });
        const r1 = await attempt(page, rec, ctl, { name: `${name}-attempt1`, bucket });
        scenario.firstAttempt = { outcome: r1.outcome, posts: r1.posts, errors: r1.errors, focus: r1.focus };
        if (sc.omitFirst) {
          check("exactly ONE request, answered with a validation state", r1.posts === 1 && r1.answer?.status === "validation", JSON.stringify({ posts: r1.posts, answer: r1.answer?.status }));
          check("the missing photo is reported next to the photo field", r1.errors.some((e) => e.field === "photo"), JSON.stringify(r1.errors));
          check("every typed value is still there", JSON.stringify(r1.before.values) === JSON.stringify(r1.after.values), JSON.stringify({ b: r1.before.values, a: r1.after.values }));
          check("button usable again", r1.afterBusy && !r1.afterBusy.disabled && r1.afterBusy.ariaBusy === null, JSON.stringify(r1.afterBusy));
          await (await page.$("#photo")).uploadFile(file.path);
        } else {
          expectRecovery(r1, { firstInvalidId: spec.firstInvalid });
          // Recover: put the valid value back (everything else must already be there), keep the same chosen file.
          await fillIn(page, { fields: Object.fromEntries(Object.keys(spec.invalid).map((id) => [id, fill.fields[id]])) });
          const kept = await snapshotForm(page);
          check("after correcting one field the rest of the form is exactly as it was", JSON.stringify(Object.fromEntries(Object.entries(kept.values).filter(([k]) => !(k in spec.invalid)))) === JSON.stringify(Object.fromEntries(Object.entries(good.values).filter(([k]) => !(k in spec.invalid)))), JSON.stringify({ good: good.values, kept: kept.values }));
        }
      }
      const r = await attempt(page, rec, ctl, { name, how: sc.how ?? "click", hold: sc.hold ?? 0, bucket, extraWhileBusy: Boolean(sc.hold) });
      expectSuccess(r, { text, hold: Boolean(sc.hold), how: sc.how ?? "click" });
      scenario.attempt = { posts: r.posts, status: r.status, feedbackMs: r.feedbackMs, paintedMs: r.paintedMs, clicks: r.clicks, submitEvents: r.submitEvents, busyEarlyLabel: r.busyEarly?.label ?? null, helper: r.busyLate?.statusText ?? null, successText: r.successText };
      if (sc.hold) {
        const expectedLabel = sc.locale === "en" ? /Submitting application/ : /জমা হচ্ছে|সংরক্ষণ করা হচ্ছে/;
        check("the busy label is in the page's language", expectedLabel.test(r.busyEarly?.label ?? ""), String(r.busyEarly?.label));
        if (sc.how === "sync3") check("three synchronous clicks produced ONE submit event's worth of work (the lock held)", r.posts === 1, `${r.posts} posts, ${r.submitEvents} submit events`);
      }
      if (formName === "membership") scenario.applicationNo = (r.successText ?? "").match(/[A-Z]{1,6}[-–][\w-]+/)?.[0] ?? null;
      if (formName === "profile") {
        const noteAfterPhoto = await page.evaluate(() => document.getElementById("photo")?.files?.length ?? null);
        check("after a saved profile the photo picker is emptied (saving again must not resend it)", noteAfterPhoto === 0, String(noteAfterPhoto));
        if (sc.id === "E") {
          // The member's own screen: a fresh load shows the revision awaiting review, with what was just saved.
          await page.reload({ waitUntil: "networkidle0", timeout: 90000 });
          await hydrated(page);
          const mine = await page.evaluate(() => ({ profession: document.getElementById("profession")?.value ?? null, pending: document.body.innerText.includes("পর্যালোচনার অপেক্ষায়") }));
          check("the member's screen shows the pending revision (callout) with the values just saved", mine.pending && mine.profession === m, JSON.stringify(mine));
          await shot(page, `${name}-member-screen`, { scroll: "top" });
        }
      }
    }
  } catch (error) {
    check("scenario ran to completion", false, error?.stack?.split("\n").slice(0, 3).join(" | ") ?? String(error));
    await shot(page, `${name}-harness-error`, { scroll: "none" }).catch(() => {});
  } finally {
    endScenario(rec);
    if (!memberCtx) await ctx.close().catch(() => {});
    else await page.close().catch(() => {});
  }
}

/** On production only: the SAME fixture, sent the old way from a page on the origin, is refused by the edge — so the success above is not luck. */
async function edgeControl(browser) {
  beginScenario("edge", "X", { title: "negative control: the old Server Action shape, from a page on the origin" });
  const { ctx, page, rec } = await newPage(browser);
  try {
    await page.goto(`${base}/membership`, { waitUntil: "networkidle0", timeout: 90000 });
    for (const key of ["trigger-deep", "trigger-dq-jpg", "trigger-sq-png"]) {
      const bytes = (await readFile(FIXTURES[key].path)).toString("base64");
      const res = await page.evaluate(async (b64, fname) => {
        const form = new FormData();
        form.append("0", '[{"status":"idle"},"$K1"]');
        form.append("1_photo", new Blob([Uint8Array.from(atob(b64), (c) => c.charCodeAt(0))], { type: "image/jpeg" }), fname);
        const r = await fetch("/membership", { method: "POST", headers: { "Next-Action": "7f00000000000000000000000000000000000000", Accept: "text/x-component" }, body: form });
        return { status: r.status, server: r.headers.get("server") };
      }, bytes, FIXTURES[key].name);
      check(`${FIXTURES[key].name} sent as a Server Action request is refused by Cloudflare (403)`, res.status === 403 && /cloudflare/i.test(res.server ?? ""), JSON.stringify(res));
    }
    rec.refused.length = 0; // those refusals are the point of this scenario
    rec.consoleErrors.length = 0;
    rec.nextAction.length = 0;
  } catch (error) {
    check("scenario ran to completion", false, String(error));
  } finally {
    endScenario(rec);
    await ctx.close().catch(() => {});
  }
}

// ---------------------------------------------------------------------------------------------------------------
if (phase === "run") {
  if (!chrome) throw new Error("No Chrome found; set CHROME_PATH");
  if (!Object.keys(FIXTURES).length) throw new Error("--fixtures and --triggers are required for --phase run");
  const browser = await puppeteer.launch({ executablePath: chrome, headless: true, args: ["--no-sandbox", "--disable-dev-shm-usage"] });
  const startedAt = Date.now();
  try {
    for (const formName of only) {
      let memberCtx = null;
      if (formName === "profile") {
        memberCtx = await browser.createBrowserContext();
        const loginPage = await memberCtx.newPage();
        await loginPage.setViewport({ width: 1280, height: 900 });
        await gate();
        await memberLogin(loginPage);
        await loginPage.close();
      }
      for (const sc of SCENARIOS[formName]) await runScenario(browser, formName, sc, memberCtx);
      if (memberCtx) await memberCtx.close().catch(() => {});
    }
    if (expectEdge) await edgeControl(browser);
  } finally {
    await browser.close();
  }

  const failed = results.filter((r) => !r.pass);
  const checks = results.reduce((n, r) => n + r.checks.length, 0);
  console.log(`\n${results.length} scenarios, ${checks} checks, ${failed.length} scenario(s) failed — ${Math.round((Date.now() - startedAt) / 1000)} s`);
  await mkdir(resolve(reportFile, ".."), { recursive: true });
  await writeFile(reportFile, JSON.stringify({ suffix: state.suffix, base, at: new Date().toISOString(), results }, null, 2));
  process.exit(failed.length ? 1 : 0);
}

console.error(`unknown --phase ${phase} (this script has the run phase; verification is scripts/upload-forms-qa-verify.mjs)`);
process.exit(2);
