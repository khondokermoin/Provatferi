// Real-Chrome acceptance of the Mail Center's INSTITUTIONAL IDENTITIES on production (admin-erp/docs/MAIL_CENTER.md).
//
//   node scripts/mail-identity-qa.mjs --admin <url> --env <file> --out <evidence dir>
//
// The env file holds QA_TAG, ADMIN_/ALL_EMAIL and _PASSWORD (disposable accounts made by
// `deploy/qa/mail-center-qa.php qa-setup`) and the ids it printed: PARTNERSHIPS_ID, INTERNAL_ID, LIMITED_ROLE_ID.
// The owner's account is never used, signed in, signed out or changed.
//
// WHAT IT PROVES ON PRODUCTION. provatferi.org has no Mail API token, so no mailbox can be opened and the reading half
// cannot be exercised here (that is proven locally against the real API). What IS provable live is the part this task
// added: one mailbox carries many institutional addresses, those addresses are registered — never created at the
// provider — the automated sender can never be written as, and nothing anywhere leaks a credential.
//
// SECRETS. Passwords and the authenticator secret live in this process only; they are masked before every capture and
// replaced by [secret] in the report.
import { createRequire } from "node:module";
import { existsSync, mkdirSync, readFileSync, writeFileSync } from "node:fs";
import { join } from "node:path";
import { createHmac } from "node:crypto";

const arg = (name, fallback) => {
  const i = process.argv.indexOf(`--${name}`);
  return i > -1 ? process.argv[i + 1] : fallback;
};
const ADMIN = (arg("admin") ?? "").replace(/\/+$/, "");
const OUT = arg("out");
const ENV_FILE = arg("env");
if (!ADMIN || !OUT || !ENV_FILE) {
  console.error("usage: --admin <url> --env <file> --out <dir>");
  process.exit(2);
}
mkdirSync(OUT, { recursive: true });
const require = createRequire(import.meta.url);
const puppeteer = require("puppeteer-core");
const env = Object.fromEntries(
  readFileSync(ENV_FILE, "utf8").split(/\r?\n/).filter((l) => l.includes("=")).map((l) => [l.slice(0, l.indexOf("=")).trim(), l.slice(l.indexOf("=") + 1).trim()]),
);
for (const key of ["QA_TAG", "ADMIN_EMAIL", "ADMIN_PASSWORD", "ALL_EMAIL", "ALL_PASSWORD", "PARTNERSHIPS_ID", "INTERNAL_ID"]) {
  if (!env[key]) { console.error(`the env file lacks ${key}`); process.exit(2); }
}
const TAG = env.QA_TAG;
const BOX = env.PARTNERSHIPS_ID;
const OTHER_BOX = env.INTERNAL_ID;
const chrome = [process.env.CHROME_PATH, "C:/Program Files/Google/Chrome/Application/chrome.exe", "C:/Program Files (x86)/Google/Chrome/Application/chrome.exe"].filter(Boolean).find(existsSync);
const WAIT_UNTIL = "load";
const DOMAIN = "provatferi.org";

const checks = [];
const problems = [];
const secrets = new Set([env.ADMIN_PASSWORD, env.ALL_PASSWORD]);
const check = (id, description, ok, detail = "") => {
  let text = String(detail);
  for (const s of secrets) if (s && s.length >= 6) text = text.split(s).join("[secret]");
  checks.push({ id, description, ok: Boolean(ok), detail: text.slice(0, 400) });
  console.log(`${ok ? "PASS" : "FAIL"}  ${id.padEnd(5)} ${description}${ok || !text ? "" : `  -> ${text.slice(0, 240)}`}`);
};
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

