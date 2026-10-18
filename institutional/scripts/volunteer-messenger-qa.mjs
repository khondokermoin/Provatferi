// Real-Chrome acceptance of the gated volunteer Messenger group (admin-erp/docs/VOLUNTEER_MESSENGER_GROUP.md): the central
// group's invite is offered ONLY on the confirmation page after an application the system accepted. It drives the REAL admin
// screen (Admin -> Recruitment -> Volunteer Messenger group), the REAL public volunteer page and form, and looks at every public
// surface for the invite. Run it against production or a local pair:
//
//   node scripts/volunteer-messenger-qa.mjs --site https://provatferi.org --admin https://admin.provatferi.org \
//        --slug <posting slug> --notice <notice slug> --email <you@gmail.com> --env <file with <PREFIX>_EMAIL, <PREFIX>_PASSWORD
//        and, for the final step, <PREFIX>_MESSENGER_URL> --prefix PROD_QA_ADMIN --out <evidence dir> [--tag <6 hex>] [--state <file>]
//   node scripts/volunteer-messenger-qa.mjs --phase expiry --state <file> ...   (31+ minutes after the main phase: the old proof)
//
// WHAT IT TOUCHES. Only disposable data: three applications to the given posting (the name starts with QA-VMG-<tag> and the
// address carries "qavmg<tag>", which is how the server-side kit admin-erp/deploy/qa/volunteer-messenger-qa.php finds and
// deletes them afterwards), and the two Messenger settings, which it sets (to made-up links first, then to the real link from
// the env file) through the admin screen exactly as the owner would. A refused application creates nothing. The admin login and
// the real link are read from the env file only and never printed: every output masks the tokens.
//
// Check groups follow the owner's matrix:
//   A notice page  B opportunity page  C apply form  D public APIs  E client bundle (no invite anywhere, with the group CONFIGURED)
//   F failed application shows none    G valid application shows the Join control   H Join/Copy give the canonical link
//   I direct address shows none        J tampered proof shows none   K expired proof shows none (phase expiry)
//   L refresh/reopen works             M switched off hides it       N a changed link propagates   O malicious links refused
//   P English   Q phone layout   R desktop layout   S light and dark   T no console errors   U cache/indexing headers
//   V is the server-side kit's `logs` run, outside this script.
import { createRequire } from "node:module";
import { existsSync, mkdirSync, readFileSync, writeFileSync } from "node:fs";
import { join } from "node:path";
import { createHash, randomBytes } from "node:crypto";

const arg = (name, fallback) => {
  const i = process.argv.indexOf(`--${name}`);
  return i > -1 ? process.argv[i + 1] : fallback;
};
const flag = (name) => process.argv.includes(`--${name}`);
const SITE = (arg("site") ?? "").replace(/\/+$/, "");
const ADMIN = (arg("admin") ?? "").replace(/\/+$/, "");
const API = (arg("api") ?? ADMIN).replace(/\/+$/, "");
const SLUG = arg("slug");
const NOTICE = arg("notice");
const OUT = arg("out");
const ENV_FILE = arg("env");
const PREFIX = arg("prefix");
const PHASE = arg("phase", "main");
const STATE_FILE = arg("state");
const EMAIL_BASE = arg("email");
const SPACING = Number(arg("spacing", 14)) * 1000;
const TAG = (arg("tag") ?? randomBytes(3).toString("hex")).toLowerCase();
const LOCAL = /^https?:\/\/(localhost|127\.0\.0\.1)(:\d+)?$/.test(SITE);
if (!SITE || !ADMIN || !SLUG || !OUT || !/^[a-f0-9]{6}$/.test(TAG) || (PHASE === "main" && (!ENV_FILE || !PREFIX || !EMAIL_BASE)) || (PHASE === "expiry" && !STATE_FILE)) {
  console.error("usage: --site <url> --admin <url> --slug <posting> --out <dir> --email <a@b> --env <file> --prefix <KEY_PREFIX> [--notice <slug>] [--api <url>] [--tag <6 hex>] [--state <file>] [--phase main|expiry] [--nojs] [--no-shots]");
  process.exit(2);
}
mkdirSync(OUT, { recursive: true });
const SHOTS = join(OUT, "shots");
mkdirSync(SHOTS, { recursive: true });

const require = createRequire(import.meta.url);
const puppeteer = require("puppeteer-core");
const env = ENV_FILE
  ? Object.fromEntries(readFileSync(ENV_FILE, "utf8").split(/\r?\n/).filter((l) => l.includes("=")).map((l) => [l.slice(0, l.indexOf("=")).trim(), l.slice(l.indexOf("=") + 1).trim()]))
  : {};
const ADMIN_EMAIL = env[`${PREFIX}_EMAIL`];
const ADMIN_PASSWORD = env[`${PREFIX}_PASSWORD`];
const REAL_URL = env[`${PREFIX}_MESSENGER_URL`] ?? null;
const chrome = [process.env.CHROME_PATH, "C:/Program Files/Google/Chrome/Application/chrome.exe", "C:/Program Files (x86)/Google/Chrome/Application/chrome.exe", "/usr/bin/google-chrome", "/usr/bin/chromium"].filter(Boolean).find(existsSync);
if (!chrome) throw new Error("No Chrome found; set CHROME_PATH");

const UA = "provatferi-messenger-qa/1.0";
const NO_SHOTS = flag("no-shots");
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const digest = (s) => createHash("sha256").update(String(s)).digest("hex").slice(0, 8);

// ---------------------------------------------------------------------------------------------------------- the links
const tokenOf = (url) => /^https:\/\/m\.me\/j\/([A-Za-z0-9_-]{6,100})\/$/.exec(url ?? "")?.[1] ?? null;
const FAKE_1 = `https://m.me/j/QaFake${TAG}Alpha/`;
const FAKE_2 = `https://m.me/j/QaFake${TAG}Bravo/`;
const REAL_PASTED = REAL_URL; // as the owner gave it; the screen normalises it
const realToken = (() => {
  const m = REAL_URL ? /\/j\/([A-Za-z0-9_-]{6,100})/.exec(REAL_URL) : null;
  return m ? m[1] : null;
})();
const REAL_CANONICAL = realToken ? `https://m.me/j/${realToken}/` : null;
/** Every secret string this run knows; anything printed or saved goes through mask(). */
const SECRETS = new Map([["fake-1", tokenOf(FAKE_1)], ["fake-2", tokenOf(FAKE_2)], ["real", realToken], ["password", ADMIN_PASSWORD]].filter(([, v]) => v));
const mask = (text) => {
  let s = String(text);
  for (const [label, value] of SECRETS) s = s.split(value).join(`<${label}>`);
  return s;
};

// ---------------------------------------------------------------------------------------------------------- reporting
const checks = [];
const check = (id, description, ok, detail = "") => {
  const row = { id, description, ok: Boolean(ok), detail: mask(detail).slice(0, 500) };
  checks.push(row);
  console.log(`${row.ok ? "PASS" : "FAIL"}  ${id.padEnd(4)} ${description}${row.ok || !row.detail ? "" : `  -> ${row.detail.slice(0, 260)}`}`);
};
const notes = [];
const note = (text) => { notes.push(mask(text)); console.log(`      ${mask(text)}`); };
const finish = () => {
  const failed = checks.filter((c) => !c.ok);
  writeFileSync(join(OUT, `report-${PHASE}.json`), JSON.stringify({ phase: PHASE, tag: TAG, site: SITE, passed: checks.length - failed.length, failed: failed.length, checks, notes }, null, 2));
  console.log(`\n${checks.length - failed.length}/${checks.length} checks passed${failed.length ? `; FAILED: ${failed.map((c) => c.id).join(", ")}` : ""}`);
  process.exit(failed.length ? 1 : 0);
};

