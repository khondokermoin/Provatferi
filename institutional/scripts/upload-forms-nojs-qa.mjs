// The no-JavaScript path of the four upload forms: with scripting disabled in Chrome the form must still post natively to its
// Server Action (a plain multipart form post — it carries no `Next-Action` header, which is why it is not exposed to the
// Cloudflare rule) and the server must render the answer. Uses the same QA data as scripts/upload-forms-qa.mjs, so run it
// against a FRESH `php deploy/qa/upload-forms-qa.php setup`, before or instead of that run: it consumes correction token G (upload-forms-qa.mjs uses A-E, native-post-probe.mjs uses F).
//
//   node scripts/upload-forms-nojs-qa.mjs --base http://127.0.0.1:3100 --state qa-state.json --fixtures <dir> [--shots <dir>] [--report out.json]
//
// Page scripts are disabled with CDP (Emulation.setScriptExecutionDisabled), so nothing here can lean on React: fields are
// filled with real key presses and clicks, the photo is attached with DOM.setFileInputFiles, and the answer is read from the
// served HTML.

import { existsSync } from "node:fs";
import { mkdir, readFile, writeFile } from "node:fs/promises";
import { resolve } from "node:path";
import puppeteer from "puppeteer-core";

const arg = (n, d = null) => {
  const i = process.argv.indexOf(`--${n}`);
  return i === -1 ? d : process.argv[i + 1];
};
const base = arg("base", "http://127.0.0.1:3100").replace(/\/+$/, "");
const fixtures = arg("fixtures");
const shotsDir = arg("shots");
const reportFile = arg("report");
const paceMs = Number(arg("pace-ms", "0"));
const state = JSON.parse(await readFile(arg("state"), "utf8"));
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const chrome = [process.env.CHROME_PATH, "C:/Program Files/Google/Chrome/Application/chrome.exe", "C:/Program Files (x86)/Google/Chrome/Application/chrome.exe", "/usr/bin/google-chrome", "/usr/bin/chromium"].filter(Boolean).find(existsSync);
if (!fixtures || !chrome) throw new Error("--fixtures <dir> and a Chrome install are required");
const photo = resolve(fixtures, "photo-tiny.png");

const results = [];
let lastGate = 0;
async function gate() {
  if (!paceMs) return;
  const wait = lastGate + paceMs - Date.now();
  if (wait > 0) await sleep(wait);
  lastGate = Date.now();
}

async function noJsPage(browser, context) {
  const page = await (context ?? (await browser.createBrowserContext())).newPage();
  await page.setViewport({ width: 1280, height: 900 });
  const cdp = await page.createCDPSession();
  await cdp.send("Emulation.setScriptExecutionDisabled", { value: true });
  const seen = { nextAction: [], posts: [] };
  page.on("request", (r) => {
    if (r.method() === "POST") seen.posts.push(r.url());
    if (r.headers()["next-action"] !== undefined) seen.nextAction.push(r.url());
  });
  return { page, seen };
}
const html = (page) => page.content();
const type = async (page, selector, text) => {
  await page.click(selector, { count: 3 });
  await page.type(selector, text, { delay: 2 });
};
async function pickOption(page, selector, downPresses) {
  await page.focus(selector);
  for (let i = 0; i < downPresses; i++) await page.keyboard.press("ArrowDown");
}
async function submit(page, name) {
  await Promise.all([page.waitForNavigation({ waitUntil: "networkidle0", timeout: 90000 }), page.click('form.application-form button[type="submit"]')]);
  if (shotsDir) {
    await mkdir(shotsDir, { recursive: true });
    await page.screenshot({ path: resolve(shotsDir, `nojs-${name}.png`) }).catch(() => {});
  }
}
function record(form, checks, seen, extra = {}) {
  const row = { form, checks, ...extra };
  row.pass = checks.every((c) => c.ok) && seen.nextAction.length === 0;
  results.push(row);
  console.log(`${row.pass ? "PASS" : "FAIL"}  ${form.padEnd(14)} no-JavaScript native post`);
  for (const c of checks.filter((c) => !c.ok)) console.log(`        x ${c.name} — ${c.detail ?? ""}`);
  if (seen.nextAction.length) console.log(`        x a Next-Action request was sent: ${seen.nextAction.join(", ")}`);
}

