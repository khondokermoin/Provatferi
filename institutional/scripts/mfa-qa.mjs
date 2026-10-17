// Real-Chrome acceptance of two-step verification on the admin panel (admin-erp/docs/MFA_SECURITY.md). It drives the REAL
// screens with throw-away accounts: a privileged QA account whose QA role REQUIRES two-step verification, and an optional
// QA account. The owner's login is used only to create those roles and accounts and to switch the QA role's requirement
// on: the owner's own account is never enrolled, never asked for a code and never signed out (a sign-out would rotate the
// owner's remember-me token; the kit deletes this browser's session instead).
//
//   node scripts/mfa-qa.mjs --admin https://admin.provatferi.org --env <file with <PREFIX>_EMAIL and <PREFIX>_PASSWORD> \
//        --prefix PROD_QA_ADMIN --out <evidence dir> [--tag <6 hex>] [--mail mailtm|none] [--wait-reset <minutes>]
//
// SECRETS. The authenticator secret is read from the set-up page into this process's memory only; codes are computed from
// it here (RFC 6238, HMAC-SHA1) exactly as a phone does. Recovery codes, passwords, API tokens and inbox passwords also live
// in memory only. Nothing secret is printed, written to the report, or visible in a screenshot (the QR code, the key, the
// recovery codes and every typed field are masked before each capture).
//
// MAIL. With --mail mailtm (default) the QA accounts get disposable mail.tm inboxes: the password-reset link is followed
// from there, and every security e-mail is checked (From no-reply@, Reply-To security@, no secret inside). The inboxes are
// deleted at the end.
//
// BREAK-GLASS. With --wait-reset N it ends by printing WAITING_FOR_RESET <address> and waiting up to N minutes for the reset
// e-mail that `deploy/qa/mfa-qa.php reset-qa <address>` (run on the server) sends, then checks the account must enrol again.
//
// Cleanup of the server side (accounts, roles, audit rows, sessions) is `deploy/qa/mfa-qa.php purge <tag> go`.
import { createRequire } from "node:module";
import { existsSync, mkdirSync, readFileSync, writeFileSync } from "node:fs";
import { dirname, join } from "node:path";
import { createHmac, randomBytes } from "node:crypto";
import { execFileSync } from "node:child_process";
import { fileURLToPath } from "node:url";

const arg = (name, fallback) => {
  const i = process.argv.indexOf(`--${name}`);
  return i > -1 ? process.argv[i + 1] : fallback;
};
const ADMIN = (arg("admin") ?? "").replace(/\/+$/, "");
const OUT = arg("out");
const ENV_FILE = arg("env");
const PREFIX = arg("prefix");
const TAG = (arg("tag") ?? randomBytes(3).toString("hex")).toLowerCase();
const MAIL = arg("mail", "mailtm");
const WAIT_RESET = Number(arg("wait-reset", "0"));
const SERVER_SKEW = Number(arg("server-skew", "0"));
if (!ADMIN || !OUT || !ENV_FILE || !PREFIX || !/^[a-f0-9]{6}$/.test(TAG) || !["mailtm", "none"].includes(MAIL)) {
  console.error("usage: --admin <url> --env <file> --prefix <KEY_PREFIX> --out <dir> [--tag <6 hex>] [--mail mailtm|none] [--wait-reset <min>]");
  process.exit(2);
}
mkdirSync(OUT, { recursive: true });

const require = createRequire(import.meta.url);
const puppeteer = require("puppeteer-core");
const env = Object.fromEntries(readFileSync(ENV_FILE, "utf8").split(/\r?\n/).filter((l) => l.includes("=")).map((l) => [l.slice(0, l.indexOf("=")).trim(), l.slice(l.indexOf("=") + 1).trim()]));
const OWNER_EMAIL = env[`${PREFIX}_EMAIL`];
const OWNER_PASSWORD = env[`${PREFIX}_PASSWORD`];
if (!OWNER_EMAIL || !OWNER_PASSWORD) {
  console.error(`the env file lacks ${PREFIX}_EMAIL / ${PREFIX}_PASSWORD`);
  process.exit(2);
}
const chrome = [process.env.CHROME_PATH, "C:/Program Files/Google/Chrome/Application/chrome.exe", "C:/Program Files (x86)/Google/Chrome/Application/chrome.exe", "/usr/bin/google-chrome", "/usr/bin/chromium"].filter(Boolean).find(existsSync);
const HERE = dirname(fileURLToPath(import.meta.url));

const MARK = `QA-MFA-${TAG}`;
const UA_MARK = `provatferi-mfa-qa/${TAG}`;
const NO_SHOT = process.argv.includes("--no-shots");