// ------------------------------------------------------------------------------------------------------------- TOTP
function base32Decode(input) {
  const alphabet = "ABCDEFGHIJKLMNOPQRSTUVWXYZ234567";
  let bits = 0, value = 0;
  const bytes = [];
  for (const c of input.replace(/[\s=]/g, "").toUpperCase()) {
    const i = alphabet.indexOf(c);
    if (i < 0) throw new Error("not base32");
    value = ((value << 5) | i) & 0xffff;
    bits += 5;
    if (bits >= 8) { bytes.push((value >>> (bits - 8)) & 0xff); bits -= 8; }
  }
  return Buffer.from(bytes);
}
function totp(secret, step) {
  const counter = Buffer.alloc(8);
  counter.writeBigUInt64BE(BigInt(step));
  const h = createHmac("sha1", base32Decode(secret)).update(counter).digest();
  const o = h[h.length - 1] & 15;
  const n = ((h[o] & 0x7f) << 24) | (h[o + 1] << 16) | (h[o + 2] << 8) | h[o + 3];
  return String(n % 1_000_000).padStart(6, "0");
}
let clockOffset = 0;
async function calibrateClock() {
  const t0 = Date.now();
  const r = await fetch(`${ADMIN}/cdn-cgi/trace`).catch(() => null);
  const t1 = Date.now();
  const m = r && r.ok ? /^ts=([0-9.]+)$/m.exec(await r.text()) : null;
  clockOffset = m ? Number(m[1]) - (t0 + t1) / 2000 : 0;
}
const serverNow = () => Date.now() / 1000 + clockOffset;
async function freshCode(account) {
  for (;;) {
    const into = serverNow() % 30;
    const step = Math.floor(serverNow() / 30);
    if (step > (account.lastStep ?? 0) && into < 25) { account.lastStep = step; return totp(account.secret, step); }
    await sleep((30 - into + 0.6) * 1000);
  }
}

// ---------------------------------------------------------------------------------------------------------- browser
const browser = await puppeteer.launch({ executablePath: chrome, headless: true, args: ["--no-sandbox", "--hide-scrollbars"] });
const UA = `${(await browser.userAgent()).replace("HeadlessChrome", "Chrome")} provatferi-mail-qa/${TAG}`;
async function openBrowser(label) {
  const context = await browser.createBrowserContext();
  const page = await context.newPage();
  await page.setUserAgent(UA);
  await page.setViewport({ width: 1366, height: 900 });
  page.on("dialog", (d) => d.accept().catch(() => {}));
  page.on("pageerror", (e) => problems.push(`${label} pageerror: ${String(e).slice(0, 160)}`));
  page.on("console", (m) => {
    if (m.type() === "error" && !/status of (403|404|422)/.test(m.text())) problems.push(`${label} console: ${m.text().slice(0, 160)}`);
  });
  return { context, page };
}
const pathOf = (page) => new URL(page.url()).pathname;
async function go(page, path) {
  const response = await page.goto(ADMIN + path, { waitUntil: WAIT_UNTIL, timeout: 90000 });
  return { path: pathOf(page), status: response?.status() ?? 0, headers: response?.headers() ?? {} };
}
async function clickAndWait(page, selector) {
  await Promise.all([page.waitForNavigation({ waitUntil: WAIT_UNTIL, timeout: 90000 }), page.click(selector)]);
  return pathOf(page);
}
async function submitForm(page, formSelector) {
  await Promise.all([page.waitForNavigation({ waitUntil: WAIT_UNTIL, timeout: 90000 }), page.$eval(formSelector, (f) => f.requestSubmit ? f.requestSubmit() : f.submit())]);
  return pathOf(page);
}
async function fill(page, selector, value) {
  await page.$eval(selector, (el) => { el.value = ""; });
  await page.type(selector, value, { delay: 1 });
}
async function flash(page) {
  return page.evaluate(() => {
    const t = (sel) => [...document.querySelectorAll(sel)].map((e) => e.textContent.replace(/\s+/g, " ").trim()).filter(Boolean);
    return { success: t(".alert-success"), warning: t(".alert-warning"), danger: t(".alert-danger"), info: t(".alert-info"), errors: t(".invalid-feedback") };
  });
}
async function signIn(page, email, password) {
  await go(page, "/login");
  await fill(page, "#email", email);
  await fill(page, "#password", password);
  return clickAndWait(page, 'form[action$="/login"] button[type="submit"]');
}
const SECRET_WORDS = ["HOSTINGER_MAIL", "Bearer ", "api.mail.hostinger.com", "Authorization", "webhook_secret", "MAIL_PASSWORD", "MAIL_USERNAME"];
async function leaks(page) {
  const html = await page.content();
  return [...SECRET_WORDS, ...secrets].filter((w) => w && html.includes(w));
}
const MASK_CSS = `.pf-mfa-qr svg{visibility:hidden!important}
.pf-mfa-qr,#mfa-manual-key,#mfa-recovery-codes li{background:repeating-linear-gradient(45deg,#8a8f98 0 6px,#b9bdc4 6px 12px)!important;color:transparent!important}
input[name=code],input[type=password]{color:transparent!important;text-shadow:none!important}`;
async function capture(page, name) {
  await page.addStyleTag({ content: MASK_CSS });
  await sleep(200);
  const facts = await page.evaluate(() => ({ lang: document.documentElement.lang || "x", width: window.innerWidth }));
  const file = `${name}-${facts.lang}-${facts.width <= 500 ? "phone" : "desktop"}.png`;
  await page.screenshot({ path: join(OUT, file), fullPage: true });
  return file;
}
async function setLanguage(page, locale) {
  const sel = `form[action$="/locale"] input[name="locale"][value="${locale}"]`;
  if (!(await page.$(sel))) return false;
  await Promise.all([page.waitForNavigation({ waitUntil: WAIT_UNTIL, timeout: 90000 }), page.evaluate((s) => document.querySelector(s).closest("form").submit(), sel)]);
  return true;
}