// ---------------------------------------------------------------------------------------------------------- dictionary
/** The words the page is meant to show, read from the site's own dictionaries (this script is plain JS and cannot import .ts). */
const dictionary = (locale) => {
  const source = readFileSync(new URL(`../lib/i18n/${locale}.ts`, import.meta.url), "utf8");
  const read = (block, key) => {
    const start = source.indexOf(`${block}: {`);
    const m = new RegExp(`\\b${key}:\\s*\\n?\\s*"((?:[^"\\\\]|\\\\.)*)"`).exec(source.slice(start));
    return m ? m[1].replace(/\\"/g, '"') : null;
  };
  return {
    receivedHeading: read("applyConfirmation", "receivedHeading"), unverifiedHeading: read("applyConfirmation", "unverifiedHeading"),
    title: read("messengerGroup", "title"), join: read("messengerGroup", "join"), copy: read("messengerGroup", "copy"),
    copied: read("messengerGroup", "copied"), copyFailed: read("messengerGroup", "copyFailed"),
  };
};
const WORDS = { bn: dictionary("bn"), en: dictionary("en") };

// ------------------------------------------------------------------------------------------------------ leak scanning
const ADDRESS_PATTERNS = [
  ["whatsapp group address", /chat\.whatsapp\.com/i],
  ["messenger group address", /\bm\.me\/j\/|messenger\.com\/j\//i],
  ["proof cookie name", /pf_applied/],
  ["proof key", /success_proof/],
];
const MARKUP_PATTERNS = [["group card markup", /data-messenger-(card|join|copy)|class="[^"]*messenger-card/]];
const leaks = (text, { markup = true } = {}) => {
  const hits = [];
  for (const [label, re] of [...ADDRESS_PATTERNS, ...(markup ? MARKUP_PATTERNS : [])]) if (re.test(text)) hits.push(label);
  for (const [label, token] of SECRETS) if (label !== "password" && text.includes(token)) hits.push(`token ${label}`);
  return hits;
};
async function get(url, { rsc = false, method = "GET", body, headers = {} } = {}) {
  const res = await fetch(url, { method, body, redirect: "manual", headers: { "User-Agent": UA, Accept: rsc ? "text/x-component" : "text/html,application/json;q=0.9,*/*;q=0.8", ...(rsc ? { RSC: "1" } : {}), ...headers } });
  return { status: res.status, headers: res.headers, text: await res.text() };
}

// ---------------------------------------------------------------------------------------------------------- browser
const browser = await puppeteer.launch({ executablePath: chrome, headless: true, args: ["--no-sandbox", "--disable-dev-shm-usage", "--hide-scrollbars"] });
const problems = []; // console errors, page errors, failed or 4xx/5xx same-origin loads, across the whole run
function watch(page, label) {
  // The CSRF check below is meant to be answered 419, and the browser logs every refused load as a console error.
  page.on("console", (m) => { if (m.type() === "error" && !/status of 419/.test(m.text())) problems.push(`${label} console: ${mask(m.text()).slice(0, 180)}`); });
  page.on("pageerror", (e) => problems.push(`${label} pageerror: ${mask(String(e)).slice(0, 180)}`));
  // With JavaScript switched off (the no-JS scenario) the browser cancels every script it was about to load: not a fault.
  page.on("requestfailed", (r) => { if (label !== "nojs" && !/net::ERR_ABORTED/.test(r.failure()?.errorText ?? "")) problems.push(`${label} requestfailed: ${r.method()} ${mask(r.url()).slice(0, 120)} ${r.failure()?.errorText ?? ""}`); });
  page.on("response", (res) => {
    const url = res.url();
    // 419 is the answer the CSRF check below is supposed to get.
    if (res.status() >= 400 && res.status() !== 419 && (url.startsWith(SITE) || url.startsWith(ADMIN)) && !/\/api\/recruitment\//.test(url)) problems.push(`${label} HTTP ${res.status()}: ${mask(url).slice(0, 140)}`);
  });
}
async function newContext() {
  const context = await browser.createBrowserContext();
  try { await context.overridePermissions(SITE, ["clipboard-read", "clipboard-write"]); } catch { /* permission names differ by version; the copy check reports it */ }
  return context;
}
const shot = async (page, name) => { if (!NO_SHOTS) await page.screenshot({ path: join(SHOTS, `${name}.png`) }).catch(() => {}); };
const cookieList = async (page) => {
  const cdp = await page.createCDPSession();
  const { cookies } = await cdp.send("Network.getAllCookies");
  await cdp.detach().catch(() => {});
  return cookies;
};
const proofCookies = async (page) => (await cookieList(page)).filter((c) => c.name === "pf_applied");
async function setProofCookie(page, cookie, value) {
  const cdp = await page.createCDPSession();
  const host = new URL(SITE).hostname;
  await cdp.send("Network.setCookie", { name: "pf_applied", value, domain: host, path: cookie.path, httpOnly: true, secure: SITE.startsWith("https://"), sameSite: "Lax", expires: Math.floor(Date.now() / 1000) + 7200 });
  await cdp.detach().catch(() => {});
}

// -------------------------------------------------------------------------------------------------------- admin screen
const MESSENGER_SCREEN = `${ADMIN}/admin/recruitment-messenger`;
async function adminLogin(page) {
  await page.goto(`${ADMIN}/login`, { waitUntil: "networkidle2", timeout: 90000 });
  await page.type("#email", ADMIN_EMAIL, { delay: 1 });
  await page.type("#password", ADMIN_PASSWORD, { delay: 1 });
  await Promise.all([page.waitForNavigation({ waitUntil: "networkidle2", timeout: 90000 }), page.click('form button[type="submit"]')]);
  return !page.url().includes("/login");
}
const screenState = (page) => page.evaluate(() => {
  const text = (sel) => [...document.querySelectorAll(sel)].map((e) => e.textContent.replace(/\s+/g, " ").trim());
  return {
    path: location.pathname, success: text(".alert-success"), danger: text(".alert-danger"), errors: text(".invalid-feedback"),
    stored: document.querySelector("#field-central_messenger_invite_url")?.value ?? null,
    switchOn: document.querySelector("#central_messenger_enabled")?.checked ?? null,
    status: document.querySelector("[data-messenger-status]")?.getAttribute("data-messenger-status") ?? null,
  };
});
async function openScreen(page) {
  await page.goto(MESSENGER_SCREEN, { waitUntil: "networkidle2", timeout: 90000 });
  return screenState(page);
}
/** Fills the form the way a person does (or forges the value, for the hostile cases) and saves it. */
async function saveMessenger(page, { enabled, url, forge = false, shotName = null }) {
  await openScreen(page);
  if (forge) {
    await page.$eval("#field-central_messenger_invite_url", (el, value) => { el.removeAttribute("maxlength"); el.value = value; }, url);
  } else {
    // Emptied first (selecting by triple-click leaves text behind in some builds), then typed like a person would.
    await page.$eval("#field-central_messenger_invite_url", (el) => { el.value = ""; });
    if (url) await page.type("#field-central_messenger_invite_url", url, { delay: 0 });
  }
  const on = await page.$eval("#central_messenger_enabled", (el) => el.checked);
  if (on !== enabled) await page.click("#central_messenger_enabled");
  await Promise.all([page.waitForNavigation({ waitUntil: "networkidle2", timeout: 90000 }), page.click('#messenger-form button[type="submit"]')]);
  const after = await screenState(page);
  if (shotName) await shot(page, shotName); // the screen as the admin sees it right after pressing Save
  const fresh = await openScreen(page); // what is actually stored, not the old input echoed back
  return { after, fresh };
}

// ------------------------------------------------------------------------------------------------------------ the form
const planned = { photo: null };
async function makeFixtures() {
  const page = await browser.newPage();
  const dataUrl = await page.evaluate(() => {
    const c = document.createElement("canvas");
    c.width = c.height = 64;
    const g = c.getContext("2d");
    const grad = g.createLinearGradient(0, 0, 64, 64);
    grad.addColorStop(0, "#c8641e");
    grad.addColorStop(1, "#1d4f6e");
    g.fillStyle = grad;
    g.fillRect(0, 0, 64, 64);
    return c.toDataURL("image/png");
  });
  await page.close();
  planned.photo = join(OUT, "qa-photo.png");
  writeFileSync(planned.photo, Buffer.from(dataUrl.split(",")[1], "base64"));
}
// A hand-written one-page PDF, only used when a posting makes the CV compulsory.
const tinyPdf = () => {
  const objects = ["<< /Type /Catalog /Pages 2 0 R >>", "<< /Type /Pages /Kids [3 0 R] /Count 1 >>", "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 200 200] >>"];
  let out = "%PDF-1.4\n";
  const offsets = [];
  objects.forEach((body, i) => { offsets.push(out.length); out += `${i + 1} 0 obj\n${body}\nendobj\n`; });
  const xref = out.length;
  out += `xref\n0 ${objects.length + 1}\n0000000000 65535 f \n${offsets.map((o) => `${String(o).padStart(10, "0")} 00000 n \n`).join("")}trailer\n<< /Size ${objects.length + 1} /Root 1 0 R >>\nstartxref\n${xref}\n%%EOF\n`;
  return Buffer.from(out, "latin1");
};

const applicantEmail = (suffix) => {
  const [local, domain] = EMAIL_BASE.split("@");
  return `${local}+qavmg${TAG}${suffix}@${domain}`;
};
const fillForm = (v) => {
  const set = (el, value) => {
    const proto = el.tagName === "TEXTAREA" ? HTMLTextAreaElement.prototype : el.tagName === "SELECT" ? HTMLSelectElement.prototype : HTMLInputElement.prototype;
    Object.getOwnPropertyDescriptor(proto, "value").set.call(el, value);
    el.dispatchEvent(new Event("input", { bubbles: true }));
    el.dispatchEvent(new Event("change", { bubbles: true }));
  };
  for (const [id, value] of Object.entries(v.fields)) { const el = document.getElementById(id); if (el) set(el, value); }
  // Anything else the posting makes compulsory gets a harmless value.
  for (const el of document.querySelectorAll("form.volunteer-form [required]")) {
    if (el.type === "file" || el.type === "checkbox" || el.type === "radio" || el.value) continue;
    if (el.tagName === "SELECT") { const option = [...el.options].find((o) => o.value); if (option) set(el, option.value); }
    else set(el, el.type === "url" ? "https://example.org/qa" : el.type === "email" ? v.fields.applicant_email : "QA verification run");
  }
  for (const id of ["accuracy_declaration", "privacy_consent", "contact_consent"]) { const el = document.getElementById(id); if (el && !el.checked) el.click(); }
  const skill = document.querySelector('input[name="skills[]"]');
  if (skill && !skill.checked) skill.click();
};
const fieldsFor = (suffix, { badPhone = false } = {}) => ({
  applicant_name: `QA-VMG-${TAG} ${suffix.toUpperCase()}`,
  applicant_phone: badPhone ? "abc" : "01712345678",
  applicant_email: applicantEmail(suffix),
  district: "Dhaka", current_location: "Mirpur", profession: "QA verification run",
  experience: "QA verification run of the gated Messenger group.", contribution: "QA verification run.",
});
async function openForm(context, locale, label) {
  const page = await context.newPage();
  watch(page, label);
  await page.setViewport({ width: 1440, height: 900 });
  await page.goto(`${SITE}${locale === "en" ? "/en" : ""}/recruitment/${SLUG}/apply`, { waitUntil: "networkidle0", timeout: 90000 });
  await page.waitForFunction(() => {
    const f = document.querySelector("form.volunteer-form");
    return f && Object.keys(f).some((k) => k.startsWith("__reactProps$") || k.startsWith("__reactFiber$"));
  }, { timeout: 45000 });
  return page;
}
/** Fills and submits the real form. Resolves with how it ended and what the browser saw of the answer. */
// The apply endpoint allows 5 posts a minute per IP, and every visitor reaches Laravel through the same Next.js server, so the
// limit is shared with real applicants: this run keeps well under it (3 in any 65 seconds) instead of racing it.
const sentAt = [];
const runStart = Date.now();
async function throttleGate() {
  for (;;) {
    const now = Date.now();
    while (sentAt.length && now - sentAt[0] > 65000) sentAt.shift();
    if (sentAt.length < 3) break;
    note(`holding the next submission ${Math.round((sentAt[0] + 65000 - now) / 1000)} s to stay under the shared limit`);
    await sleep(sentAt[0] + 65000 - now + 250);
  }
  sentAt.push(Date.now());
  note(`submission #${sentAt.length} (in this window) at +${Math.round((Date.now() - runStart) / 1000)} s`);
}
async function submitApplication(context, locale, suffix, label, options = {}) {
  const page = await openForm(context, locale, label);
  await throttleGate();
  const answers = [];
  page.on("response", async (res) => {
    if (res.request().method() === "POST" && /\/api\/recruitment\//.test(res.url())) {
      answers.push({ status: res.status(), body: await res.text().catch(() => ""), cacheControl: res.headers()["cache-control"] ?? null });
    }
  });
  await page.evaluate(fillForm, { fields: fieldsFor(suffix, options) });
  await (await page.$("#photo")).uploadFile(planned.photo);
  if (await page.$("#cv[required]")) {
    const cvPath = join(OUT, "qa-cv.pdf");
    writeFileSync(cvPath, tinyPdf());
    await (await page.$("#cv")).uploadFile(cvPath);
  }
  await page.waitForFunction(() => !!document.querySelector("#photo")?.closest(".form-field")?.querySelector('[role="status"]'), { timeout: 5000, polling: 25 }).catch(() => {});
  await sleep(300);
  await page.click('form.volunteer-form button[type="submit"]');
  const outcome = await page.waitForFunction(() => {
    if (location.pathname.endsWith("/apply/success") && document.querySelector(".apply-success")) return "success";
    if (document.querySelector("form.volunteer-form .form-summary")) return "error";
    return null;
  }, { timeout: 90000, polling: 50 }).then((h) => h.jsonValue()).catch(() => "timeout");
  await sleep(500);
  const errorText = outcome === "error"
    ? await page.evaluate(() => [...document.querySelectorAll("form.volunteer-form .form-summary, form.volunteer-form [role='alert'], form.volunteer-form .field-error")].map((e) => e.innerText.replace(/\s+/g, " ").trim()).join(" | ").slice(0, 300)).catch(() => "")
    : "";
  return { page, outcome, answers, errorText };
}

// ---------------------------------------------------------------------------------------------- the confirmation page
const successUrl = (locale) => `${SITE}${locale === "en" ? "/en" : ""}/recruitment/${SLUG}/apply/success`;
async function readConfirmation(page) {
  return page.evaluate(() => {
    const join = document.querySelector("[data-messenger-join]");
    return {
      url: location.pathname,
      h1: document.querySelector("h1")?.innerText.trim() ?? null,
      badge: document.querySelector(".apply-success-badge")?.innerText.trim() ?? null,
      card: !!document.querySelector("[data-messenger-card]"),
      joinHref: join?.getAttribute("href") ?? null, joinTarget: join?.getAttribute("target") ?? null, joinRel: join?.getAttribute("rel") ?? null,
      joinText: join?.innerText.replace(/\s+/g, " ").trim() ?? null,
      copyText: document.querySelector("[data-messenger-copy]")?.innerText.trim() ?? null,
      status: document.querySelector(".messenger-card-status")?.innerText.trim() ?? null,
      groupLinks: [...document.querySelectorAll("a[href]")].map((a) => a.getAttribute("href")).filter((h) => /m\.me|messenger\.com|whatsapp/i.test(h)),
      robots: document.querySelector('meta[name="robots"]')?.getAttribute("content") ?? null,
      overflow: document.documentElement.scrollWidth - document.documentElement.clientWidth,
      bodyText: document.body.innerText,
    };
  });
}
/** A confirmation page opened with whatever cookies the context holds; returns the page, the state and the main response. */
async function openConfirmation(context, locale, label) {
  const page = await context.newPage();
  watch(page, label);
  await page.setViewport({ width: 1440, height: 900 });
  const response = await page.goto(successUrl(locale), { waitUntil: "networkidle0", timeout: 90000 });
  await page.waitForSelector(".apply-success", { timeout: 30000 });
  return { page, response, state: await readConfirmation(page) };
}
const neutralOk = async (page, state, locale) => {
  const html = await page.content();
  return state.h1 === WORDS[locale].unverifiedHeading && !state.card && state.groupLinks.length === 0 && leaks(html).length === 0;
};
const verifiedOk = (state, locale) => state.h1 === WORDS[locale].receivedHeading && state.card;

/** Contrast of an element's text against what is behind it (alpha composited), WCAG ratio. */
const contrastOf = (sel) => {
  const el = document.querySelector(sel);
  if (!el) return null;
  const parse = (c) => { const m = /rgba?\(([^)]+)\)/.exec(c); if (!m) return null; const p = m[1].split(/[ ,/]+/).filter(Boolean).map(Number); return { r: p[0], g: p[1], b: p[2], a: p[3] === undefined ? 1 : p[3] }; };
  const over = (top, under) => ({ r: top.r * top.a + under.r * (1 - top.a), g: top.g * top.a + under.g * (1 - top.a), b: top.b * top.a + under.b * (1 - top.a), a: 1 });
  let bg = { r: 255, g: 255, b: 255, a: 1 };
  const chain = [];
  for (let n = el; n; n = n.parentElement) chain.push(n);
  for (const n of chain.reverse()) { const c = parse(getComputedStyle(n).backgroundColor); if (c && c.a > 0) bg = over(c, bg); }
  const fg = over(parse(getComputedStyle(el).color) ?? { r: 0, g: 0, b: 0, a: 1 }, bg);
  const lum = ({ r, g, b }) => { const f = (v) => { v /= 255; return v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4; }; return 0.2126 * f(r) + 0.7152 * f(g) + 0.0722 * f(b); };
  const [a, b] = [lum(fg), lum(bg)];
  return Math.round(((Math.max(a, b) + 0.05) / (Math.min(a, b) + 0.05)) * 100) / 100;
};

// ========================================================================================================== EXPIRY PHASE
if (PHASE === "expiry") {
  const state = JSON.parse(readFileSync(STATE_FILE, "utf8"));
  SECRETS.set("proof", state.value);
  const ageMin = Math.round((Date.now() - state.savedAt) / 60000);
  note(`the proof is ${ageMin} minutes old (valid for 30)`);
  check("K0", "the saved proof is older than its 30-minute lifetime", ageMin >= 31, `${ageMin} min`);
  const context = await newContext();
  const bootstrap = await context.newPage();
  // The browser itself drops an expired cookie, so the old value is handed to a new one with a far-off expiry: what is tested is the SERVER.
  await setProofCookie(bootstrap, { path: state.path }, state.value);
  await bootstrap.close();
  const { page, state: seen } = await openConfirmation(context, state.locale, "expiry");
  check("K1", "an expired proof, sent anyway, shows the neutral page: no card, no Join, no Copy, no link", await neutralOk(page, seen, state.locale), JSON.stringify({ h1: seen.h1, card: seen.card, links: seen.groupLinks.length }));
  check("K2", "...and the page's source carries no invite", leaks(await page.content()).length === 0);
  await shot(page, "K-expired-proof-neutral");
  const verdict = await get(`${API}/api/v1/public/recruitment/${encodeURIComponent(SLUG)}/application-success`, { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ proof: state.value }) });
  let parsed = null;
  try { parsed = JSON.parse(verdict.text); } catch { /* reported below */ }
  check("K3", "the verification API itself answers verified:false, messenger:null for the expired proof", verdict.status === 200 && parsed?.data?.verified === false && parsed?.data?.messenger === null, `${verdict.status} ${verdict.text.slice(0, 120)}`);
  check("T", "no console errors, page errors or failed loads in the expiry run", problems.length === 0, problems.slice(0, 4).join(" | "));
  await browser.close();
  finish();
}