// Every expected e-mail subject, both languages, read from the admin's own language files (no copy to drift).
const SUBJECTS = (() => {
  const php = (locale) => JSON.parse(execFileSync("php", ["-r", `echo json_encode((require '${join(HERE, "..", "..", "admin-erp", "lang", locale, "admin.php").replace(/\\/g, "/")}')['mfa']['mail']);`], { encoding: "utf8" }).replace(/^[^{]*/, ""));
  const bn = php("bn");
  const en = php("en");
  const of = (event) => [bn[`subject_${event}`], en[`subject_${event}`]];
  return { enabled: of("enabled"), replaced: of("replaced"), disabled: of("disabled"), codes_regenerated: of("codes_regenerated"), code_used: of("code_used"), reset: of("reset") };
})();

const checks = [];
const notes = [];
const problems = [];
const secrets = new Set(); // everything that must never appear in the report, a screenshot's page text or an e-mail
const check = (id, description, ok, detail = "") => {
  let text = String(detail);
  for (const s of secrets) {
    if (s && s.length >= 6) text = text.split(s).join("[secret]");
  }
  checks.push({ id, description, ok: Boolean(ok), detail: text.slice(0, 400) });
  console.log(`${ok ? "PASS" : "FAIL"}  ${id.padEnd(5)} ${description}${ok || !text ? "" : `  -> ${text.slice(0, 240)}`}`);
};
const note = (text) => {
  notes.push(text);
  console.log(`NOTE  ${text}`);
};
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const fileSafe = (s) => s.replace(/[^a-z0-9-]+/gi, "-");
const strongPassword = () => {
  const p = `Qa!${randomBytes(9).toString("hex")}Zz9`;
  secrets.add(p);
  return p;
};

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

// The server's clock, from Cloudflare's (the kit measured the server against it: --server-skew).
let clockOffset = 0;
async function calibrateClock() {
  const t0 = Date.now();
  const r = await fetch(`${ADMIN}/cdn-cgi/trace`).catch(() => null);
  const t1 = Date.now();
  const text = r && r.ok ? await r.text() : "";
  const m = /^ts=([0-9.]+)$/m.exec(text);
  if (m) {
    clockOffset = Number(m[1]) - (t0 + t1) / 2000 + SERVER_SKEW;
    note(`clock: this machine is ${Math.abs(clockOffset).toFixed(1)} s ${clockOffset < 0 ? "ahead of" : "behind"} the server (Cloudflare reference + skew ${SERVER_SKEW} s)`);
  } else {
    clockOffset = SERVER_SKEW;
    note("clock: no Cloudflare reference (local run?): this machine's clock is used");
  }
}
const serverNow = () => Date.now() / 1000 + clockOffset;
const serverStep = () => Math.floor(serverNow() / 30);

/** The code an authenticator shows now, never one of a step this account already used (a person waits for the next). */
async function freshCode(account) {
  for (;;) {
    const into = serverNow() % 30;
    const step = serverStep();
    if (step > account.lastStep && into < 25) {
      account.lastStep = step;
      const code = totp(account.secret, step);
      account.used.push(code);
      return code;
    }
    await sleep((30 - into + 0.6) * 1000);
  }
}

// ------------------------------------------------------------------------------------------------------------ inbox
const MAILTM = "https://api.mail.tm";
async function mailtm(path, { method = "GET", token, body } = {}) {
  for (let attempt = 0; attempt < 6; attempt++) {
    const r = await fetch(MAILTM + path, {
      method,
      headers: { Accept: "application/json", "Content-Type": "application/json", ...(token ? { Authorization: `Bearer ${token}` } : {}) },
      body: body ? JSON.stringify(body) : undefined,
    });
    if (r.status === 429) {
      await sleep(2500);
      continue;
    }
    const text = await r.text();
    let json = null;
    try { json = JSON.parse(text); } catch { /* not JSON */ }
    return { status: r.status, json, text };
  }
  return { status: 429, json: null, text: "" };
}
async function makeInbox(local) {
  if (MAIL === "none") return { address: `${local}@example.test`, none: true };
  const domains = await mailtm("/domains");
  const list = Array.isArray(domains.json) ? domains.json : domains.json?.["hydra:member"] ?? [];
  const domain = list.find((d) => d.isActive !== false)?.domain;
  if (!domain) throw new Error("mail.tm offers no domain");
  const address = `${local}@${domain}`;
  const password = randomBytes(12).toString("hex");
  secrets.add(password);
  const account = await mailtm("/accounts", { method: "POST", body: { address, password } });
  if (account.status !== 201) throw new Error(`mail.tm account: ${account.status}`);
  const token = await mailtm("/token", { method: "POST", body: { address, password } });
  secrets.add(token.json?.token);
  return { address, id: account.json.id, token: token.json?.token };
}
async function inboxMessages(inbox) {
  if (inbox.none) return [];
  const r = await mailtm("/messages", { token: inbox.token });
  return Array.isArray(r.json) ? r.json : r.json?.["hydra:member"] ?? [];
}
async function rawMessage(inbox, id) {
  const r = await fetch(`${MAILTM}/messages/${id}/download`, { headers: { Authorization: `Bearer ${inbox.token}` } });
  return r.text();
}
async function waitForMail(inbox, predicate, timeoutMs) {
  const until = Date.now() + timeoutMs;
  while (Date.now() < until) {
    const found = (await inboxMessages(inbox)).find(predicate);
    if (found) return found;
    await sleep(5000);
  }
  return null;
}
/** The message source with quoted-printable undone (so a split token cannot hide from the search). */
function decodedSource(raw) {
  const unfolded = raw.replace(/=\r?\n/g, "");
  const qp = unfolded.replace(/=([0-9A-F]{2})/g, (_, h) => String.fromCharCode(parseInt(h, 16)));
  const b64 = [...raw.matchAll(/Content-Transfer-Encoding: base64\r?\n(?:[^\r\n]+\r?\n)*\r?\n([A-Za-z0-9+/=\r\n]+)/gi)].map((m) => Buffer.from(m[1].replace(/\s+/g, ""), "base64").toString("utf8")).join("\n");
  return `${raw}\n${Buffer.from(qp, "latin1").toString("utf8")}\n${b64}`;
}
const header = (raw, name) => (new RegExp(`^${name}:\\s*([^\\r\\n]*(?:\\r?\\n[ \\t][^\\r\\n]*)*)`, "mi").exec(raw)?.[1] ?? "").replace(/\r?\n[ \t]+/g, " ");

// ---------------------------------------------------------------------------------------------------------- browser
const browser = await puppeteer.launch({ executablePath: chrome, headless: true, args: ["--no-sandbox", "--hide-scrollbars"] });
const BASE_UA = (await browser.userAgent()).replace("HeadlessChrome", "Chrome");
const UA = `${BASE_UA} ${UA_MARK}`;

async function openBrowser(label, { width = 1366, height = 900 } = {}) {
  const context = await browser.createBrowserContext();
  const page = await context.newPage();
  await page.setUserAgent(UA);
  await page.setViewport({ width, height });
  page.on("dialog", (d) => d.accept().catch(() => {}));
  page.on("pageerror", (e) => problems.push(`${label} pageerror: ${String(e).slice(0, 160)}`));
  page.on("console", (m) => { if (m.type() === "error") problems.push(`${label} console: ${m.text().slice(0, 160)}`); });
  return { context, page };
}
const pathOf = (page) => new URL(page.url()).pathname;
async function go(page, path) {
  await page.goto(ADMIN + path, { waitUntil: "networkidle2", timeout: 90000 });
  return pathOf(page);
}
async function clickAndWait(page, selector) {
  await Promise.all([page.waitForNavigation({ waitUntil: "networkidle2", timeout: 90000 }), page.click(selector)]);
  return pathOf(page);
}
async function fill(page, selector, value) {
  await page.$eval(selector, (el) => { el.value = ""; });
  await page.type(selector, value, { delay: 1 });
}
async function screenState(page) {
  return page.evaluate(() => {
    const text = (sel) => [...document.querySelectorAll(sel)].map((e) => e.textContent.replace(/\s+/g, " ").trim()).filter(Boolean);
    return {
      path: location.pathname,
      lang: document.documentElement.lang,
      success: text(".alert-success"),
      warning: text(".alert-warning"),
      danger: text(".alert-danger"),
      errors: text(".invalid-feedback"),
      status: document.querySelector("[data-mfa-status]")?.getAttribute("data-mfa-status") ?? null,
      required: document.querySelector("[data-mfa-required]")?.getAttribute("data-mfa-required") ?? null,
      codesLeft: document.querySelector("[data-mfa-codes-left]")?.getAttribute("data-mfa-codes-left") ?? null,
    };
  });
}
/** No secret in what the page holds (DOM text and attributes). */
async function pageHolds(page, needles) {
  const html = await page.content();
  return needles.filter((n) => n && html.includes(n));
}

const MASK_CSS = `
.pf-mfa-qr svg{visibility:hidden!important}
.pf-mfa-qr,#mfa-manual-key,#mfa-recovery-codes li,.pf-mfa-code-item{background:repeating-linear-gradient(45deg,#8a8f98 0 6px,#b9bdc4 6px 12px)!important;color:transparent!important;text-shadow:none!important}
input[name=code],input[name=recovery_code],input[type=password],input[name=email]{color:transparent!important;text-shadow:none!important}`;
/** The file name records what the capture really shows: language, theme and size. */
async function capture(page, name) {
  await page.addStyleTag({ content: MASK_CSS });
  await sleep(150);
  const facts = await page.evaluate(() => ({ lang: document.documentElement.lang || "x", theme: document.documentElement.getAttribute("data-bs-theme") || "x", width: window.innerWidth }));
  const file = `${fileSafe(name)}-${facts.lang}-${facts.theme}-${facts.width <= 500 ? "phone" : "desktop"}.png`;
  await page.screenshot({ path: join(OUT, file), fullPage: true });
}
async function shot(page, name, { width, height, dark } = {}) {
  if (NO_SHOT) return;
  if (width) await page.setViewport({ width, height: height ?? 900 });
  await page.emulateMediaFeatures([{ name: "prefers-color-scheme", value: dark ? "dark" : "light" }]);
  await page.reload({ waitUntil: "networkidle2", timeout: 90000 }).catch(() => {});
  await capture(page, name);
}
/** Screenshot without reloading (for a page that cannot be loaded twice: the recovery codes shown once). */
async function shotAsIs(page, name) {
  if (NO_SHOT) return;
  await capture(page, name);
}
async function setLanguage(page, locale) {
  const form = `form[action$="/locale"] input[name="locale"][value="${locale}"]`;
  const has = await page.$(form);
  if (!has) return false;
  await Promise.all([page.waitForNavigation({ waitUntil: "networkidle2", timeout: 90000 }), page.evaluate((sel) => document.querySelector(sel).closest("form").submit(), form)]);
  return true;
}

// ------------------------------------------------------------------------------------------------------ admin steps
async function passwordStep(page, email, password, { remember = false } = {}) {
  await go(page, "/login");
  await fill(page, "#email", email);
  await fill(page, "#password", password);
  if (remember) await page.click("#remember_me");
  return clickAndWait(page, 'form[action$="/login"] button[type="submit"]');
}
async function enterCode(page, code) {
  await fill(page, "#code", code);
  return clickAndWait(page, 'form[action$="/two-factor-challenge"] button[type="submit"]');
}
async function enterRecovery(page, code) {
  if (!(await page.$("#recovery_code"))) await go(page, "/two-factor-challenge?method=recovery");
  await fill(page, "#recovery_code", code);
  return clickAndWait(page, 'form[action$="/two-factor-challenge/recovery"] button[type="submit"]');
}
async function confirmPassword(page, password) {
  await fill(page, "#password", password);
  return clickAndWait(page, 'form[action$="/confirm-password"] button[type="submit"]');
}
async function logout(page) {
  const form = await page.$('form[action$="/logout"]');
  if (!form) await go(page, "/admin");
  if (!(await page.$('form[action$="/logout"]'))) return pathOf(page);
  await Promise.all([page.waitForNavigation({ waitUntil: "networkidle2", timeout: 90000 }), page.evaluate(() => document.querySelector('form[action$="/logout"]').submit())]);
  return pathOf(page);
}
/** A same-origin request from inside the page (its cookies, its CSRF token), the way a script on the page would. */
async function pageFetch(page, path, { method = "GET", json = false, body = null } = {}) {
  return page.evaluate(async ({ path, method, json, body }) => {
    const token = document.querySelector('meta[name="csrf-token"]')?.content ?? document.querySelector('input[name="_token"]')?.value ?? "";
    const headers = { Accept: json ? "application/json" : "text/html", "X-Requested-With": json ? "XMLHttpRequest" : "" };
    let payload;
    if (body) {
      payload = new URLSearchParams({ _token: token, ...body });
      headers["Content-Type"] = "application/x-www-form-urlencoded";
    }
    const r = await fetch(path, { method, headers, body: payload, redirect: "manual", credentials: "same-origin" });
    return { status: r.status, type: r.type, cacheControl: r.headers.get("cache-control"), robots: r.headers.get("x-robots-tag") };
  }, { path, method, json, body });
}

/** Finds a QA role on the role list (paginated, ordered by name): its id. */
async function findRoleId(page, name) {
  for (let p = 1; p <= 6; p++) {
    await go(page, `/admin/system/roles?page=${p}`);
    const id = await page.evaluate((name) => {
      for (const row of document.querySelectorAll("tbody tr")) {
        if (row.textContent.includes(name)) {
          const a = row.querySelector('a[href*="/admin/system/roles/"][href$="/edit"]');
          return a ? a.getAttribute("href").match(/roles\/(\d+)\/edit/)[1] : null;
        }
      }
      return null;
    }, name);
    if (id) return id;
  }
  return null;
}
async function roleBadge(page, name) {
  for (let p = 1; p <= 6; p++) {
    await go(page, `/admin/system/roles?page=${p}`);
    const badge = await page.evaluate((name) => {
      for (const row of document.querySelectorAll("tbody tr")) {
        if (row.textContent.includes(name)) return row.querySelectorAll("td")[4]?.textContent.replace(/\s+/g, " ").trim() ?? null;
      }
      return null;
    }, name);
    if (badge !== null) return badge;
  }
  return null;
}
async function createRole(page, name, permissionSlugs) {
  await go(page, "/admin/system/roles/create");
  await fill(page, "#field-name", name);
  await page.evaluate((slugs) => {
    for (const cb of document.querySelectorAll('input[name="permissions[]"]')) {
      const slug = document.querySelector(`label[for="${cb.id}"] code`)?.textContent.trim();
      cb.checked = slugs.includes(slug);
    }
  }, permissionSlugs);
  await clickAndWait(page, 'form[action$="/admin/system/roles"] button[type="submit"]');
  return findRoleId(page, name);
}
async function createUser(page, { name, email, password, roleId }) {
  await go(page, "/admin/system/users/create");
  await fill(page, "#field-name", name);
  await fill(page, "#field-email", email);
  await fill(page, "#field-password", password);
  await fill(page, "#field-password_confirmation", password);
  await page.evaluate((roleId) => {
    for (const cb of document.querySelectorAll('input[name="roles[]"]')) cb.checked = cb.value === String(roleId);
    const status = document.querySelector('select[name="status"]');
    if (status) status.value = "active";
  }, roleId);
  const path = await clickAndWait(page, 'form[action$="/admin/system/users"] button[type="submit"]');
  return /\/admin\/system\/users\/\d+$/.test(path);
}

// ---------------------------------------------------------------------------------------------------------- the run
const started = new Date().toISOString();
await calibrateClock();
let owner;
let P;
let O;
const qa = {};
try {
  // Inboxes and the two QA accounts' credentials (memory only).
  const inboxP = await makeInbox(`qa-mfa-${TAG}-p`);
  const inboxO = await makeInbox(`qa-mfa-${TAG}-o`);
  qa.P = { email: inboxP.address.toLowerCase(), password: strongPassword(), inbox: inboxP, secret: null, lastStep: 0, used: [], codes: [] };
  qa.O = { email: inboxO.address.toLowerCase(), password: strongPassword(), inbox: inboxO, secret: null, lastStep: 0, used: [], codes: [] };
  note(`QA accounts: ${qa.P.email} (privileged, role requires two-step) and ${qa.O.email} (optional)`);

  // ===================================================================================== the owner sets the stage
  owner = await openBrowser("owner");
  const ownerIn = await passwordStep(owner.page, OWNER_EMAIL, OWNER_PASSWORD);
  check("OWN1", "owner signs in with the password alone (nothing is required after deployment)", ownerIn === "/admin" || ownerIn === "/admin/", ownerIn);
  const ownerSecurity = await go(owner.page, "/admin/account/security");
  const ownerState = await screenState(owner.page);
  check("OWN2", "the owner's own two-step verification is untouched: off and optional", ownerSecurity === "/admin/account/security" && ownerState.status === "off" && ownerState.required === "no", JSON.stringify(ownerState));
  await shot(owner.page, "01-owner-security-page");

  const privilegedRole = `${MARK} Privileged`;
  const optionalRole = `${MARK} Optional`;
  const privilegedRoleId = await createRole(owner.page, privilegedRole, ["settings.view", "settings.update"]);
  const optionalRoleId = await createRole(owner.page, optionalRole, ["activities.view"]);
  check("SET1", "QA roles created through the role screen", Boolean(privilegedRoleId && optionalRoleId), `${privilegedRoleId} / ${optionalRoleId}`);
  const badgeBefore = await roleBadge(owner.page, privilegedRole);
  const badgeOptional = await roleBadge(owner.page, optionalRole);
  check("S12a", "role list: a privileged role without the requirement is marked recommended, a plain one optional", Boolean(badgeBefore && badgeOptional && badgeBefore !== badgeOptional), `${badgeBefore} | ${badgeOptional}`);

  // S12 — the role requires it: switched on through the role screen, which asks the owner (no two-step) for the password.
  await go(owner.page, `/admin/system/roles/${privilegedRoleId}/edit`);
  await shot(owner.page, "02-role-card-before");
  await page$check(owner.page, "#role-mfa-required");
  const toConfirm = await clickAndWait(owner.page, "#role-mfa-card form button[type='submit']");
  check("S12b", "raising a role's protection asks for the password first", toConfirm === "/confirm-password", toConfirm);
  const backToRole = await confirmPassword(owner.page, OWNER_PASSWORD);
  check("S12c", "after the password the admin is back on the role screen", backToRole === `/admin/system/roles/${privilegedRoleId}/edit`, backToRole);
  await page$check(owner.page, "#role-mfa-required");
  await clickAndWait(owner.page, "#role-mfa-card form button[type='submit']");
  const roleOn = await screenState(owner.page);
  const badgeAfter = await roleBadge(owner.page, privilegedRole);
  check("S12d", "the QA role now requires two-step verification", roleOn.success.length > 0 && badgeAfter && badgeAfter !== badgeBefore, `${JSON.stringify(roleOn.success)} | ${badgeAfter}`);
  await go(owner.page, `/admin/system/roles/${privilegedRoleId}/edit`);
  await shot(owner.page, "03-role-card-required");

  const madeP = await createUser(owner.page, { name: `${MARK} P`, email: qa.P.email, password: qa.P.password, roleId: privilegedRoleId });
  const madeO = await createUser(owner.page, { name: `${MARK} O`, email: qa.O.email, password: qa.O.password, roleId: optionalRoleId });
  check("SET2", "QA accounts created through the user screen", madeP && madeO);

  // ============================================================================ P: mandatory enrolment (steps 1-3)
  P = await openBrowser("qa-privileged");
  const pFirst = await passwordStep(P.page, qa.P.email, qa.P.password);
  const pFirstState = await screenState(P.page);
  check("S1", "a required account signs in with its password and lands on the Security page, told to set it up", pFirst === "/admin/account/security" && pFirstState.required === "yes" && pFirstState.status === "off" && pFirstState.warning.length > 0, `${pFirst} ${JSON.stringify(pFirstState)}`);
  await shot(P.page, "04-forced-enrolment");
  await shot(P.page, "05-forced-enrolment", { width: 390, height: 844 });
  await P.page.setViewport({ width: 1366, height: 900 });
  for (const target of ["/admin", "/admin/settings", "/profile"]) {
    const landed = await go(P.page, target);
    check("B", `before enrolling, ${target} sends the account back to the Security page`, landed === "/admin/account/security", landed);
  }
  const jsonBlocked = await pageFetch(P.page, "/admin", { json: true });
  check("B-json", "before enrolling, a JSON request is refused (403)", jsonBlocked.status === 403, JSON.stringify(jsonBlocked));

  const toSetup = await go(P.page, "/admin/account/security/setup");
  check("S2a", "set-up asks for the password first", toSetup === "/confirm-password", toSetup);
  const setupPath = await confirmPassword(P.page, qa.P.password);
  check("S2b", "after the password the set-up page opens", setupPath === "/admin/account/security/setup", setupPath);
  const manualKey = await P.page.$eval("#mfa-manual-key", (e) => e.textContent.replace(/\s+/g, ""));
  qa.P.secret = manualKey;
  secrets.add(manualKey);
  const setupFacts = await P.page.evaluate(() => ({
    svg: Boolean(document.querySelector(".pf-mfa-qr svg")),
    svgLabelled: Boolean(document.querySelector('.pf-mfa-qr [role="img"][aria-label], .pf-mfa-qr[role="img"][aria-label], .pf-mfa-qr svg[aria-label]')),
    externalQr: [...document.querySelectorAll("img,script,iframe")].some((e) => /qrserver|chart\.googleapis|quickchart/.test(e.src || "")),
  }));
  check("S2c", "the set-up page shows a server-drawn QR code and a 32-character manual key", setupFacts.svg && !setupFacts.externalQr && /^[A-Z2-7]{32}$/.test(manualKey), JSON.stringify(setupFacts));
  const setupHeaders = await pageFetch(P.page, "/admin/account/security/setup");
  check("S2d", "the set-up page is never cached or indexed (no-store, noindex)", setupHeaders.status === 200 && /no-store/.test(setupHeaders.cacheControl ?? "") && /noindex/.test(setupHeaders.robots ?? ""), JSON.stringify(setupHeaders));
  await shotAsIs(P.page, "06-setup-masked");
  await P.page.setViewport({ width: 390, height: 844 });
  await shotAsIs(P.page, "07-setup-masked");
  await P.page.setViewport({ width: 1366, height: 900 });

  await fill(P.page, "#mfa-enable-form input[name='code']", "000000");
  await clickAndWait(P.page, "#mfa-enable-form button[type='submit']");
  const wrongEnable = await screenState(P.page);
  check("S2e", "a wrong code does not turn it on", wrongEnable.path === "/admin/account/security/setup" && (wrongEnable.errors.length > 0 || wrongEnable.danger.length > 0), JSON.stringify(wrongEnable));
  const keyAgain = await P.page.$eval("#mfa-manual-key", (e) => e.textContent.replace(/\s+/g, "")).catch(() => null);
  check("S2f", "the same pending secret is offered again after a mistake", keyAgain === manualKey);

  await fill(P.page, "#mfa-enable-form input[name='code']", await freshCode(qa.P));
  await clickAndWait(P.page, "#mfa-enable-form button[type='submit']");
  const shown = await P.page.$$eval("#mfa-recovery-codes li", (lis) => lis.map((li) => li.textContent.replace(/\s+/g, "").trim()));
  const codes = shown.map((c) => (c.match(/[A-HJ-NP-Z2-9]{5}-[A-HJ-NP-Z2-9]{5}/) ?? [null])[0]).filter(Boolean);
  qa.P.codes = codes;
  codes.forEach((c) => secrets.add(c));
  check("S2g", "a correct code turns it on and shows ten recovery codes once", codes.length === 10, `${codes.length} codes`);
  await shotAsIs(P.page, "08-recovery-codes-masked");
  await clickAndWait(P.page, "#mfa-codes-done");
  const enabledState = await screenState(P.page);
  const leakedAfter = await pageHolds(P.page, [manualKey, ...codes]);
  check("S3", "the Security page now says on, ten codes left, and holds neither the secret nor a code", enabledState.status === "on" && enabledState.codesLeft === "10" && leakedAfter.length === 0, JSON.stringify(enabledState));
  const setupAgain = await go(P.page, "/admin/account/security/setup");
  check("T1", "the set-up page does not show the active secret again (it asks for a fresh code first)", setupAgain !== "/admin/account/security/setup" || !(await pageHolds(P.page, [manualKey])).length, setupAgain);

  // ===================================================================== steps 4-7: sign out, the challenge, bypass
  await go(P.page, "/admin/account/security");
  await logout(P.page);
  const pChallenge = await passwordStep(P.page, qa.P.email, qa.P.password);
  check("S5", "after the password the account is NOT signed in: the challenge opens", pChallenge === "/two-factor-challenge", pChallenge);
  await shot(P.page, "09-challenge");
  await shot(P.page, "10-challenge", { width: 390, height: 844, dark: true });
  await setLanguage(P.page, "en");
  await shot(P.page, "11-challenge", { width: 1366, height: 900 });
  await shot(P.page, "12-challenge", { dark: true });
  const challengeA11y = await P.page.evaluate(() => {
    const input = document.querySelector("#code");
    return { lang: document.documentElement.lang, label: Boolean(document.querySelector('label[for="code"]')), inputmode: input?.getAttribute("inputmode"), autocomplete: input?.getAttribute("autocomplete") };
  });
  check("S16a", "the challenge switches to English and keeps its accessible one-time-code field", challengeA11y.lang === "en" && challengeA11y.label && challengeA11y.inputmode === "numeric" && challengeA11y.autocomplete === "one-time-code", JSON.stringify(challengeA11y));
  await P.page.emulateMediaFeatures([{ name: "prefers-color-scheme", value: "light" }]);

  for (const target of ["/admin", "/admin/system/users", "/admin/account/security", "/admin/settings", "/dashboard"]) {
    const landed = await go(P.page, target);
    check("S7", `while the challenge is open, ${target} goes back to the challenge`, landed === "/two-factor-challenge", landed);
  }
  const pendingJson = await pageFetch(P.page, "/admin/system/users", { json: true });
  check("S7-json", "while the challenge is open, a JSON request is 401", pendingJson.status === 401, JSON.stringify(pendingJson));
  const forged = await P.page.evaluate(async () => (await fetch("/admin?mfa_verified=1", { headers: { "X-MFA-Verified": "1" }, redirect: "manual" })).type);
  const afterForge = await go(P.page, "/admin");
  check("S7-forge", "a forged \"verified\" flag (header, query) changes nothing", afterForge === "/two-factor-challenge", `${forged} ${afterForge}`);
  const apiNoCode = await fetch(`${ADMIN}/api/v1/auth/login`, { method: "POST", headers: { Accept: "application/json", "Content-Type": "application/json", "User-Agent": UA }, body: JSON.stringify({ email: qa.P.email, password: qa.P.password }) });
  const apiNoCodeBody = await apiNoCode.json().catch(() => ({}));
  check("S7-api", "the API gives no token for the password alone (401, mfa_required)", apiNoCode.status === 401 && apiNoCodeBody.mfa_required === true && !apiNoCodeBody.data, `${apiNoCode.status} ${JSON.stringify(apiNoCodeBody).slice(0, 120)}`);

  await go(P.page, "/two-factor-challenge");
  await enterCode(P.page, "000000");
  const wrong = await screenState(P.page);
  check("S6a", "a wrong code is refused with a message, nobody signed in", wrong.path === "/two-factor-challenge" && (wrong.errors.length > 0 || wrong.danger.length > 0), JSON.stringify(wrong));
  const signedIn = await enterCode(P.page, await freshCode(qa.P));
  check("S6b", "a current code signs in", signedIn.startsWith("/admin"), signedIn);
  const usedCode = qa.P.used[qa.P.used.length - 1];

  // ======================================================================= step 18: API token (with a code)
  const apiCode = await freshCode(qa.P);
  const apiLogin = await fetch(`${ADMIN}/api/v1/auth/login`, { method: "POST", headers: { Accept: "application/json", "Content-Type": "application/json", "User-Agent": UA }, body: JSON.stringify({ email: qa.P.email, password: qa.P.password, code: apiCode }) });
  const apiBody = await apiLogin.json().catch(() => ({}));
  const token = apiBody?.data?.token;
  if (token) secrets.add(token);
  const me = token ? await fetch(`${ADMIN}/api/v1/auth/me`, { headers: { Accept: "application/json", Authorization: `Bearer ${token}`, "User-Agent": UA } }) : null;
  const meText = me ? await me.text() : "";
  const meLeaks = ["mfa_secret", "mfa_recovery_codes", "mfa_pending_secret", "mfa_last_used_step", qa.P.secret, ...qa.P.codes].filter((n) => n && meText.includes(n));
  check("S18a", "with a current code the API issues a token, and /auth/me carries no two-step material", apiLogin.status === 200 && Boolean(token) && me?.status === 200 && meLeaks.length === 0, `${apiLogin.status} ${me?.status} leaks=${meLeaks.length}`);
  const apiReplay = await fetch(`${ADMIN}/api/v1/auth/login`, { method: "POST", headers: { Accept: "application/json", "Content-Type": "application/json", "User-Agent": UA }, body: JSON.stringify({ email: qa.P.email, password: qa.P.password, code: apiCode }) });
  check("S18b", "the same code cannot buy a second token (422)", apiReplay.status === 422, String(apiReplay.status));
  if (token) {
    const out = await fetch(`${ADMIN}/api/v1/auth/logout`, { method: "POST", headers: { Accept: "application/json", Authorization: `Bearer ${token}`, "User-Agent": UA } });
    check("S18c", "the token is revoked by its own logout", out.status === 200, String(out.status));
  }

  // The Security page (signed in), both languages, both themes, both sizes.
  await go(P.page, "/admin/account/security");
  await shot(P.page, "13-security-on");
  await shot(P.page, "14-security-on", { dark: true });
  await setLanguage(P.page, "bn");
  await shot(P.page, "15-security-on", { width: 390, height: 844, dark: true });
  await shot(P.page, "16-security-on", { width: 390, height: 844 });
  await P.page.setViewport({ width: 1366, height: 900 });
  await P.page.emulateMediaFeatures([{ name: "prefers-color-scheme", value: "light" }]);
  const overflow = await P.page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
  check("S15", "no sideways scrolling on the Security page", overflow <= 1, String(overflow));

  // N — a required account cannot turn it off.
  const locked = await P.page.$('[data-mfa-disable="locked"]');
  const disableForm = await P.page.$("#mfa-disable-form");
  const forcedDisable = await pageFetch(P.page, "/admin/account/security/disable", { method: "POST", body: { password: qa.P.password, code: await freshCode(qa.P) } });
  await go(P.page, "/admin/account/security");
  const afterForced = await screenState(P.page);
  check("S13a", "a required account is offered no \"turn off\", and a forced request is refused", Boolean(locked) && !disableForm && afterForced.status === "on" && afterForced.danger.length > 0, `${JSON.stringify(forcedDisable)} ${JSON.stringify(afterForced)}`);

  // Step-up page exists and works.
  const stepUp = await go(P.page, "/admin/account/security/confirm");
  await shot(P.page, "17-step-up");
  check("I-stepup", "the \"confirm it's you\" page opens for an account with two-step", stepUp === "/admin/account/security/confirm");

  // ================================================================ steps 8-9: recovery code, replay of both kinds
  await go(P.page, "/admin/account/security");
  await logout(P.page);
  await passwordStep(P.page, qa.P.email, qa.P.password);
  await go(P.page, "/two-factor-challenge?method=recovery");
  await shot(P.page, "18-recovery-form", { width: 390, height: 844 });
  await P.page.setViewport({ width: 1366, height: 900 });
  const viaRecovery = await enterRecovery(P.page, qa.P.codes[0].toLowerCase());
  const recoveryState = await screenState(P.page);
  check("S8", "a recovery code signs in once and the page says nine are left", viaRecovery === "/admin/account/security" && recoveryState.codesLeft === "9" && recoveryState.warning.length > 0, JSON.stringify(recoveryState));
  await logout(P.page);

  await passwordStep(P.page, qa.P.email, qa.P.password);
  await enterRecovery(P.page, qa.P.codes[0]);
  const reused = await screenState(P.page);
  await enterRecovery(P.page, "ABCDE-FGHJK");
  const never = await screenState(P.page);
  check("S9a", "the used recovery code is refused with exactly the answer a never-valid one gets", reused.path === "/two-factor-challenge" && JSON.stringify(reused.errors.concat(reused.danger)) === JSON.stringify(never.errors.concat(never.danger)), `${JSON.stringify(reused.errors)} vs ${JSON.stringify(never.errors)}`);
  await go(P.page, "/two-factor-challenge");
  await enterCode(P.page, usedCode);
  const replay = await screenState(P.page);
  check("S9b", "a code that already signed in once is refused", replay.path === "/two-factor-challenge", JSON.stringify(replay));
  const back = await enterCode(P.page, await freshCode(qa.P));
  check("S9c", "the next code works", back.startsWith("/admin"), back);

  // ======================================================================== step 10: new recovery codes
  const toCodes = await go(P.page, "/admin/account/security/recovery-codes");
  check("S10a", "the recovery-code page asks for the password", toCodes === "/confirm-password", toCodes);
  const codesPage = await confirmPassword(P.page, qa.P.password);
  check("S10b", "after the password (and with a recent code) it opens", codesPage === "/admin/account/security/recovery-codes", codesPage);
  await clickAndWait(P.page, "#mfa-regenerate-form button[type='submit']");
  const fresh = (await P.page.$$eval("#mfa-recovery-codes li", (lis) => lis.map((li) => li.textContent.replace(/\s+/g, "").trim()))).map((c) => (c.match(/[A-HJ-NP-Z2-9]{5}-[A-HJ-NP-Z2-9]{5}/) ?? [null])[0]).filter(Boolean);
  fresh.forEach((c) => secrets.add(c));
  check("S10c", "ten new codes, none of them an old one", fresh.length === 10 && fresh.every((c) => !qa.P.codes.includes(c)), `${fresh.length}`);
  await shotAsIs(P.page, "19-new-recovery-codes-masked");
  const oldCodes = qa.P.codes;
  qa.P.codes = fresh;
  await clickAndWait(P.page, "#mfa-codes-done");
  await logout(P.page);
  await passwordStep(P.page, qa.P.email, qa.P.password);
  await enterRecovery(P.page, oldCodes[1]);
  const oldRefused = await screenState(P.page);
  check("S10d", "an old recovery code no longer works", oldRefused.path === "/two-factor-challenge", JSON.stringify(oldRefused));
  await go(P.page, "/two-factor-challenge");
  await enterCode(P.page, await freshCode(qa.P));

  // ===================================================================== step 11: password reset keeps two-step on
  if (MAIL === "mailtm") {
    await logout(P.page);
    await go(P.page, "/forgot-password");
    await fill(P.page, "#email", qa.P.email);
    await clickAndWait(P.page, 'form[action$="/forgot-password"] button[type="submit"]');
    const resetMail = await waitForMail(qa.P.inbox, (m) => /reset/i.test(`${m.subject} ${m.intro}`) || /পাসওয়ার্ড/.test(m.subject), 180000);
    let link = null;
    if (resetMail) {
      const raw = decodedSource(await rawMessage(qa.P.inbox, resetMail.id));
      link = (raw.match(/https?:\/\/[^\s"'<>]+\/reset-password\/[A-Za-z0-9]+\?email=[^\s"'<>]+/) ?? [null])[0];
      if (link) secrets.add(link.match(/reset-password\/([A-Za-z0-9]+)/)[1]);
    }
    check("S11a", "the reset e-mail arrived with its link", Boolean(link), resetMail ? resetMail.subject : "no mail in 3 min");
    if (link) {
      await P.page.goto(link.replace(/&amp;/g, "&"), { waitUntil: "networkidle2", timeout: 90000 });
      const newPassword = strongPassword();
      await fill(P.page, "#password", newPassword);
      await fill(P.page, "#password_confirmation", newPassword);
      const afterReset = await clickAndWait(P.page, 'form[action$="/reset-password"] button[type="submit"]');
      check("S11b", "the password is reset", afterReset === "/login", afterReset);
      qa.P.password = newPassword;
      const resetChallenge = await passwordStep(P.page, qa.P.email, qa.P.password);
      check("S11c", "the new password still leads to the challenge, not into the panel", resetChallenge === "/two-factor-challenge", resetChallenge);
      await enterCode(P.page, await freshCode(qa.P));
      await go(P.page, "/admin/account/security");
      const stillOn = await screenState(P.page);
      check("S11d", "two-step verification and the recovery codes survived the reset", stillOn.status === "on" && stillOn.codesLeft === "10", JSON.stringify(stillOn));
    }
  } else {
    note("S11 skipped: --mail none");
  }

  // ======================================================================== the owner cannot lower the protection
  await go(owner.page, `/admin/system/roles/${privilegedRoleId}/edit`);
  await owner.page.$eval("#role-mfa-required", (cb) => { cb.checked = false; });
  await clickAndWait(owner.page, "#role-mfa-card form button[type='submit']");
  const lowered = await screenState(owner.page);
  const stillRequired = await roleBadge(owner.page, privilegedRole);
  check("S12e", "an administrator without two-step verification cannot lift a role's requirement", lowered.danger.length > 0 && stillRequired === badgeAfter, `${JSON.stringify(lowered.danger)} ${stillRequired}`);

  // ================================================================ O: optional enrolment, then turning it off (M)
  O = await openBrowser("qa-optional");
  const oIn = await passwordStep(O.page, qa.O.email, qa.O.password);
  check("A", "an account without two-step verification signs in with its password alone", oIn === "/admin" || oIn === "/admin/", oIn);
  await go(O.page, "/admin/account/security");
  const oState = await screenState(O.page);
  check("A2", "for an optional account the page says optional and off", oState.required === "no" && oState.status === "off", JSON.stringify(oState));
  await go(O.page, "/admin/account/security/setup");
  await confirmPassword(O.page, qa.O.password);
  qa.O.secret = await O.page.$eval("#mfa-manual-key", (e) => e.textContent.replace(/\s+/g, ""));
  secrets.add(qa.O.secret);
  await fill(O.page, "#mfa-enable-form input[name='code']", await freshCode(qa.O));
  await clickAndWait(O.page, "#mfa-enable-form button[type='submit']");
  qa.O.codes = (await O.page.$$eval("#mfa-recovery-codes li", (lis) => lis.map((li) => li.textContent.replace(/\s+/g, "")))).map((c) => (c.match(/[A-HJ-NP-Z2-9]{5}-[A-HJ-NP-Z2-9]{5}/) ?? [null])[0]).filter(Boolean);
  qa.O.codes.forEach((c) => secrets.add(c));
  await clickAndWait(O.page, "#mfa-codes-done");
  check("O1", "an optional account can turn it on", (await screenState(O.page)).status === "on");

  const disable = async (password, code) => {
    await fill(O.page, "#mfa-disable-form input[name='password']", password);
    await fill(O.page, "#mfa-disable-form input[name='code']", code);
    await clickAndWait(O.page, "#mfa-disable-form button[type='submit']");
    return screenState(O.page);
  };
  const badPassword = await disable("not-the-password", await freshCode(qa.O));
  check("S13b", "turning it off with a wrong password is refused", badPassword.status === "on" && badPassword.errors.length + badPassword.danger.length > 0, JSON.stringify(badPassword));
  const badCode = await disable(qa.O.password, "000000");
  check("S13c", "turning it off with a wrong code is refused", badCode.status === "on" && badCode.errors.length + badCode.danger.length > 0, JSON.stringify(badCode));
  await shotAsIs(O.page, "20-disable-refused");
  const off = await disable(qa.O.password, await freshCode(qa.O));
  check("S13d", "with the password and a current code it is turned off", off.status === "off" && off.success.length > 0, JSON.stringify(off));

  // ===================================================================================== S14: the security e-mails
  if (MAIL === "mailtm") {
    await sleep(20000);
    const expectations = [
      [qa.P, "enabled"], [qa.P, "code_used"], [qa.P, "codes_regenerated"], [qa.O, "enabled"], [qa.O, "disabled"],
    ];
    const needles = [...secrets].filter((s) => s && s.length >= 6);
    const usedCodes = [...qa.P.used, ...qa.O.used];
    for (const [account, event] of expectations) {
      const mail = await waitForMail(account.inbox, (m) => SUBJECTS[event].includes(m.subject), 120000);
      if (!mail) {
        check("S14", `${event} e-mail reached ${account === qa.P ? "P" : "O"}`, false, "not received");
        continue;
      }
      const raw = await rawMessage(account.inbox, mail.id);
      const text = decodedSource(raw);
      const from = header(raw, "From");
      const replyTo = header(raw, "Reply-To");
      const leaks = needles.filter((n) => text.includes(n)).length + usedCodes.filter((c) => new RegExp(`(^|[^0-9])${c}([^0-9]|$)`).test(text.split(/\r?\n\r?\n/).slice(1).join("\n"))).length;
      check("S14", `${event} e-mail: From no-reply@, Reply-To security@, no secret or code inside`, /no-reply@provatferi\.org/i.test(from) && /security@provatferi\.org/i.test(replyTo) && leaks === 0 && !/otpauth/i.test(text), `from=${from} reply-to=${replyTo} leaks=${leaks}`);
    }
  }

  // =================================================================================== step 19: the break-glass reset
  if (WAIT_RESET > 0 && MAIL === "mailtm") {
    await logout(P.page);
    console.log(`WAITING_FOR_RESET ${qa.P.email}`);
    const resetMail = await waitForMail(qa.P.inbox, (m) => SUBJECTS.reset.includes(m.subject), WAIT_RESET * 60000);
    check("S19a", "the break-glass reset e-mail arrived", Boolean(resetMail), resetMail ? resetMail.subject : "timed out");
    if (resetMail) {
      const raw = await rawMessage(qa.P.inbox, resetMail.id);
      check("S19b", "the reset e-mail is a security message without a secret", /security@provatferi\.org/i.test(header(raw, "Reply-To")) && ![...secrets].some((s) => s && s.length >= 6 && decodedSource(raw).includes(s)));
      const afterReset = await passwordStep(P.page, qa.P.email, qa.P.password);
      const resetState = await screenState(P.page);
      check("S19c", "after the reset the password leads straight to set-up again (two-step off, required)", afterReset === "/admin/account/security" && resetState.status === "off" && resetState.required === "yes", `${afterReset} ${JSON.stringify(resetState)}`);
      await shot(P.page, "21-after-break-glass-reset");
      const oldCodeAfterReset = await (async () => {
        await logout(P.page);
        const p = await passwordStep(P.page, qa.P.email, qa.P.password);
        return p;
      })();
      check("S19d", "no challenge any more for the reset account (its old secret is gone)", oldCodeAfterReset === "/admin/account/security", oldCodeAfterReset);
      await logout(P.page);
    }
  } else if (WAIT_RESET > 0) {
    note("S19 skipped: needs --mail mailtm");
  }
} catch (error) {
  check("RUN", "the run finished without an exception", false, String(error?.stack ?? error).slice(0, 400));
} finally {
  // The QA browsers sign out; the owner's browser is simply closed (its session row is removed by the kit's purge).
  for (const session of [P, O]) {
    if (session) {
      await logout(session.page).catch(() => {});
      await session.context.close().catch(() => {});
    }
  }
  if (owner) await owner.context.close().catch(() => {});
  await browser.close().catch(() => {});
  if (MAIL === "mailtm") {
    for (const account of [qa.P, qa.O]) {
      if (account?.inbox?.id) {
        const r = await mailtm(`/accounts/${account.inbox.id}`, { method: "DELETE", token: account.inbox.token });
        note(`inbox ${account.email} deleted: ${r.status === 204}`);
      }
    }
  }

  const report = {
    tool: "institutional/scripts/mfa-qa.mjs", admin: ADMIN, tag: TAG, started, finished: new Date().toISOString(),
    accounts: { privileged: qa.P?.email ?? null, optional: qa.O?.email ?? null },
    passed: checks.filter((c) => c.ok).length, failed: checks.filter((c) => !c.ok).length,
    checks, notes, browser_problems: problems,
  };
  let json = JSON.stringify(report, null, 2);
  for (const s of secrets) {
    if (s && s.length >= 6) json = json.split(s).join("[secret]");
  }
  writeFileSync(join(OUT, "mfa-qa-report.json"), json);
  console.log(`\n${report.passed} passed, ${report.failed} failed — report: ${join(OUT, "mfa-qa-report.json")}`);
  process.exitCode = report.failed === 0 ? 0 : 1;
}

/** Ticks a checkbox (a real click, so the page's own scripts see it). */
async function page$check(page, selector) {
  const checked = await page.$eval(selector, (el) => el.checked);
  if (!checked) await page.click(selector);
}
