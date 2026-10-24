// Real-Chrome acceptance of the admin Mail Center and Staff Dashboard (admin-erp/docs/MAIL_CENTER.md).
//
//   node scripts/mail-center-qa.mjs --phase production|local --admin <url> --env <file> --out <evidence dir>
//
// The env file holds QA_TAG, LIMITED_/ADMIN_/ALL_EMAIL and _PASSWORD (disposable QA accounts made by
// `deploy/qa/mail-center-qa.php qa-setup` on production, or by a local set-up script), and the ids printed by that set-up:
// PARTNERSHIPS_ID, OTHER_ID (a mailbox the limited account must NOT reach), LIMITED_ROLE_ID, LIMITED_USER_ID.
// The owner's account is never used, signed in, signed out or changed.
//
//   production  everything that does not need the mail provider (on 2026-10-24 production has no Mail API token):
//               the limited account's dashboard, menu and Mail Center, isolation and forbidden areas, headers, no secret
//               in any page, the "not connected" state and a refused send, access assignment through the role page with
//               two-step verification and step-up, revocation, the audit log, mail.manage_all, BN/EN, light/dark, phone.
//   local       the same account model against the REAL Hostinger Mail API with the developer token (its mailbox stands
//               in for partnerships@): send with attachments, read, attachment headers, reply, reply all, forward with a
//               ticked attachment, flag / unread / trash / restore, search, hostile HTML in the sandboxed frame, remote
//               images held back, duplicate submit. Only messages whose subject carries the QA tag are ever opened or
//               changed; the local set-up script deletes them afterwards.
//
// SECRETS. Passwords and the authenticator secret live in this process only; typed fields, the QR code, the key and the
// recovery codes are masked before every capture; the report replaces any secret by [secret].
import { createRequire } from "node:module";
import { existsSync, mkdirSync, readFileSync, writeFileSync } from "node:fs";
import { join } from "node:path";
import { createHmac, randomUUID } from "node:crypto";

const arg = (name, fallback) => {
  const i = process.argv.indexOf(`--${name}`);
  return i > -1 ? process.argv[i + 1] : fallback;
};
const ADMIN = (arg("admin") ?? "").replace(/\/+$/, "");
const OUT = arg("out");
const ENV_FILE = arg("env");
const PHASE = arg("phase");
if (!ADMIN || !OUT || !ENV_FILE || !["production", "local"].includes(PHASE)) {
  console.error("usage: --phase production|local --admin <url> --env <file> --out <dir>");
  process.exit(2);
}
mkdirSync(OUT, { recursive: true });
const require = createRequire(import.meta.url);
const puppeteer = require("puppeteer-core");
const env = Object.fromEntries(readFileSync(ENV_FILE, "utf8").split(/\r?\n/).filter((l) => l.includes("=")).map((l) => [l.slice(0, l.indexOf("=")).trim(), l.slice(l.indexOf("=") + 1).trim()]));
const TAG = env.QA_TAG;
for (const key of ["QA_TAG", "LIMITED_EMAIL", "LIMITED_PASSWORD", "ADMIN_EMAIL", "ADMIN_PASSWORD", "ALL_EMAIL", "ALL_PASSWORD", "PARTNERSHIPS_ID", "OTHER_ID", "LIMITED_ROLE_ID", "LIMITED_USER_ID", "EXPECTED_ADDRESS"]) {
  if (!env[key]) {
    console.error(`the env file lacks ${key}`);
    process.exit(2);
  }
}
const chrome = [process.env.CHROME_PATH, "C:/Program Files/Google/Chrome/Application/chrome.exe", "C:/Program Files (x86)/Google/Chrome/Application/chrome.exe"].filter(Boolean).find(existsSync);
const UA_MARK = `provatferi-mail-qa/${TAG}`;
// "load", not "networkidle2": PHP's single-threaded development server can leave a keep-alive socket open, and an idle-
// network wait then never ends (seen locally on 2026-10-24); production pages settle either way.
const WAIT_UNTIL = "load";
const P_ID = env.PARTNERSHIPS_ID;
const OTHER_ID = env.OTHER_ID;

const checks = [];
const notes = [];
const problems = [];
const secrets = new Set([env.LIMITED_PASSWORD, env.ADMIN_PASSWORD, env.ALL_PASSWORD]);
const check = (id, description, ok, detail = "") => {
  let text = String(detail);
  for (const s of secrets) if (s && s.length >= 6) text = text.split(s).join("[secret]");
  checks.push({ id, description, ok: Boolean(ok), detail: text.slice(0, 500) });
  console.log(`${ok ? "PASS" : "FAIL"}  ${id.padEnd(6)} ${description}${ok || !text ? "" : `  -> ${text.slice(0, 260)}`}`);
};
const note = (text) => {
  notes.push(text);
  console.log(`NOTE  ${text}`);
};
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

// ------------------------------------------------------------------------------------------------------------- TOTP
function base32Decode(input) {
  const alphabet = "ABCDEFGHIJKLMNOPQRSTUVWXYZ234567";
  let bits = 0;
  let value = 0;
  const bytes = [];
  for (const c of input.replace(/[\s=]/g, "").toUpperCase()) {
    const i = alphabet.indexOf(c);
    if (i < 0) throw new Error("not base32");
    value = ((value << 5) | i) & 0xffff;
    bits += 5;
    if (bits >= 8) {
      bytes.push((value >>> (bits - 8)) & 0xff);
      bits -= 8;
    }
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
    if (step > (account.lastStep ?? 0) && into < 25) {
      account.lastStep = step;
      return totp(account.secret, step);
    }
    await sleep((30 - into + 0.6) * 1000);
  }
}