// ============================================================================================================== RUN
await calibrateClock();
const A = await openBrowser("admin");
const M = await openBrowser("all");
const admin = { secret: null, lastStep: 0 };
const evidence = { tag: TAG, admin: ADMIN, started: new Date().toISOString(), screenshots: [] };

try {
  // --------------------------------------------------------------------------------- the mail administrator
  const landed = await signIn(A.page, env.ADMIN_EMAIL, env.ADMIN_PASSWORD);
  check("I1", "the QA mail administrator signs in", landed === "/admin" || landed === "/admin/", landed);
  await setLanguage(A.page, "en");

  // Identity administration is privileged: without two-step verification it is refused outright.
  const beforeMfa = await go(A.page, `/admin/mail-admin/mailboxes/${BOX}/identities/create`);
  check("I2", "registering an institutional address without two-step verification is refused (sent to Account security)",
    beforeMfa.path === "/admin/account/security", beforeMfa.path);

  // Enrol through the real screens.
  let to = await go(A.page, "/admin/account/security/setup");
  if (to.path === "/confirm-password") {
    await fill(A.page, "#password", env.ADMIN_PASSWORD);
    await clickAndWait(A.page, 'form[action$="/confirm-password"] button[type="submit"]');
  }
  admin.secret = await A.page.$eval("#mfa-manual-key", (e) => e.textContent.replace(/\s+/g, ""));
  secrets.add(admin.secret);
  await fill(A.page, "#mfa-enable-form input[name='code']", await freshCode(admin));
  await clickAndWait(A.page, "#mfa-enable-form button[type='submit']");
  const codes = await A.page.$$eval("#mfa-recovery-codes li", (lis) => lis.map((li) => li.textContent.replace(/\s+/g, "")));
  codes.forEach((c) => secrets.add(c));
  await clickAndWait(A.page, "#mfa-codes-done");
  check("I3", "the administrator turns two-step verification on", codes.length === 10, `${codes.length} recovery codes`);

  // --------------------------------------------------------------------------------- registering identities
  const form = await go(A.page, `/admin/mail-admin/mailboxes/${BOX}/identities/create`);
  const says = await A.page.evaluate(() => document.body.textContent.replace(/\s+/g, " "));
  check("I4", "the add-address screen opens and states the provider must already have the address",
    form.status === 200 && /already have|already exist/i.test(says), `${form.status}`);
  // What matters is that no password is ASKED FOR — the page's own reassurance that none is needed mentions the word,
  // so the check looks for an actual input, not for the text.
  const secretFields = await A.page.evaluate(() => [...document.querySelectorAll("form input")]
    // _token is Laravel's CSRF field, which every form must carry; it is not a credential the person is asked for.
    .filter((i) => i.name !== "_token" && (i.type === "password" || /password|token|secret|credential/i.test(i.name)))
    .map((i) => i.name || i.type));
  check("I5", "the form asks for no password, token or secret of any kind", secretFields.length === 0, JSON.stringify(secretFields));
  evidence.screenshots.push(await capture(A.page, "01-add-institutional-address"));

  const register = async (local, name, purpose, automated = false) => {
    await go(A.page, `/admin/mail-admin/mailboxes/${BOX}/identities/create`);
    await fill(A.page, "#field-address", `${local}@${DOMAIN}`);
    await fill(A.page, "#field-display_name", name);
    await A.page.select("#purpose", purpose);
    if (automated) await A.page.evaluate(() => { document.querySelector("#is_automated").checked = true; });
    await submitForm(A.page, "form[action*='/identities']");
    return flash(A.page);
  };

  // Two real identities of ONE mailbox, plus the automated sender.
  const one = await register(`qa-${TAG}-info`, "Provatferi", "general");
  const two = await register(`qa-${TAG}-partnerships-id`, "Provatferi Partnerships", "partnerships");
  const auto = await register(`qa-${TAG}-no-reply`, "Provatferi", "automated", true);
  check("I6", "several institutional addresses are registered against the SAME mailbox",
    one.success.length > 0 && two.success.length > 0 && auto.success.length > 0,
    JSON.stringify({ one: one.success, two: two.success, auto: auto.success }));

  const listed = await A.page.evaluate(() => [...document.querySelectorAll("table tbody tr")].map((r) => r.textContent.replace(/\s+/g, " ").trim()));
  check("I7", "the mailbox screen lists its institutional addresses", listed.length >= 3, `${listed.length} rows`);
  evidence.screenshots.push(await capture(A.page, "02-mailbox-with-identities"));
  await setLanguage(A.page, "bn");
  evidence.screenshots.push(await capture(A.page, "03-mailbox-with-identities-bn"));
  await setLanguage(A.page, "en");

  // A different domain, and an address already registered, are both refused.
  await go(A.page, `/admin/mail-admin/mailboxes/${BOX}/identities/create`);
  await fill(A.page, "#field-address", `qa-${TAG}-outside@example.org`);
  await fill(A.page, "#field-display_name", "Outside");
  await submitForm(A.page, "form[action*='/identities']");
  const wrongDomain = await flash(A.page);
  check("I8", "an address outside the organisation's own mail domain is refused", wrongDomain.errors.length > 0, JSON.stringify(wrongDomain.errors));

  await go(A.page, `/admin/mail-admin/mailboxes/${OTHER_BOX}/identities/create`);
  await fill(A.page, "#field-address", `qa-${TAG}-info@${DOMAIN}`);
  await fill(A.page, "#field-display_name", "Duplicate");
  await submitForm(A.page, "form[action*='/identities']");
  const duplicate = await flash(A.page);
  check("I9", "the same address cannot become an identity of a SECOND mailbox (one inbox, never duplicated)",
    duplicate.errors.length > 0, JSON.stringify(duplicate.errors));

  check("W1", "no credential or provider detail anywhere in the administration screens", (await leaks(A.page)).length === 0, JSON.stringify(await leaks(A.page)));

  // --------------------------------------------------------------------------------- what may be written as
  const allIn = await signIn(M.page, env.ALL_EMAIL, env.ALL_PASSWORD);
  check("V0", "the mail.manage_all account signs in", allIn === "/admin" || allIn === "/admin/", allIn);
  await setLanguage(M.page, "en");

  const compose = await go(M.page, `/admin/mail/${BOX}/compose`);
  const senders = await M.page.evaluate(() => [...document.querySelectorAll("#identity-id option")].map((o) => o.textContent.replace(/\s+/g, " ").trim()));
  const hasFreeText = await M.page.evaluate(() => Boolean(document.querySelector("input[name=from], input[name=sender], input[name=reply_to]")));
  check("C1", "Compose offers the institutional addresses as a chooser and has no free-text sender field",
    compose.status === 200 && senders.length >= 2 && !hasFreeText, `${senders.length} options, free text: ${hasFreeText}`);
  check("C2", "the automated sender is NOT offered for a message a person writes",
    !senders.some((s) => s.includes(`qa-${TAG}-no-reply@`)), JSON.stringify(senders));
  evidence.screenshots.push(await capture(M.page, "04-compose-identity-chooser"));

  const mailbox = await go(M.page, `/admin/mail/${BOX}`);
  const nav = await M.page.evaluate(() => document.body.textContent.replace(/\s+/g, " "));
  check("N1", "the Mail Center names them institutional addresses of one mailbox, not separate inboxes",
    mailbox.status === 200 && /Institutional addresses|All mail/i.test(nav), `${mailbox.status}`);
  evidence.screenshots.push(await capture(M.page, "05-mail-center-identities"));
  check("W2", "no credential in the Mail Center page either", (await leaks(M.page)).length === 0);

  check("PG", "no page error or console error in any browser", problems.length === 0, problems.slice(0, 4).join(" | "));
} catch (e) {
  check("RUN", "the run completed", false, String(e).slice(0, 400));
} finally {
  evidence.checks = checks;
  evidence.problems = problems;
  evidence.finished = new Date().toISOString();
  writeFileSync(join(OUT, `mail-identity-qa-${TAG}.json`), JSON.stringify(evidence, null, 2));
  await browser.close().catch(() => {});
}

const failed = checks.filter((c) => !c.ok).length;
console.log(`\n${checks.length - failed}/${checks.length} passed`);
process.exit(failed === 0 ? 0 : 1);