// ============================================================================================================= MAIN PHASE
await makeFixtures();
const adminPage = await browser.newPage();
await adminPage.setViewport({ width: 1366, height: 1000 });
adminPage.on("dialog", (d) => d.accept().catch(() => {}));
watch(adminPage, "admin");

check("S0", "the QA admin signs in", await adminLogin(adminPage));
const start = await openScreen(adminPage);
note(`admin screen on arrival: status=${start.status} switch=${start.switchOn} stored=${start.stored ? `link ${digest(tokenOf(start.stored) ?? start.stored)}` : "none"}`);
check("O0", "the admin screen opens for an authorised admin and shows no public-site control", start.path.endsWith("/recruitment-messenger") && start.errors.length === 0, JSON.stringify(start));
await shot(adminPage, "admin-screen-on-arrival");

// ---- with the group CONFIGURED and ON (a made-up link), nothing public may show it ----
const saved1 = await saveMessenger(adminPage, { enabled: true, url: FAKE_1, shotName: "admin-screen-after-save-on" });
check("O1", "the admin saves a Messenger group link and switches it on", saved1.after.success.length === 1 && saved1.fresh.stored === FAKE_1 && saved1.fresh.switchOn === true && saved1.fresh.status === "on", JSON.stringify({ ...saved1.after, stored: mask(saved1.after.stored ?? "") }));
await shot(adminPage, "admin-screen-saved-on");