// ---------------------------------------------------------------------------------------------------------- browser
const browser = await puppeteer.launch({ executablePath: chrome, headless: true, args: ["--no-sandbox", "--hide-scrollbars", "--disable-background-timer-throttling", "--disable-renderer-backgrounding"] });
const UA = `${(await browser.userAgent()).replace("HeadlessChrome", "Chrome")} ${UA_MARK}`;
async function openBrowser(label, { width = 1366, height = 900 } = {}) {
  const context = await browser.createBrowserContext();
  const page = await context.newPage();
  await page.setUserAgent(UA);
  await page.setViewport({ width, height });
  page.on("dialog", (d) => d.accept().catch(() => {}));
  page.on("pageerror", (e) => problems.push(`${label} pageerror: ${String(e).slice(0, 160)}`));
  page.on("console", (m) => {
    // 403/404 are what the isolation checks provoke; ERR_NAME_NOT_RESOLVED is the hostile sample's tracker host
    // (tracker.invalid), requested on purpose after "Show images".
    if (m.type() === "error" && !/Failed to load resource: the server responded with a status of (403|404)/.test(m.text()) && !/tracker\.invalid|ERR_NAME_NOT_RESOLVED/.test(m.text())) {
      problems.push(`${label} console: ${m.text().slice(0, 160)}`);
    }
  });
  return { context, page };
}
const pathOf = (page) => new URL(page.url()).pathname;
const TRACE = Boolean(process.env.QA_TRACE);
const trace = (...parts) => { if (TRACE) console.log(`TRACE ${new Date().toISOString().slice(11, 19)}`, ...parts); };
async function go(page, path) {
  trace("go", path);
  const response = await page.goto(ADMIN + path, { waitUntil: WAIT_UNTIL, timeout: 90000 });
  trace("  ->", response?.status(), pathOf(page));
  return { path: pathOf(page), status: response?.status() ?? 0, headers: response?.headers() ?? {} };
}
async function clickAndWait(page, selector) {
  await Promise.all([page.waitForNavigation({ waitUntil: WAIT_UNTIL, timeout: 90000 }), page.click(selector)]);
  return pathOf(page);
}
async function submitForm(page, formSelector) {
  trace("submit", formSelector);
  await Promise.all([page.waitForNavigation({ waitUntil: WAIT_UNTIL, timeout: 90000 }), page.$eval(formSelector, (f) => f.requestSubmit ? f.requestSubmit() : f.submit())]);
  trace("  ->", pathOf(page));
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
async function pageFetch(page, path, { method = "GET", json = false, body = null } = {}) {
  return page.evaluate(async ({ path, method, json, body }) => {
    const token = document.querySelector('input[name="_token"]')?.value ?? "";
    const headers = { Accept: json ? "application/json" : "text/html" };
    let payload;
    if (body) {
      payload = new URLSearchParams({ _token: token, ...body });
      headers["Content-Type"] = "application/x-www-form-urlencoded";
    }
    const r = await fetch(path, { method, headers, body: payload, redirect: "manual", credentials: "same-origin" });
    const text = r.type === "opaqueredirect" ? "" : await r.text();
    return { status: r.status, type: r.type, cache: r.headers.get("cache-control"), robots: r.headers.get("x-robots-tag"), referrer: r.headers.get("referrer-policy"), nosniff: r.headers.get("x-content-type-options"), ctype: r.headers.get("content-type"), disposition: r.headers.get("content-disposition"), length: text.length, text: text.slice(0, 20000) };
  }, { path, method, json, body });
}
const MASK_CSS = `
.pf-mfa-qr svg{visibility:hidden!important}
.pf-mfa-qr,#mfa-manual-key,#mfa-recovery-codes li,.pf-mfa-code-item{background:repeating-linear-gradient(45deg,#8a8f98 0 6px,#b9bdc4 6px 12px)!important;color:transparent!important;text-shadow:none!important}
input[name=code],input[type=password]{color:transparent!important;text-shadow:none!important}`;
async function capture(page, name) {
  await page.addStyleTag({ content: MASK_CSS });
  if (PHASE === "local") {
    // Locally the stand-in mailbox is a real one: every message that is not this run's own is blurred in the evidence.
    await page.evaluate((tag) => {
      for (const el of document.querySelectorAll(".pf-mail-row, [data-dashboard-recent] a")) {
        if (!el.textContent.includes(tag)) el.style.filter = "blur(6px)";
      }
    }, TAG);
  }
  await sleep(250);
  const facts = await page.evaluate(() => ({ lang: document.documentElement.lang || "x", theme: document.documentElement.getAttribute("data-bs-theme") || "x", width: window.innerWidth }));
  const file = `${name.replace(/[^a-z0-9-]+/gi, "-")}-${facts.lang}-${facts.theme}-${facts.width <= 500 ? "phone" : "desktop"}.png`;
  await page.screenshot({ path: join(OUT, file), fullPage: true });
  return file;
}
/** Captures the page as it is in light, dark, and (light) on a phone. */
async function shots(page, name) {
  const files = [];
  for (const [dark, width] of [[false, 1366], [true, 1366], [false, 390]]) {
    await page.setViewport({ width, height: width < 500 ? 844 : 900 });
    await page.emulateMediaFeatures([{ name: "prefers-color-scheme", value: dark ? "dark" : "light" }]);
    await page.evaluate(() => { try { localStorage.removeItem("provatferi-admin-theme"); } catch (e) { /* none */ } });
    await page.reload({ waitUntil: WAIT_UNTIL, timeout: 90000 });
    files.push(await capture(page, name));
  }
  await page.setViewport({ width: 1366, height: 900 });
  await page.emulateMediaFeatures([{ name: "prefers-color-scheme", value: "light" }]);
  return files;
}
async function setLanguage(page, locale) {
  const sel = `form[action$="/locale"] input[name="locale"][value="${locale}"]`;
  if (!(await page.$(sel))) return false;
  await Promise.all([page.waitForNavigation({ waitUntil: WAIT_UNTIL, timeout: 90000 }), page.evaluate((s) => document.querySelector(s).closest("form").submit(), sel)]);
  return true;
}
async function signIn(page, email, password) {
  await go(page, "/login");
  await fill(page, "#email", email);
  await fill(page, "#password", password);
  return clickAndWait(page, 'form[action$="/login"] button[type="submit"]');
}
/** Removes this browser's own session server-side by expiring its cookies (never a /logout of a real account). */
async function forget(ctx) {
  await ctx.context.close().catch(() => {});
}
const SECRET_WORDS = ["HOSTINGER_MAIL", "Bearer ", "api.mail.hostinger.com", "Authorization", "webhook_secret", "MAIL_PASSWORD"];
async function leaks(page) {
  const html = await page.content();
  return [...SECRET_WORDS, ...secrets].filter((w) => w && html.includes(w));
}
const sideTitles = (page) => page.$$eval(".side-nav-title", (els) => els.map((e) => e.textContent.trim()));

// ============================================================================================================== RUN
await calibrateClock();
const L = await openBrowser("limited");
const A = await openBrowser("admin");
const M = await openBrowser("all");
const admin = { secret: null, lastStep: 0 };
const evidence = { phase: PHASE, tag: TAG, admin: ADMIN, started: new Date().toISOString(), screenshots: [] };

try {
  // ---------------------------------------------------------------------------------------- the limited account
  const landed = await signIn(L.page, env.LIMITED_EMAIL, env.LIMITED_PASSWORD);
  check("L1", "the limited partnerships account signs in with its password (no two-step required yet)", landed === "/admin" || landed === "/admin/", landed);
  await setLanguage(L.page, "en");
  const dash = await L.page.evaluate(() => ({
    staff: Boolean(document.querySelector('[data-dashboard="staff"]')),
    mail: document.querySelector("[data-dashboard-mail]")?.textContent.replace(/\s+/g, " ").trim() ?? null,
    stats: document.querySelectorAll(".pf-stat-value").length,
    quick: [...document.querySelectorAll(".card-title")].map((e) => e.textContent.trim()),
  }));
  check("H1", "the dashboard shows the account's own panels, its partnerships mailbox and no organisation figures", dash.staff && dash.mail !== null && dash.stats === 0 && !dash.quick.some((t) => /Quick actions|Recent membership|Recent activities/.test(t)), JSON.stringify(dash));
  const titles = await sideTitles(L.page);
  check("H2", "the menu shows Overview, Mail and Account only — no empty heading", JSON.stringify(titles) === JSON.stringify(["Overview", "Mail", "Account"]), JSON.stringify(titles));
  check("H3", "the top bar carries the Mail Center badge link", Boolean(await L.page.$("[data-mail-unread-url]")));
  evidence.screenshots.push(...(await shots(L.page, "01-limited-dashboard")));

  const center = await go(L.page, "/admin/mail");
  check("A1", "the Mail Center opens on the partnerships mailbox", center.path === `/admin/mail/${P_ID}`, center.path);
  const boxes = await L.page.$$eval(".pf-mail-box", (els) => els.map((e) => e.querySelector(".pf-mail-box-address")?.textContent.trim()));
  check("A2", "only ONE mailbox is listed: the partnerships one", boxes.length === 1 && boxes[0] === env.EXPECTED_ADDRESS, JSON.stringify(boxes));
  check("X1", "Mail Center pages are no-store, noindex, no-referrer, nosniff", /no-store/.test(center.headers["cache-control"] ?? "") && /noindex/.test(center.headers["x-robots-tag"] ?? "") && center.headers["referrer-policy"] === "no-referrer" && center.headers["x-content-type-options"] === "nosniff", JSON.stringify({ c: center.headers["cache-control"], r: center.headers["x-robots-tag"], p: center.headers["referrer-policy"] }));
  check("W1", "no credential or provider detail anywhere in the page", (await leaks(L.page)).length === 0, JSON.stringify(await leaks(L.page)));
  if (PHASE === "production") {
    const failure = await L.page.$eval("[data-mail-failure]", (e) => e.getAttribute("data-mail-failure")).catch(() => null);
    check("A3", "with no token on the server, the page says the Mail Center is not connected yet (no error page)", failure === "unconfigured" && center.status === 200, `${failure} ${center.status}`);
  }
  evidence.screenshots.push(...(await shots(L.page, "02-limited-mail-center")));

  // Isolation: another mailbox by every route; a guessed id; a forged POST.
  const iso = [];
  for (const path of [`/admin/mail/${OTHER_ID}`, `/admin/mail/${OTHER_ID}/messages/1?folder=INBOX`, `/admin/mail/${OTHER_ID}/messages/1/attachments/0?folder=INBOX`, `/admin/mail/${OTHER_ID}/compose`, `/admin/mail/${OTHER_ID}/messages/1/reply?folder=INBOX`, `/admin/mail/${OTHER_ID}/messages/1/forward?folder=INBOX`, "/admin/mail/999999", "/admin/mail/999999/messages/1?folder=INBOX"]) {
    iso.push([path, (await pageFetch(L.page, path)).status]);
  }
  const forgedSend = await pageFetch(L.page, `/admin/mail/${OTHER_ID}/compose`, { method: "POST", body: { to: "x@example.org", subject: "forged", body: "forged", form_key: randomUUID() } });
  const forgedState = await pageFetch(L.page, `/admin/mail/${OTHER_ID}/messages/1/state?folder=INBOX`, { method: "POST", body: { action: "trash" } });
  iso.push(["POST send", forgedSend.status], ["POST state", forgedState.status]);
  check("K1", "every route of another mailbox, a guessed id and forged POSTs answer 404", iso.every(([, s]) => s === 404), JSON.stringify(iso));
  if (PHASE === "production") {
    // Only where nothing can be sent (no token): against a real provider this POST would really send a message.
    const fromSpoof = await pageFetch(L.page, `/admin/mail/${P_ID}/compose`, { method: "POST", body: { to: "x@example.org", subject: `[MC QA ${TAG}] spoof`, body: "x", from: "someone@example.org", mailbox_id: OTHER_ID, form_key: randomUUID() } });
    check("R1", "a POST that names another sender or mailbox is handled for the authorised mailbox only (no error page)", [200, 302].includes(fromSpoof.status) || fromSpoof.type === "opaqueredirect", `${fromSpoof.status} ${fromSpoof.type}`);
  }

  const forbidden = [];
  for (const path of ["/admin/system/users", "/admin/system/roles", "/admin/system/permissions", "/admin/settings", "/admin/membership/members", "/admin/membership/seasons", "/admin/leadership", "/admin/recruitment", "/admin/mail-admin/mailboxes", "/admin/mail-admin/audit"]) {
    forbidden.push([path, (await pageFetch(L.page, path)).status]);
  }
  check("G1", "users, roles, permissions, settings, membership, leadership, recruitment, the mailbox registry and the mail audit log are refused (403)", forbidden.every(([, s]) => s === 403), JSON.stringify(forbidden));

  const unread = JSON.parse((await pageFetch(L.page, "/admin/mail/unread", { json: true })).text || "{}");
  check("A4", "the unread badge counts only the partnerships mailbox", Array.isArray(unread.mailboxes) && unread.mailboxes.length === 1 && unread.mailboxes[0].address === env.EXPECTED_ADDRESS, JSON.stringify(unread));

  const compose = await go(L.page, `/admin/mail/${P_ID}/compose`);
  const composeFacts = await L.page.evaluate(() => ({ from: document.querySelector(".pf-mail-from")?.textContent.replace(/\s+/g, " ").trim(), fromField: Boolean(document.querySelector('[name="from"],[name="sender"],[name="reply_to"]')) }));
  check("C0", "the compose form shows the partnerships mailbox as sender and has no sender field", compose.status === 200 && (composeFacts.from ?? "").includes(env.EXPECTED_ADDRESS) && !composeFacts.fromField, JSON.stringify(composeFacts));
  evidence.screenshots.push(await capture(L.page, "03-limited-compose"));

  if (PHASE === "production") {
    await fill(L.page, "#field-to", "qa-recipient@example.org");
    await fill(L.page, "#field-subject", `[MC QA ${TAG}] production send while not connected`);
    await fill(L.page, "#field-body", "This must not be sent: the mail service is not connected.");
    await submitForm(L.page, "form[data-mail-send-form]");
    const f = await flash(L.page);
    const html = await L.page.content();
    check("C1", "a send while not connected is refused politely: 'not connected … not sent', no error page", f.danger.some((t) => /not connected/.test(t) && /not sent/.test(t)) && !/Exception|vendor\/|Stack trace/.test(html), JSON.stringify(f));
  }

  // Bangla.
  await setLanguage(L.page, "bn");
  await go(L.page, `/admin/mail/${P_ID}`);
  const bn = await L.page.evaluate(() => ({ lang: document.documentElement.lang, title: document.querySelector(".side-nav-title")?.textContent.trim() }));
  check("I18N", "the Mail Center renders in Bangla too", bn.lang === "bn" && /[ঀ-৿]/.test(bn.title ?? ""), JSON.stringify(bn));
  evidence.screenshots.push(...(await shots(L.page, "04-limited-mail-center")));
  await go(L.page, "/admin");
  evidence.screenshots.push(await capture(L.page, "05-limited-dashboard"));
  await setLanguage(L.page, "en");

  // -------------------------------------------------------------------------- local: the real provider, end to end
  if (PHASE === "local") {
    await localProviderFlows();
  }

  // ------------------------------------------------------------------------------------- the access administrator
  const adminIn = await signIn(A.page, env.ADMIN_EMAIL, env.ADMIN_PASSWORD);
  check("M0", "the QA mail administrator signs in", adminIn === "/admin" || adminIn === "/admin/", adminIn);
  await setLanguage(A.page, "en");
  const roleEdit = await go(A.page, `/admin/system/roles/${env.LIMITED_ROLE_ID}/edit`);
  const grid = await A.page.evaluate((pid) => ({
    card: Boolean(document.querySelector("#mailbox-access")),
    row: [...document.querySelectorAll(`input[name^="mailbox_access[${pid}]"]`)].map((i) => [i.getAttribute("data-capability"), i.checked]),
    secret: /token|password/i.test(document.querySelector("#mailbox-access")?.textContent ?? ""),
  }), P_ID);
  check("M1", "Users & Permissions → the role → Mailbox access shows Read/Send/Reply/Forward/Message state, all ticked for partnerships", roleEdit.status === 200 && grid.card && grid.row.length === 5 && grid.row.every(([, on]) => on) && !grid.secret, JSON.stringify(grid));
  evidence.screenshots.push(await capture(A.page, "06-admin-role-mailbox-access"));

  // Without two-step verification, handing out mailbox access is refused outright.
  await A.page.evaluate((pid) => { document.querySelector(`input[name="mailbox_access[${pid}][read]"]`).checked = false; for (const c of ["reply", "forward", "manage_state"]) document.querySelector(`input[name="mailbox_access[${pid}][${c}]"]`).checked = false; }, P_ID);
  await submitForm(A.page, "form[data-mail-access-form]");
  const noMfa = await flash(A.page);
  check("Y1", "an administrator WITHOUT two-step verification cannot change mailbox access (sent to Account security)", pathOf(A.page) === "/admin/account/security" && noMfa.warning.length > 0, `${pathOf(A.page)} ${JSON.stringify(noMfa)}`);
  check("Y2", "…and nothing changed: the limited account still opens partnerships", (await pageFetch(L.page, `/admin/mail/${P_ID}`)).status === 200);

  // Enrol two-step verification through the real screens.
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
  check("Y3", "the administrator turns two-step verification on through the Account security screens", codes.length === 10, `${codes.length} recovery codes`);

  // Revoke: untick everything on partnerships, save (the code was just entered, so the step-up is satisfied).
  await go(A.page, `/admin/system/roles/${env.LIMITED_ROLE_ID}/edit`);
  await A.page.evaluate((pid) => { for (const i of document.querySelectorAll(`input[name^="mailbox_access[${pid}]"]`)) i.checked = false; }, P_ID);
  await submitForm(A.page, "form[data-mail-access-form]");
  const revoked = await flash(A.page);
  check("U1", "revoking partnerships access on the role saves (one mailbox changed)", revoked.success.some((t) => /1 mailbox changed/.test(t)), JSON.stringify(revoked));
  const afterRevoke = await go(L.page, `/admin/mail/${P_ID}`);
  check("U2", "the limited account's very next request: partnerships is gone (404)", afterRevoke.status === 404, String(afterRevoke.status));
  const emptyCenter = await go(L.page, "/admin/mail");
  check("U3", "the Mail Center now says no mailbox has been assigned", emptyCenter.status === 200 && (await L.page.content()).includes("No mailbox has been assigned to you"), String(emptyCenter.status));

  // Re-grant through the grid.
  await go(A.page, `/admin/system/roles/${env.LIMITED_ROLE_ID}/edit`);
  await A.page.evaluate((pid) => { for (const i of document.querySelectorAll(`input[name^="mailbox_access[${pid}]"]`)) i.checked = true; }, P_ID);
  await submitForm(A.page, "form[data-mail-access-form]");
  check("U4", "granting it again works at once", (await go(L.page, `/admin/mail/${P_ID}`)).status === 200);

  // The user page shows what the role gives.
  await go(A.page, `/admin/system/users/${env.LIMITED_USER_ID}`);
  const via = await A.page.$$eval("#mailbox-access .fs-11.text-muted", (els) => els.map((e) => e.textContent.trim()));
  check("M2", "the user's page shows the access the account gets through its role", via.some((t) => /via /.test(t)), JSON.stringify(via.slice(0, 5)));
  evidence.screenshots.push(await capture(A.page, "07-admin-user-mailbox-access"));

  // The audit log.
  await go(A.page, "/admin/mail-admin/audit");
  const audit = await A.page.$$eval("tbody tr", (rows) => rows.map((r) => r.textContent.replace(/\s+/g, " ").trim()));
  check("Z1", "the mail audit log lists the access changes (and, on production, the refused send)", audit.filter((r) => /Changed mailbox access/.test(r)).length >= 2 && (PHASE !== "production" || audit.some((r) => /Sending failed/.test(r))), `${audit.length} rows`);
  check("Z2", "no credential or message content in the audit log", (await leaks(A.page)).length === 0 && !audit.some((r) => /This must not be sent/.test(r)));
  evidence.screenshots.push(await capture(A.page, "08-admin-mail-audit"));

  const registry = await go(A.page, "/admin/mail-admin/mailboxes");
  const conn = await A.page.$eval("[data-mail-connection]", (e) => e.getAttribute("data-mail-connection")).catch(() => null);
  check("M3", PHASE === "production" ? "the registry says plainly that no Mail API token is configured on the server" : "the registry sees the developer token", registry.status === 200 && conn === (PHASE === "production" ? "unconfigured" : "connected"), String(conn));
  check("W2", "the registry shows no credential", (await leaks(A.page)).length === 0);
  evidence.screenshots.push(...(await shots(A.page, "09-admin-registry")));

  // Two-step verification for the mail role: switch it on, the limited account must enrol before anything else.
  await go(A.page, `/admin/system/roles/${env.LIMITED_ROLE_ID}/edit`);
  await A.page.$eval("#role-mfa-required", (i) => { i.checked = true; });
  await submitForm(A.page, "#role-mfa-card form");
  const mustEnrol = await go(L.page, `/admin/mail/${P_ID}`);
  check("Y4", "with the role requiring two-step verification, the limited account is sent to enrol before the Mail Center", mustEnrol.path === "/admin/account/security", mustEnrol.path);
  await go(A.page, `/admin/system/roles/${env.LIMITED_ROLE_ID}/edit`);
  await A.page.$eval("#role-mfa-required", (i) => { i.checked = false; });
  await submitForm(A.page, "#role-mfa-card form");
  check("Y5", "switching the requirement off again restores access", (await go(L.page, `/admin/mail/${P_ID}`)).status === 200);

  // ------------------------------------------------------------------------------ mail.manage_all (Super Admin level)
  const allIn = await signIn(M.page, env.ALL_EMAIL, env.ALL_PASSWORD);
  check("V0", "the mail.manage_all account signs in", allIn === "/admin" || allIn === "/admin/", allIn);
  await go(M.page, "/admin/mail");
  const allBoxes = await M.page.$$eval(".pf-mail-box", (els) => els.length);
  const other = await go(M.page, `/admin/mail/${OTHER_ID}`);
  check("V1", "mail.manage_all sees every enabled mailbox, including the one the limited account cannot reach", allBoxes >= 2 && other.status === 200, `${allBoxes} boxes, other ${other.status}`);
  check("V2", "…and nothing beyond mail: users and the registry are refused", (await pageFetch(M.page, "/admin/system/users")).status === 403 && (await pageFetch(M.page, "/admin/mail-admin/mailboxes")).status === 403);
  evidence.screenshots.push(await capture(M.page, "10-all-mailboxes"));
} catch (e) {
  check("RUN", "the run completed without an unexpected error", false, String(e?.stack ?? e).slice(0, 400));
} finally {
  for (const ctx of [L, A, M]) await forget(ctx);
  await browser.close();
  check("PG", "no page error or console error in any browser", problems.length === 0, JSON.stringify(problems.slice(0, 6)));
  const failed = checks.filter((c) => !c.ok).length;
  evidence.finished = new Date().toISOString();
  evidence.summary = `${checks.length - failed}/${checks.length} passed`;
  writeFileSync(join(OUT, `mail-center-qa-${PHASE}-${TAG}.json`), JSON.stringify({ ...evidence, checks, notes, problems }, null, 2));
  console.log(`\n${evidence.summary}`);
  process.exitCode = failed ? 1 : 0;
}

// ======================================================================================== local real-provider flows
async function localProviderFlows() {
  const box = P_ID; // the developer token's mailbox, standing in for partnerships@
  const self = env.EXPECTED_ADDRESS.toLowerCase();
  const subject = `[MC QA ${TAG}] partnership proposal`;
  const tmp = join(OUT, "fixtures");
  mkdirSync(tmp, { recursive: true });
  const png = Buffer.from("iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==", "base64");
  writeFileSync(join(tmp, "logo.png"), png);
  writeFileSync(join(tmp, "brief.pdf"), "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n");
  writeFileSync(join(tmp, "disguised.png"), "<html><script>alert(1)</script></html>");

  // L-C: compose and send to the mailbox itself, with three attachments; a double click must send once.
  await go(L.page, `/admin/mail/${box}/compose`);
  await fill(L.page, "#field-to", self);
  await fill(L.page, "#field-subject", subject);
  await fill(L.page, "#field-body", `Dear team,\n\nPlease find the proposal attached (${TAG}).`);
  const input = await L.page.$("#field-attachments");
  await input.uploadFile(join(tmp, "logo.png"), join(tmp, "brief.pdf"), join(tmp, "disguised.png"));
  await Promise.all([L.page.waitForNavigation({ waitUntil: WAIT_UNTIL, timeout: 120000 }), L.page.evaluate(() => { const b = document.querySelector("[data-mail-send-button]"); b.click(); b.click(); })]);
  const sent = await flash(L.page);
  check("LC1", "a message is sent from the mailbox through the real Mail API", sent.success.some((t) => /Message sent/.test(t)), JSON.stringify(sent));

  // Wait for delivery into the mailbox's own Inbox.
  let uid = null;
  for (let i = 0; i < 24 && !uid; i++) {
    await go(L.page, `/admin/mail/${box}?folder=INBOX&q=${encodeURIComponent(TAG)}&in=subject`);
    uid = await L.page.evaluate((s) => { for (const a of document.querySelectorAll(".pf-mail-row")) if (a.textContent.includes(s)) return a.getAttribute("href").match(/messages\/(\d+)/)?.[1] ?? null; return null; }, "partnership proposal");
    if (!uid) await sleep(5000);
  }
  check("LB0", "the message arrives in the Inbox (search inside the folder finds it)", Boolean(uid), String(uid));
  if (!uid) return;
  const sentCount = await go(L.page, `/admin/mail/${box}?folder=INBOX.Sent&q=${encodeURIComponent(`${TAG}] partnership proposal`)}&in=subject`);
  const sentRows = await L.page.$$eval(".pf-mail-row", (rows) => rows.length);
  check("LT2", "exactly one copy is in Sent (the double click sent once)", sentCount.status === 200 && sentRows === 1, String(sentRows));
  evidence.screenshots.push(await capture(L.page, "11-local-sent-search"));

  // L-B: open it.
  const opened = await go(L.page, `/admin/mail/${box}/messages/${uid}?folder=INBOX`);
  const view = await L.page.evaluate(() => ({
    subject: document.querySelector(".pf-mail-subject")?.textContent.trim(),
    text: document.querySelector(".pf-mail-text")?.textContent ?? document.querySelector(".pf-mail-frame")?.contentDocument?.body?.textContent ?? "",
    attachments: [...document.querySelectorAll(".pf-mail-attachments li")].map((li) => li.querySelector(".pf-mail-attachment-name")?.textContent.trim()),
    frame: document.querySelector(".pf-mail-frame")?.getAttribute("sandbox") ?? null,
  }));
  check("LB1", "the message opens: subject, body and its three attachments", opened.status === 200 && /partnership proposal/.test(view.subject ?? "") && /proposal attached/.test(view.text) && view.attachments.length === 3, JSON.stringify(view));
  evidence.screenshots.push(...(await shots(L.page, "12-local-message")));

  // L-M: attachment headers.
  const links = await L.page.$$eval(".pf-mail-attachments a", (as) => as.map((a) => [a.textContent.trim(), a.getAttribute("href")]));
  // Fetched from Node with this browser's session cookie: a download manager on the test machine (IDM) answers in-browser
  // PDF fetches itself with a 204, which would hide the server's own headers.
  const cookie = (await L.page.cookies(ADMIN)).map((c) => `${c.name}=${c.value}`).join("; ");
  const results = {};
  for (const [label, href] of links) {
    const r = await fetch(href, { headers: { Cookie: cookie, "User-Agent": UA }, redirect: "manual" });
    await r.arrayBuffer();
    results[`${label} ${new URL(href).searchParams.get("inline") ? "inline" : "download"} ${href.match(/attachments\/(\d+)/)?.[1]}`] = [r.status, r.headers.get("content-type"), (r.headers.get("content-disposition") ?? "").split(";")[0], r.headers.get("x-content-type-options")];
  }
  const values = Object.values(results);
  check("LM1", "downloads are opaque files (octet-stream, attachment, nosniff); only the real PNG previews as image/png", values.filter((v) => v[1] === "image/png" && v[2] === "inline").length === 1 && values.filter((v) => v[1] === "application/octet-stream" && v[2] === "attachment").length >= 3 && values.every((v) => v[0] === 200 && v[3] === "nosniff"), JSON.stringify(results));
  const disguised = await pageFetch(L.page, `/admin/mail/${box}/messages/${uid}/attachments/2?folder=INBOX&inline=1`);
  check("LM2", "the HTML disguised as a PNG is never shown inline", disguised.ctype === "application/octet-stream" && /^attachment/.test(disguised.disposition ?? ""), `${disguised.ctype} ${disguised.disposition}`);

  // L-D: reply (to the mailbox itself) and reply all.
  await go(L.page, `/admin/mail/${box}/messages/${uid}/reply?folder=INBOX&all=1`);
  const replyAll = await L.page.evaluate(() => ({ to: document.querySelector("#field-to")?.value, cc: document.querySelector("#field-cc")?.value, subject: document.querySelector("#field-subject")?.value, quote: document.querySelector(".pf-mail-quote")?.textContent ?? "" }));
  check("LD0", "reply all is prefilled with Re: and the original quoted", /^Re: /.test(replyAll.subject ?? "") && /wrote:/.test(replyAll.quote), JSON.stringify(replyAll));
  await go(L.page, `/admin/mail/${box}/messages/${uid}/reply?folder=INBOX`);
  if (!(await L.page.$eval("#field-to", (e) => e.value))) await fill(L.page, "#field-to", self);
  await fill(L.page, "#field-body", `Thank you — reply test ${TAG}.`);
  await submitForm(L.page, "form[data-mail-send-form]");
  const replied = await flash(L.page);
  check("LD1", "the reply is sent and the page returns to the original", replied.success.some((t) => /Reply sent/.test(t)) && pathOf(L.page) === `/admin/mail/${box}/messages/${uid}`, `${pathOf(L.page)} ${JSON.stringify(replied)}`);

  // L-E: forward with ONE ticked attachment.
  await go(L.page, `/admin/mail/${box}/messages/${uid}/forward?folder=INBOX`);
  await fill(L.page, "#field-to", self);
  await fill(L.page, "#field-body", `Forward test ${TAG}.`);
  await L.page.$eval("#fwd-att-1", (i) => { i.checked = true; });
  await submitForm(L.page, "form[data-mail-send-form]");
  const forwarded = await flash(L.page);
  check("LE1", "the forward is sent", forwarded.success.some((t) => /forwarded/.test(t)), JSON.stringify(forwarded));
  let fwdUid = null;
  for (let i = 0; i < 24 && !fwdUid; i++) {
    await go(L.page, `/admin/mail/${box}?folder=INBOX&q=${encodeURIComponent("Fwd: [MC QA " + TAG)}&in=subject`);
    fwdUid = await L.page.evaluate(() => document.querySelector(".pf-mail-row")?.getAttribute("href")?.match(/messages\/(\d+)/)?.[1] ?? null);
    if (!fwdUid) await sleep(5000);
  }
  if (fwdUid) {
    await go(L.page, `/admin/mail/${box}/messages/${fwdUid}?folder=INBOX`);
    const fwd = await L.page.evaluate(() => ({ attachments: [...document.querySelectorAll(".pf-mail-attachment-name")].map((e) => e.textContent.trim()), body: document.querySelector(".pf-mail-frame")?.contentDocument?.body?.textContent ?? document.querySelector(".pf-mail-text")?.textContent ?? "" }));
    check("LE2", "the forwarded copy carries exactly the ticked attachment and a marked 'Forwarded message' block", fwd.attachments.length === 1 && /brief\.pdf/.test(fwd.attachments[0] ?? "") && /Forwarded message/.test(fwd.body), JSON.stringify(fwd));
  } else {
    check("LE2", "the forwarded copy arrived", false, "not found within 2 minutes");
  }
  await go(L.page, `/admin/mail/${box}?folder=INBOX&q=${encodeURIComponent(TAG)}&in=subject`);
  const icons = await L.page.evaluate((u) => { const a = document.querySelector(`.pf-mail-row[href*="/messages/${u}?"]`); return a ? [...a.querySelectorAll(".visually-hidden")].map((e) => e.textContent.trim()) : []; }, uid);
  check("LD2", "the original now shows Replied and Forwarded (flags set by the provider)", icons.includes("Replied") && icons.includes("Forwarded"), JSON.stringify(icons));

  // L-S: state.
  const stateOf = async (action) => {
    await go(L.page, `/admin/mail/${box}/messages/${uid}?folder=INBOX`);
    const form = await L.page.$(`form input[name="action"][value="${action}"]`);
    if (!form) return null;
    await Promise.all([L.page.waitForNavigation({ waitUntil: WAIT_UNTIL, timeout: 90000 }), L.page.evaluate((a) => document.querySelector(`form input[name="action"][value="${a}"]`).closest("form").submit(), action)]);
    return flash(L.page);
  };
  const flagged = await stateOf("flag");
  check("LS1", "flag", flagged?.success.some((t) => /Flagged/.test(t)), JSON.stringify(flagged));
  const unreadAgain = await stateOf("unread");
  check("LS2", "mark as unread", unreadAgain?.success.some((t) => /unread/.test(t)), JSON.stringify(unreadAgain));
  const trashed = await stateOf("trash");
  check("LS3", "move to Trash", trashed?.success.some((t) => /Trash/.test(t)), JSON.stringify(trashed));
  await go(L.page, `/admin/mail/${box}?folder=INBOX.Trash&q=${encodeURIComponent(TAG)}&in=subject`);
  const inTrash = await L.page.evaluate(() => document.querySelector(".pf-mail-row")?.getAttribute("href")?.match(/messages\/(\d+)/)?.[1] ?? null);
  if (inTrash) {
    await go(L.page, `/admin/mail/${box}/messages/${inTrash}?folder=INBOX.Trash`);
    await Promise.all([L.page.waitForNavigation({ waitUntil: WAIT_UNTIL, timeout: 90000 }), L.page.evaluate(() => document.querySelector('form input[name="action"][value="inbox"]').closest("form").submit())]);
    check("LS4", "and back to the Inbox", (await flash(L.page)).success.some((t) => /Inbox/.test(t)));
  } else {
    check("LS4", "the trashed message is in Trash", false);
  }

  // L-N/O/P: hostile HTML through the real provider, in the real browser.
  note("hostile-HTML sample: sent to the mailbox by the local set-up script (send-hostile) before this run");
  let hostile = null;
  for (let i = 0; i < 24 && !hostile; i++) {
    await go(L.page, `/admin/mail/${box}?folder=INBOX&q=${encodeURIComponent(TAG + "] hostile")}&in=subject`);
    hostile = await L.page.evaluate(() => document.querySelector(".pf-mail-row")?.getAttribute("href")?.match(/messages\/(\d+)/)?.[1] ?? null);
    if (!hostile) await sleep(5000);
  }
  if (hostile) {
    const requests = [];
    const listener = (req) => { if (/tracker\.invalid/.test(req.url())) requests.push(req.url()); };
    L.page.on("request", listener);
    await go(L.page, `/admin/mail/${box}/messages/${hostile}?folder=INBOX`);
    await sleep(1500);
    const h = await L.page.evaluate(() => {
      const frame = document.querySelector(".pf-mail-frame");
      const doc = frame?.contentDocument;
      return {
        sandbox: frame?.getAttribute("sandbox"),
        injected: window.__mcInjected ?? null,
        frameInjected: frame?.contentWindow?.__mcInjected ?? null,
        scripts: doc ? doc.querySelectorAll("script").length : -1,
        handlers: doc ? [...doc.querySelectorAll("*")].filter((el) => [...el.attributes].some((a) => /^on/i.test(a.name))).length : -1,
        iframes: doc ? doc.querySelectorAll("iframe,form,input").length : -1,
        jsLinks: doc ? [...doc.querySelectorAll("a[href]")].filter((a) => /^javascript:/i.test(a.getAttribute("href"))).length : -1,
        text: doc?.body?.textContent?.includes("Hostile") ?? false,
        blocked: Boolean(document.querySelector('a[href*="images=1"]')),
      };
    });
    check("LN1", "hostile HTML from a real message: no script ran, no handler, no frame/form, no javascript: link, text kept, in a scriptless sandbox", h.injected === null && h.frameInjected === null && h.scripts === 0 && h.handlers === 0 && h.iframes === 0 && h.jsLinks === 0 && h.text && h.sandbox === "allow-same-origin allow-popups allow-popups-to-escape-sandbox", JSON.stringify(h));
    check("LP1", "no request to the tracking host before 'Show images'", requests.length === 0 && h.blocked, JSON.stringify(requests));
    evidence.screenshots.push(await capture(L.page, "13-local-hostile-message"));
    await go(L.page, `/admin/mail/${box}/messages/${hostile}?folder=INBOX&images=1`);
    await sleep(2500);
    check("LP2", "after 'Show images' the remote image is requested (then fails: .invalid)", requests.length >= 1, JSON.stringify(requests));
    L.page.off("request", listener);
  } else {
    check("LN1", "the hostile sample arrived", false, "not found within 2 minutes");
  }

  // L-Q: a line break smuggled into To past the browser is refused; nothing sent.
  await go(L.page, `/admin/mail/${box}/compose`);
  await L.page.evaluate(() => { const t = document.querySelector("#field-to"); t.type = "hidden"; t.value = "a@example.org\r\nBcc: x@example.org"; });
  await fill(L.page, "#field-subject", `[MC QA ${TAG}] injection`);
  await fill(L.page, "#field-body", "x");
  await submitForm(L.page, "form[data-mail-send-form]");
  const injected = await flash(L.page);
  check("LQ1", "a CR/LF in To is refused and nothing is sent", injected.errors.length > 0 || injected.danger.length > 0, JSON.stringify(injected));
}