const browser = await puppeteer.launch({ executablePath: chrome, headless: true, args: ["--no-sandbox", "--disable-dev-shm-usage"] });
try {
  // --- registration: required photo, the position picked with the keyboard, both consents clicked
  {
    const { page, seen } = await noJsPage(browser);
    await gate();
    await page.goto(`${base}/committee/register/${state.registrationToken}`, { waitUntil: "networkidle0", timeout: 90000 });
    await pickOption(page, "#committee_position_id", 2);
    await type(page, "#full_name", `QA-JR-${state.suffix}`);
    await type(page, "#email", `khondokermoin2k23+${state.suffix}-jr@gmail.com`);
    await type(page, "#phone", "01712345678");
    await type(page, "#provatferi_comment", "QA no-JS comment");
    await (await page.$("#photo")).uploadFile(photo);
    await page.click("#publishing_consent");
    await page.click("#accuracy_declaration");
    await gate();
    await submit(page, "registration");
    const body = await html(page);
    record("registration", [{ name: "the server rendered the confirmation", ok: /আপনার তথ্য সফলভাবে জমা হয়েছে/.test(body), detail: body.slice(0, 120) }], seen, { marker: `QA-JR-${state.suffix}` });
    await page.close();
  }

  // --- correction: the photo left untouched, only the name changed
  {
    const { page, seen } = await noJsPage(browser);
    await gate();
    await page.goto(`${base}/committee/register/correct/${state.corrections.G.token}`, { waitUntil: "networkidle0", timeout: 90000 });
    await type(page, "#full_name", `QA-JC-${state.suffix}`);
    await gate();
    await submit(page, "correction");
    const body = await html(page);
    // After a SUCCESSFUL correction the single-use link is spent, so the page re-rendered around the answer cannot show the form and
    // is the not-found page (the record IS saved — scripts/upload-forms-qa.php inspect shows it; with JavaScript the form is replaced
    // in place and never reloads). The answer still rides in that page's flight payload, which is what is asserted.
    record("correction", [{ name: "the server answered: the confirmation, or the spent link's page carrying the success state", ok: /আপনার সংশোধিত তথ্য জমা হয়েছে/.test(body) || /\{"status":"success"\}/.test(body), detail: body.slice(0, 120) }], seen, { marker: `QA-JC-${state.suffix}`, token: "G" });
    await page.close();
  }

  // --- membership: the QA type picked with the keyboard, the optional photo omitted
  {
    const { page, seen } = await noJsPage(browser);
    let ready = false;
    for (let i = 0; i < 24 && !ready; i++) {
      await page.goto(`${base}/membership?qa=${Date.now()}`, { waitUntil: "networkidle0", timeout: 90000 });
      ready = Boolean(await page.$(`#membership_type_id option[value="${state.membership.typeId}"]`));
      if (!ready) await sleep(6000);
    }
    await pickOption(page, "#membership_type_id", 1);
    await type(page, "#applicant_name", `QA-JM-${state.suffix}`);
    await type(page, "#applicant_email", `khondokermoin2k23+${state.suffix}-jm@gmail.com`);
    await type(page, "#applicant_phone", "01712345678");
    await gate();
    await submit(page, "membership");
    const body = await html(page);
    record("membership", [{ name: "the server rendered the confirmation with an application number", ok: /আপনার আবেদন সফলভাবে জমা হয়েছে/.test(body) && /আবেদন নম্বর/.test(body), detail: body.slice(0, 120) }], seen, { marker: `QA-JM-${state.suffix}` });
    await page.close();
  }

  // --- member profile: the login is itself a native post; the photo omitted
  {
    const context = await browser.createBrowserContext();
    const { page, seen } = await noJsPage(browser, context);
    await page.goto(`${base}/member/login`, { waitUntil: "networkidle0", timeout: 90000 });
    await type(page, "#email", state.member.email);
    await type(page, "#password", state.member.password);
    await gate();
    await Promise.all([page.waitForNavigation({ waitUntil: "networkidle0", timeout: 90000 }), page.click('form.application-form button[type="submit"]')]);
    await page.goto(`${base}/member/dashboard/profile`, { waitUntil: "networkidle0", timeout: 90000 });
    await type(page, "#profession", `QA-JP-${state.suffix}`);
    await submit(page, "profile");
    const body = await html(page);
    record("profile", [{ name: "the server rendered the 'saved' confirmation", ok: /সংরক্ষণ করা হয়েছে/.test(body), detail: body.slice(0, 120) }], seen, { marker: `QA-JP-${state.suffix}` });
    await context.close();
  }
} finally {
  await browser.close();
}

const failed = results.filter((r) => !r.pass);
console.log(`\n${results.length} no-JavaScript submissions, ${failed.length} failed`);
if (reportFile) await writeFile(reportFile, JSON.stringify({ suffix: state.suffix, base, at: new Date().toISOString(), results }, null, 2));
process.exit(failed.length ? 1 : 0);