async function surfaceScan(label) {
  const pages = [];
  for (const prefix of ["", "/en"]) {
    pages.push([`${prefix}/recruitment/${SLUG}`, "opportunity"], [`${prefix}/recruitment/${SLUG}/apply`, "apply form"], [`${prefix}/recruitment`, "opportunity list"], [`${prefix}/notices`, "notice list"]);
    if (NOTICE) pages.push([`${prefix}/notices/${NOTICE}`, "notice"]);
  }
  const chunks = new Set();
  const found = { A: [], B: [], C: [] };
  for (const [path, kind] of pages) {
    for (const rsc of [false, true]) {
      const res = await get(`${SITE}${path}`, { rsc });
      const group2 = kind === "notice" ? "A" : kind === "opportunity" ? "B" : kind === "apply form" ? "C" : null;
      const hits = leaks(res.text);
      if (!rsc) for (const m of res.text.matchAll(/\/_next\/static\/[^"'\s<>\\)]+\.(?:js|css)/g)) chunks.add(m[0]);
      if (hits.length) found[group2 ?? "A"].push(`${path}${rsc ? " (RSC)" : ""}: ${hits.join(", ")}`);
      if (res.status >= 500) found[group2 ?? "A"].push(`${path}${rsc ? " (RSC)" : ""}: HTTP ${res.status}`);
    }
  }
  check(`A${label}`, "the volunteer notice page (Bangla + English, HTML + data payload) carries no group invite, no copy control", NOTICE ? found.A.length === 0 : true, found.A.join(" | ") + (NOTICE ? "" : " (no notice given)"));
  check(`B${label}`, "the recruitment details page (Bangla + English, HTML + data payload) carries none", found.B.length === 0, found.B.join(" | "));
  check(`C${label}`, "the apply form page (Bangla + English, HTML + data payload) and the listings carry none", found.C.length === 0, found.C.join(" | "));

  const apis = [
    ["/api/v1/job-postings", "postings list"], [`/api/v1/job-postings/${encodeURIComponent(SLUG)}`, "posting"], ["/api/v1/public/notices", "notices list"],
    ["/api/v1/public/notices/sitemap", "notice sitemap"], ["/api/v1/settings", "settings"], ["/api/v1/about", "about"],
    ...(NOTICE ? [[`/api/v1/public/notices/${encodeURIComponent(NOTICE)}`, "notice"]] : []),
  ];
  const apiHits = [];
  for (const [path, name] of apis) {
    const res = await get(`${API}${path}`);
    const hits = leaks(res.text, { markup: false });
    if (hits.length) apiHits.push(`${name}: ${hits.join(", ")}`);
    if (name === "posting") { try { const j = JSON.parse(res.text); if (j.data?.notice_action) apiHits.push("posting: notice_action is not null"); } catch { apiHits.push("posting: not JSON"); } }
    if (name === "notice" && NOTICE) { try { const j = JSON.parse(res.text); if (j.data?.recruitment && j.data?.action) apiHits.push("notice: a recruitment-linked notice still has an action"); } catch { apiHits.push("notice: not JSON"); } }
  }
  const noProof = await get(`${API}/api/v1/public/recruitment/${encodeURIComponent(SLUG)}/application-success`, { method: "POST", headers: { "Content-Type": "application/json" }, body: "{}" });
  if (leaks(noProof.text, { markup: false }).length) apiHits.push("verification API without a proof leaks");
  let noProofJson = null;
  try { noProofJson = JSON.parse(noProof.text); } catch { /* checked below */ }
  check(`D${label}`, "every public API (postings, notices, settings, about) is clean, and the verification endpoint with no proof answers verified:false / messenger:null", apiHits.length === 0 && noProofJson?.data?.verified === false && noProofJson?.data?.messenger === null, apiHits.join(" | "));

  const chunkHits = [];
  let scanned = 0;
  for (const path of chunks) {
    const res = await get(`${SITE}${path}`);
    scanned++;
    const hits = leaks(res.text, { markup: false });
    if (hits.length) chunkHits.push(`${path.slice(0, 60)}: ${hits.join(", ")}`);
  }
  check(`E${label}`, `the client bundle (${scanned} script/style files the public pages load) carries no invite, no proof name`, scanned > 5 && chunkHits.length === 0, chunkHits.join(" | "));
  const sitemap = await get(`${SITE}/sitemap.xml`);
  check(`U1${label}`, "the confirmation page is not in the sitemap", !/apply\/success/.test(sitemap.text) && sitemap.status === 200, `status ${sitemap.status}`);
}
await surfaceScan("");

// ---- F: failed applications show nothing ----
await sleep(1000);
{
  const context = await newContext();
  const { page, outcome } = await submitApplication(context, "bn", "f", "failed", { badPhone: true });
  const cookies = await proofCookies(page);
  const stillOnForm = page.url().includes("/apply") && !page.url().includes("/success");
  check("F1", "an application the server refuses (invalid phone) stays on the form with the error shown", outcome === "error" && stillOnForm, `${outcome} ${page.url()}`);
  check("F2", "...and no proof cookie is set", cookies.length === 0, `${cookies.length} cookie(s)`);
  await shot(page, "F-failed-application-form");
  const direct = await openConfirmation(context, "bn", "failed-direct");
  check("F3", "opening the confirmation address right after the refusal shows the neutral page, no invite", await neutralOk(direct.page, direct.state, "bn"), JSON.stringify({ h1: direct.state.h1, card: direct.state.card }));
  await context.close();
}

// ---- G/H/L: a valid application (Bangla, desktop, light) ----
await sleep(SPACING);
const ctx1 = await newContext();
const first = await submitApplication(ctx1, "bn", "a", "apply-bn");
check("G0", "a valid application is accepted and the visitor lands on the confirmation page", first.outcome === "success", `${first.outcome} ${first.errorText}`);
if (first.outcome !== "success") { await browser.close(); finish(); }
const answer = first.answers[0];
const proofBefore = await proofCookies(first.page);
const bnProof = proofBefore.find((c) => c.path === `/recruitment/${SLUG}/apply/success`);
const enProof = proofBefore.find((c) => c.path === `/en/recruitment/${SLUG}/apply/success`);
if (bnProof) SECRETS.set("proof", bnProof.value);
check("G1", "the submit answer the browser reads is only {status:success}: no proof, no application number", answer?.status === 200 && /^\{"status":"success"\}$/.test(answer.body) && answer.cacheControl === "no-store", JSON.stringify({ status: answer?.status, body: mask(answer?.body ?? ""), cc: answer?.cacheControl }));
check("G2", "the proof cookie is HttpOnly, Secure, SameSite=Lax, ~30 minutes, and scoped to the confirmation page of this posting (both languages)",
  Boolean(bnProof && enProof) && [bnProof, enProof].every((c) => c.httpOnly && c.secure && c.sameSite === "Lax"),
  JSON.stringify(proofBefore.map((c) => ({ path: c.path, httpOnly: c.httpOnly, secure: c.secure, sameSite: c.sameSite, hostOnly: c.domain === new URL(SITE).hostname }))));
if (bnProof) {
  const life = bnProof.expires - Date.now() / 1000;
  check("G3", "the cookie lives about 30 minutes", life > 1500 && life <= 1830, `${Math.round(life)} s left`);
}
const conf = first.page;
const s1 = await readConfirmation(conf);
check("G4", "the confirmation page shows the received message and the Messenger group card", verifiedOk(s1, "bn"), JSON.stringify({ h1: s1.h1, card: s1.card }));
check("G5", "the card has the Join control with the localized wording and the Copy control", s1.joinText?.startsWith(WORDS.bn.join) && s1.copyText === WORDS.bn.copy, JSON.stringify({ join: s1.joinText, copy: s1.copyText }));
check("H1", "Join points at the canonical link, opens in a new tab with noopener", s1.joinHref === FAKE_1 && s1.joinTarget === "_blank" && /noopener/.test(s1.joinRel ?? "") && /noreferrer/.test(s1.joinRel ?? ""), `${mask(s1.joinHref)} ${s1.joinTarget} ${s1.joinRel}`);
const html1 = await conf.content();
const rsc1 = await conf.evaluate(() => fetch(location.href, { headers: { RSC: "1" }, cache: "no-store" }).then((r) => r.text()));
const storage1 = await conf.evaluate(() => JSON.stringify({ l: { ...localStorage }, s: { ...sessionStorage }, c: document.cookie }));
check("G6", "the proof is nowhere the browser can read it: not in the page, the data payload, storage or document.cookie", bnProof ? ![html1, rsc1, storage1].some((t) => t.includes(bnProof.value) || t.includes(encodeURIComponent(bnProof.value))) : false);
check("G7", "no name, e-mail, phone or application number is shown on the confirmation", !/QA-VMG|qavmg|01712345678|VOL-\d{4}-\d+/i.test(s1.bodyText + html1));
check("U2", "the confirmation says noindex, nofollow", /noindex/.test(s1.robots ?? "") && /nofollow/.test(s1.robots ?? ""), String(s1.robots));
const mainHeaders = (await conf.goto(successUrl("bn"), { waitUntil: "networkidle0" }))?.headers() ?? {};
check("U3", "the confirmation response is private and not cacheable (no-store) and the CDN did not cache it", /no-store/.test(mainHeaders["cache-control"] ?? "") && /private|no-cache/.test(mainHeaders["cache-control"] ?? "") && !/^hit$/i.test(mainHeaders["cf-cache-status"] ?? ""), JSON.stringify({ cc: mainHeaders["cache-control"], cf: mainHeaders["cf-cache-status"] ?? null }));
const afterReload = await readConfirmation(conf);
check("L1", "refreshing the confirmation page inside the window still shows the card", verifiedOk(afterReload, "bn") && afterReload.joinHref === FAKE_1);
const reopened = await openConfirmation(ctx1, "bn", "reopen");
check("L2", "a second tab in the same browser still shows the card (the proof is a replayable, short-lived credential)", verifiedOk(reopened.state, "bn") && reopened.state.joinHref === FAKE_1);
await reopened.page.close();

// Copy
const copyResult = await conf.evaluate(async () => {
  const button = document.querySelector("[data-messenger-copy]");
  button.click();
  await new Promise((r) => setTimeout(r, 400));
  const status = document.querySelector(".messenger-card-status")?.innerText.trim() ?? null;
  const label = button.innerText.trim();
  let clip = null;
  try { clip = await navigator.clipboard.readText(); } catch (e) { clip = `error:${e?.name}`; }
  return { status, label, clip };
});
check("H2", "Copy puts exactly the canonical link on the clipboard", copyResult.clip === FAKE_1, `clipboard=${mask(String(copyResult.clip))}`);
check("H3", "...and confirms it in words (button label and polite status line)", copyResult.status === WORDS.bn.copied && copyResult.label === WORDS.bn.copied, JSON.stringify(copyResult));
check("H6", "the 'copied' message is readable (contrast at least 4.5:1) in the light theme", (await conf.evaluate(contrastOf, ".messenger-card-status")) >= 4.5, String(await conf.evaluate(contrastOf, ".messenger-card-status")));
await shot(conf, "H-copied-bn-desktop-light");
await sleep(3200);
check("H4", "the copied confirmation goes back to normal by itself", (await readConfirmation(conf)).copyText === WORDS.bn.copy);

// Copy fallback when the clipboard is refused
await conf.evaluate(() => {
  Object.defineProperty(navigator, "clipboard", { value: { writeText: () => Promise.reject(new DOMException("denied", "NotAllowedError")) }, configurable: true });
  document.execCommand = () => false;
});
await conf.click("[data-messenger-copy]");
await sleep(300);
const fallback = await conf.evaluate(() => {
  const field = document.querySelector(".messenger-card-field");
  return { shown: !!field, value: field?.value ?? null, selected: field ? field.selectionStart === 0 && field.selectionEnd === field.value.length : false, readOnly: field?.readOnly ?? null, status: document.querySelector(".messenger-card-status")?.innerText.trim() ?? null };
});
check("H5", "when both clipboard routes are refused the link appears in a read-only field, already selected, with a message", fallback.shown && fallback.value === FAKE_1 && fallback.selected && fallback.readOnly && fallback.status === WORDS.bn.copyFailed, mask(JSON.stringify(fallback)));
await shot(conf, "H-copy-fallback-bn-desktop-light");

// keyboard + contrast + layout, then the themes and viewports on the SAME verified page
await conf.reload({ waitUntil: "networkidle0" });
await conf.waitForSelector("[data-messenger-card]");
let focusOk = false;
for (let i = 0; i < 60 && !focusOk; i++) {
  await conf.keyboard.press("Tab");
  focusOk = await conf.evaluate(() => document.activeElement?.hasAttribute("data-messenger-join"));
}
const joinFocus = await conf.evaluate(() => { const s = getComputedStyle(document.activeElement); return { outline: s.outlineStyle, width: s.outlineWidth, shadow: s.boxShadow }; });
check("R1", "keyboard focus reaches Join and shows a visible focus ring", focusOk && (joinFocus.outline !== "none" || joinFocus.shadow !== "none"), JSON.stringify(joinFocus));
await conf.keyboard.press("Tab");
check("R2", "...then Copy", await conf.evaluate(() => document.activeElement?.hasAttribute("data-messenger-copy")));

const variants = [
  { name: "desktop-light", viewport: { width: 1440, height: 900 }, scheme: "light", group: "R" },
  { name: "desktop-dark", viewport: { width: 1440, height: 900 }, scheme: "dark", group: "S" },
  { name: "mobile-light", viewport: { width: 390, height: 844, deviceScaleFactor: 2, isMobile: true, hasTouch: true }, scheme: "light", group: "Q" },
  { name: "mobile-dark", viewport: { width: 390, height: 844, deviceScaleFactor: 2, isMobile: true, hasTouch: true }, scheme: "dark", group: "Q" },
];
for (const v of variants) {
  await conf.setViewport(v.viewport);
  await conf.emulateMediaFeatures([{ name: "prefers-color-scheme", value: v.scheme }]);
  await conf.reload({ waitUntil: "networkidle0" });
  await conf.waitForSelector("[data-messenger-card]");
  await sleep(250);
  const layout = await conf.evaluate(() => {
    const box = (sel) => { const r = document.querySelector(sel)?.getBoundingClientRect(); return r ? { x: Math.round(r.x), y: Math.round(r.y), w: Math.round(r.width), h: Math.round(r.height), r: Math.round(r.right) } : null; };
    return { card: box("[data-messenger-card]"), join: box("[data-messenger-join]"), copy: box("[data-messenger-copy]"), vw: innerWidth, overflow: document.documentElement.scrollWidth - document.documentElement.clientWidth, dark: getComputedStyle(document.body).backgroundColor };
  });
  const fits = layout.overflow <= 0 && layout.card && layout.card.x >= 0 && layout.card.r <= layout.vw && layout.join.r <= layout.vw && layout.copy.r <= layout.vw;
  const tall = v.viewport.isMobile ? layout.join.h >= 44 && layout.copy.h >= 40 : layout.join.h >= 36;
  check(`${v.group}-${v.name}`, `${v.name}: the card fits the screen, no sideways scroll, controls are comfortably tall`, fits && tall, JSON.stringify(layout));
  const type = await conf.evaluate(() => {
    const size = (el) => (el ? getComputedStyle(el).fontSize : null);
    const join = document.querySelector("[data-messenger-join]");
    const copy = document.querySelector("[data-messenger-copy]");
    return { join: size(join), copy: size(copy?.querySelector("span") ?? copy), copyButton: size(copy) };
  });
  check(`${v.group}-${v.name}-type`, `${v.name}: the Join and Copy buttons use the same text size`, type.join === type.copy && type.copy === type.copyButton, JSON.stringify(type));
  if (v.group === "S" || v.scheme === "dark") {
    const ratios = {};
    for (const sel of [".apply-success h1", ".messenger-card h2", ".messenger-card-text", ".messenger-join", ".messenger-copy", ".apply-success-badge"]) ratios[sel] = await conf.evaluate(contrastOf, sel);
    check(`S-${v.name}-contrast`, `${v.name}: text contrast is at least 4.5:1 for the heading, card, buttons and badge`, Object.values(ratios).every((r) => r !== null && r >= 4.5), JSON.stringify(ratios));
  }
  if (v.scheme === "light" && v.group === "R") {
    const ratios = {};
    for (const sel of [".apply-success h1", ".messenger-card h2", ".messenger-card-text", ".messenger-join", ".messenger-copy", ".apply-success-badge"]) ratios[sel] = await conf.evaluate(contrastOf, sel);
    check("S-desktop-light-contrast", "desktop-light: text contrast is at least 4.5:1 for the heading, card, buttons and badge", Object.values(ratios).every((r) => r !== null && r >= 4.5), JSON.stringify(ratios));
  }
  await shot(conf, `confirmation-bn-${v.name}`);
}
await conf.setViewport({ width: 1440, height: 900 });
await conf.emulateMediaFeatures([{ name: "prefers-color-scheme", value: "light" }]);

// ---- I / J: strangers and tampering ----
{
  const stranger = await newContext();
  const direct = await openConfirmation(stranger, "bn", "stranger");
  check("I1", "the confirmation address typed into a fresh browser shows the neutral page: no Join, no Copy, no link", await neutralOk(direct.page, direct.state, "bn"), JSON.stringify({ h1: direct.state.h1, card: direct.state.card, links: direct.state.groupLinks }));
  const html = await direct.page.content();
  const rsc = await direct.page.evaluate(() => fetch(location.href, { headers: { RSC: "1" }, cache: "no-store" }).then((r) => r.text()));
  check("I2", "...and neither its source nor its data payload holds any invite", leaks(html).length === 0 && leaks(rsc).length === 0, [...leaks(html), ...leaks(rsc)].join(", "));
  const dh = direct.response?.headers() ?? {};
  check("U4", "the neutral response is private and not cacheable either", /no-store/.test(dh["cache-control"] ?? "") && !/^hit$/i.test(dh["cf-cache-status"] ?? ""), JSON.stringify({ cc: dh["cache-control"], cf: dh["cf-cache-status"] ?? null }));
  await shot(direct.page, "I-direct-address-neutral-bn-desktop");
  await direct.page.setViewport({ width: 390, height: 844, deviceScaleFactor: 2, isMobile: true, hasTouch: true });
  await direct.page.emulateMediaFeatures([{ name: "prefers-color-scheme", value: "dark" }]);
  await direct.page.reload({ waitUntil: "networkidle0" });
  await shot(direct.page, "I-direct-address-neutral-bn-mobile-dark");
  const fits = await direct.page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
  check("I3", "the neutral page fits a phone screen in dark mode", fits <= 0, `overflow ${fits}`);
  await stranger.close();

  // Tampered proofs
  const original = bnProof.value;
  const flip = (s, at) => s.slice(0, at) + (s[at] === "A" ? "B" : "A") + s.slice(at + 1);
  const variantsOfProof = [
    ["one character changed in the middle", flip(original, Math.floor(original.length / 2))],
    // Not the very last character: base64 padding and the unused trailing bits can be rewritten without changing a single byte
    // underneath, which is an equivalent spelling of the SAME credential, not a forgery.
    ["a character near the end changed", flip(original, original.length - 12)],
    ["truncated", original.slice(0, original.length - 12)],
    ["extended", `${original}AAAA`],
    ["random text of the same length", randomBytes(Math.ceil(original.length)).toString("base64").slice(0, original.length)],
  ];
  const outcomes = [];
  for (const [label, value] of variantsOfProof) {
    const context = await newContext();
    const boot = await context.newPage();
    await setProofCookie(boot, bnProof, value);
    await boot.close();
    const seen = await openConfirmation(context, "bn", "tampered");
    outcomes.push([label, await neutralOk(seen.page, seen.state, "bn")]);
    await context.close();
  }
  check("J1", "a tampered, truncated, extended or invented proof shows the neutral page every time", outcomes.every(([, ok]) => ok), JSON.stringify(outcomes));
  // A proof for THIS posting presented on another posting's address cannot be tried on a real second posting here; the unit tests cover it.
}

// ---- M / N: switching it off, changing the link ----
{
  const off = await saveMessenger(adminPage, { enabled: false, url: FAKE_1 });
  check("M1", "the admin switches the group off (the link stays saved)", off.after.success.length === 1 && off.fresh.switchOn === false && off.fresh.stored === FAKE_1 && off.fresh.status === "off", JSON.stringify({ ...off.after, stored: mask(off.after.stored ?? "") }));
  await shot(adminPage, "admin-screen-saved-off");
  const t0 = Date.now();
  await conf.goto(successUrl("bn"), { waitUntil: "networkidle0" });
  const hidden = await readConfirmation(conf);
  check("M2", "with the group off, a verified applicant still sees the received message but no card, no link", hidden.h1 === WORDS.bn.receivedHeading && !hidden.card && hidden.groupLinks.length === 0 && leaks(await conf.content()).length === 0, JSON.stringify({ h1: hidden.h1, card: hidden.card }));
  note(`switch-off took effect on the next load (${Date.now() - t0} ms for that load)`);
  await shot(conf, "M-group-off-confirmation-bn");

  const on2 = await saveMessenger(adminPage, { enabled: true, url: FAKE_2 });
  check("N1", "the admin switches it on with a different link", on2.fresh.stored === FAKE_2 && on2.fresh.switchOn === true, mask(JSON.stringify(on2.fresh)));
  const t1 = Date.now();
  await conf.goto(successUrl("bn"), { waitUntil: "networkidle0" });
  const changed = await readConfirmation(conf);
  check("N2", "the open confirmation shows the NEW link on its next load, and the old one is nowhere on the page", changed.joinHref === FAKE_2 && !(await conf.content()).includes(tokenOf(FAKE_1)), `${mask(changed.joinHref)} (${Date.now() - t1} ms)`);
  const back = await saveMessenger(adminPage, { enabled: true, url: FAKE_1 });
  check("N3", "putting the first link back works the same way", back.fresh.stored === FAKE_1);
}

// ---- O: hostile links refused, nothing stored ----
{
  const before = await openScreen(adminPage);
  const hostile = [
    "javascript:alert(1)", "data:text/html,<script>alert(1)</script>", "http://m.me/j/AbCdEf1234/", "https://evil.example/j/AbCdEf1234/",
    "https://m.me.evil.example/j/AbCdEf1234/", "https://m.me@evil.example/j/AbCdEf1234/", "https://evil.example/m.me/j/AbCdEf1234/",
    '<iframe src="https://m.me/j/AbCdEf1234/"></iframe>', 'https://m.me/j/AbCdEf1234/"><script>alert(1)</script>', "https://m.me/j/short/",
    "https://www.messenger.com/t/1234567890/", "https://m.me/AbCdEf1234", "https://chat.whatsapp.com/AbCdEf1234567890", "https://m.me/j/AbCdEf1234/ extra", `https://m.me/j/${"a".repeat(600)}/`,
  ];
  const results = [];
  for (const link of hostile) {
    const r = await saveMessenger(adminPage, { enabled: true, url: link, forge: true, shotName: link.startsWith("javascript:") ? "admin-screen-refusal-javascript-link" : null });
    results.push({ link: link.slice(0, 40), refused: r.after.errors.length > 0 && r.after.success.length === 0, unchanged: r.fresh.stored === before.stored });
  }
  check("O2", `${hostile.length} hostile or malformed links (script and data URLs, other hosts, look-alike hosts, embedded markup, wrong paths, over-long) are all refused with a message`, results.every((r) => r.refused), JSON.stringify(results.filter((r) => !r.refused)));
  check("O3", "...and every refusal left the saved link exactly as it was", results.every((r) => r.unchanged), JSON.stringify(results.filter((r) => !r.unchanged)));
  const pasted = await saveMessenger(adminPage, { enabled: true, url: `https://www.messenger.com/j/${tokenOf(FAKE_1)}/?send_source=gc%3Acopy_invite_link_c` });
  check("O4", "a link pasted in Messenger's own long form (www.messenger.com, ?send_source=…) is accepted and saved in the canonical short form", pasted.after.success.length === 1 && pasted.fresh.stored === FAKE_1, mask(JSON.stringify({ ok: pasted.after.success.length, errors: pasted.after.errors, stored: pasted.fresh.stored })));
  const emptyOn = await saveMessenger(adminPage, { enabled: true, url: "", shotName: "admin-screen-refusal-no-link" });
  check("O5", "switching it on with no link is refused", emptyOn.after.errors.length > 0 && emptyOn.fresh.stored === FAKE_1, JSON.stringify({ errors: emptyOn.after.errors.length }));
  await shot(adminPage, "admin-screen-refusal");
  // CSRF: a forged cross-origin style request without the page's token is refused by the framework.
  await openScreen(adminPage);
  const forged = await adminPage.evaluate(async (url) => {
    const res = await fetch(url, { method: "PUT", credentials: "same-origin", headers: { "Content-Type": "application/x-www-form-urlencoded", Accept: "text/html" }, body: "central_messenger_enabled=1&central_messenger_invite_url=https%3A%2F%2Fm.me%2Fj%2FForgedToken123%2F", redirect: "manual" });
    return res.status;
  }, MESSENGER_SCREEN);
  const afterForge = await openScreen(adminPage);
  check("O6", "a save sent without the page's CSRF token is refused (419) and changes nothing", forged === 419 && afterForge.stored === FAKE_1, `HTTP ${forged}`);
}

// ---- P: English ----
await sleep(SPACING);
const ctxEn = await newContext();
const second = await submitApplication(ctxEn, "en", "b", "apply-en");
check("P0", "a valid application in English is accepted and lands on the English confirmation", second.outcome === "success" && second.page.url().includes("/en/recruitment/"), `${second.outcome} ${second.page.url()} ${second.errorText}`);
if (second.outcome !== "success") { await browser.close(); finish(); }
const sEn = await readConfirmation(second.page);
check("P1", "the English confirmation shows the English message, card, Join and Copy", verifiedOk(sEn, "en") && sEn.joinText?.startsWith(WORDS.en.join) && sEn.copyText === WORDS.en.copy && sEn.joinHref === FAKE_1, JSON.stringify({ h1: sEn.h1, join: sEn.joinText, copy: sEn.copyText }));
check("P2", "...with no Bangla on the card", !/[\u0980-\u09FF]/.test(await second.page.$eval("[data-messenger-card]", (e) => e.innerText)));
const enCopy = await second.page.evaluate(async () => { document.querySelector("[data-messenger-copy]").click(); await new Promise((r) => setTimeout(r, 400)); return { status: document.querySelector(".messenger-card-status")?.innerText.trim(), clip: await navigator.clipboard.readText().catch((e) => `error:${e?.name}`) }; });
check("P3", "English Copy works and announces itself", enCopy.clip === FAKE_1 && enCopy.status === WORDS.en.copied, mask(JSON.stringify(enCopy)));
await shot(second.page, "P-confirmation-en-desktop-light");
await second.page.setViewport({ width: 390, height: 844, deviceScaleFactor: 2, isMobile: true, hasTouch: true });
await second.page.emulateMediaFeatures([{ name: "prefers-color-scheme", value: "dark" }]);
await second.page.reload({ waitUntil: "networkidle0" });
await second.page.waitForSelector("[data-messenger-card]");
const sEnM = await readConfirmation(second.page);
check("P4", "English on a phone in dark mode: fits the screen, nothing sideways", sEnM.overflow <= 0 && verifiedOk(sEnM, "en"), `overflow ${sEnM.overflow}`);
await shot(second.page, "P-confirmation-en-mobile-dark");
{
  const stranger = await newContext();
  const direct = await openConfirmation(stranger, "en", "stranger-en");
  check("P5", "the English confirmation address typed into a fresh browser shows the English neutral page", await neutralOk(direct.page, direct.state, "en"), JSON.stringify({ h1: direct.state.h1 }));
  await shot(direct.page, "P-neutral-en-desktop");
  await stranger.close();
}
// a proof made on the Bangla page and the English page are each good for both languages' confirmation of this posting
const cross = await openConfirmation(ctx1, "en", "cross-language");
check("L3", "the Bangla applicant's proof also opens the English confirmation of the same posting (same application, same window)", verifiedOk(cross.state, "en"));
await cross.page.close();

// ---- a duplicate of an accepted application is refused and earns no proof ----
await sleep(SPACING);
{
  const context = await newContext();
  const dup = await submitApplication(context, "bn", "a", "duplicate");
  const cookies = await proofCookies(dup.page);
  check("F4", "applying again with an address that already has a live application is refused: error shown, no proof cookie", dup.outcome === "error" && cookies.length === 0, `${dup.outcome}, ${cookies.length} cookie(s), message: ${dup.errorText}`);
  const seen = await openConfirmation(context, "bn", "duplicate-direct");
  check("F5", "...and the confirmation address in that browser stays neutral", await neutralOk(seen.page, seen.state, "bn"));
  await context.close();
}

// ---- the real link, last ----
if (REAL_URL && REAL_CANONICAL) {
  const real = await saveMessenger(adminPage, { enabled: true, url: REAL_PASTED });
  check("W1", "the owner's real invite (pasted as given) is saved through the admin screen in the canonical form", real.fresh.stored === REAL_CANONICAL && real.fresh.switchOn === true && real.fresh.status === "on", `stored link digest ${digest(tokenOf(real.fresh.stored) ?? "")} vs expected ${digest(realToken)}`);
  // The evidence image must not carry the real invite: show the field as dots (the DOM only, nothing is submitted).
  await adminPage.$eval("#field-central_messenger_invite_url", (el) => { el.type = "password"; });
  await shot(adminPage, "admin-screen-real-link-saved");
  await surfaceScan("-real");
  await sleep(SPACING);
  const ctx3 = await newContext();
  const third = await submitApplication(ctx3, "bn", "c", "apply-real");
  check("W2", "an application made now offers the real invite on its confirmation", third.outcome === "success" && verifiedOk(await readConfirmation(third.page), "bn"), `${third.outcome} ${third.errorText}`);
  if (third.outcome === "success") {
  const sReal = await readConfirmation(third.page);
  check("W3", "Join points at the real canonical link (compared by digest)", sReal.joinHref === REAL_CANONICAL, `digest ${digest(tokenOf(sReal.joinHref) ?? "")} vs ${digest(realToken)}`);
  const realCopy = await third.page.evaluate(async () => { document.querySelector("[data-messenger-copy]").click(); await new Promise((r) => setTimeout(r, 400)); return navigator.clipboard.readText().catch((e) => `error:${e?.name}`); });
  check("W4", "Copy puts exactly the real canonical link on the clipboard (compared by digest)", realCopy === REAL_CANONICAL, `digest ${digest(tokenOf(realCopy) ?? realCopy)} vs ${digest(realToken)}`);
  await shot(third.page, "W-confirmation-real-link-bn-desktop-light");
  const stranger = await newContext();
  const direct = await openConfirmation(stranger, "bn", "stranger-real");
  check("W5", "a stranger opening the same address still gets the neutral page and no trace of the real invite", await neutralOk(direct.page, direct.state, "bn") && !(await direct.page.content()).includes(realToken));
  await stranger.close();
  const thirdProof = (await proofCookies(third.page)).find((c) => c.path === `/recruitment/${SLUG}/apply/success`);
  if (STATE_FILE && thirdProof) {
    writeFileSync(STATE_FILE, JSON.stringify({ value: thirdProof.value, path: thirdProof.path, locale: "bn", slug: SLUG, savedAt: Date.now(), tag: TAG }));
    note(`saved the last proof for the expiry phase (${STATE_FILE}); run --phase expiry 31+ minutes from now, BEFORE the server-side purge`);
  }
  }
  await third.page.close();
} else {
  note("no real link in the env file: the final real-link step was skipped");
  if (STATE_FILE) {
    const p = (await proofCookies(second.page)).find((c) => c.path === `/en/recruitment/${SLUG}/apply/success`);
    if (p) writeFileSync(STATE_FILE, JSON.stringify({ value: p.value, path: p.path, locale: "en", slug: SLUG, savedAt: Date.now(), tag: TAG }));
  }
}

// ---- no-JavaScript submission (the Server Action path): local pairs only ----
if (flag("nojs")) {
  if (!LOCAL) {
    note("--nojs skipped: a native Server Action POST is never sent at production (2026-10-04 incident)");
  } else {
    await sleep(SPACING);
    const context = await newContext();
    const page = await context.newPage();
    watch(page, "nojs");
    await page.setJavaScriptEnabled(false);
    await page.goto(`${SITE}/recruitment/${SLUG}/apply`, { waitUntil: "networkidle0", timeout: 90000 });
    const f = fieldsFor("d");
    for (const [id, value] of Object.entries(f)) { if (await page.$(`#${id}`)) await page.type(`#${id}`, value, { delay: 0 }); }
    for (const el of await page.$$("form.volunteer-form [required]")) {
      const info = await el.evaluate((n) => ({ type: n.type, tag: n.tagName, empty: !n.value, id: n.id }));
      if (info.type === "file" || info.type === "checkbox" || !info.empty) continue;
      if (info.tag === "SELECT") await el.select(await el.evaluate((n) => [...n.options].find((o) => o.value)?.value ?? ""));
      else await el.type("QA verification run");
    }
    for (const id of ["accuracy_declaration", "privacy_consent", "contact_consent"]) await page.click(`#${id}`);
    const skill = await page.$('input[name="skills[]"]');
    if (skill) await skill.click();
    await (await page.$("#photo")).uploadFile(planned.photo);
    await throttleGate();
    await Promise.all([page.waitForNavigation({ waitUntil: "networkidle0", timeout: 90000 }), page.click('form.volunteer-form button[type="submit"]')]);
    const cookies = await proofCookies(page);
    const state = await readConfirmation(page);
    check("X1", "no-JavaScript path: the Server Action sets the HttpOnly proof cookie for the address it redirects to, and redirects", page.url().endsWith("/apply/success") && cookies.length === 1 && cookies[0].httpOnly && cookies[0].path === `/recruitment/${SLUG}/apply/success`, `${page.url()} ${JSON.stringify(cookies.map((c) => ({ path: c.path, httpOnly: c.httpOnly })))}`);
    check("X2", "...which shows the Join link (the Copy button needs JavaScript and is correctly absent)", state.card && state.joinHref !== null && state.copyText === null, JSON.stringify({ card: state.card, copy: state.copyText }));
    await shot(page, "X-nojs-confirmation-bn");
    await context.close();
  }
}

// ---- T: nothing logged an error ----
check("T", "no console error, page error, failed request or HTTP error on any page of the run", problems.length === 0, problems.slice(0, 6).join(" | "));
for (const line of problems.slice(0, 20)) note(`problem: ${line}`);

await browser.close();
finish();
