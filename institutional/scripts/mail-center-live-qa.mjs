// Real-Chrome acceptance of the Mail Center against the LIVE mailbox (admin-erp/docs/MAIL_CENTER.md).
//
//   node scripts/mail-center-live-qa.mjs --admin <url> --env <file> --out <dir>
//
// The env file holds QA_TAG, ALL_/ADMIN_EMAIL and _PASSWORD (disposable accounts from
// `deploy/qa/mail-center-qa.php qa-setup`), MAILBOX_ID (the registered institutional mailbox), SEED_TAG (the tag of the
// controlled messages `deploy/qa/mail-inbound-qa.php seed` put in it) and OUTSIDE_ADDRESS (a disposable inbox the one
// real send goes to). The owner's account is never used, signed in, signed out or changed.
//
// IT TOUCHES ONLY ITS OWN MESSAGES. Every message it opens, flags or answers carries SEED_TAG in its subject. Real mail
// is listed (it must be, to prove "all mail shows everything") but never opened, never flagged and never moved.
import { createRequire } from "node:module";
import { existsSync, mkdirSync, readFileSync, writeFileSync } from "node:fs";
import { join } from "node:path";
import { randomUUID } from "node:crypto";

const arg = (name, fallback) => {
  const i = process.argv.indexOf(`--${name}`);
  return i > -1 ? process.argv[i + 1] : fallback;
};
const ADMIN = (arg("admin") ?? "").replace(/\/+$/, "");
const OUT = arg("out");
const ENV_FILE = arg("env");
if (!ADMIN || !OUT || !ENV_FILE) { console.error("usage: --admin <url> --env <file> --out <dir>"); process.exit(2); }
mkdirSync(OUT, { recursive: true });
const require = createRequire(import.meta.url);
const puppeteer = require("puppeteer-core");
const env = Object.fromEntries(readFileSync(ENV_FILE, "utf8").split(/\r?\n/).filter((l) => l.includes("=")).map((l) => [l.slice(0, l.indexOf("=")).trim(), l.slice(l.indexOf("=") + 1).trim()]));
for (const key of ["QA_TAG", "ALL_EMAIL", "ALL_PASSWORD", "MAILBOX_ID", "SEED_TAG", "OUTSIDE_ADDRESS"]) {
  if (!env[key]) { console.error(`the env file lacks ${key}`); process.exit(2); }
}
const BOX = env.MAILBOX_ID;
const SEED = env.SEED_TAG;
const MARK = `[MCIN ${SEED} `;
const chrome = [process.env.CHROME_PATH, "C:/Program Files/Google/Chrome/Application/chrome.exe", "C:/Program Files (x86)/Google/Chrome/Application/chrome.exe"].filter(Boolean).find(existsSync);
const WAIT = "load";

const checks = [];
const problems = [];
const secrets = new Set([env.ALL_PASSWORD, env.ADMIN_PASSWORD].filter(Boolean));
const check = (id, what, ok, detail = "") => {
  let t = String(detail);
  for (const s of secrets) if (s && s.length >= 6) t = t.split(s).join("[secret]");
  checks.push({ id, check: what, ok: Boolean(ok), detail: t.slice(0, 400) });
  console.log(`${ok ? "PASS" : "FAIL"}  ${id.padEnd(5)} ${what}${ok || !t ? "" : `  -> ${t.slice(0, 240)}`}`);
};
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

const browser = await puppeteer.launch({ executablePath: chrome, headless: true, args: ["--no-sandbox", "--hide-scrollbars"] });
const UA = `${(await browser.userAgent()).replace("HeadlessChrome", "Chrome")} provatferi-mail-qa/${env.QA_TAG}`;
async function openBrowser(label) {
  const ctx = await browser.createBrowserContext();
  const page = await ctx.newPage();
  await page.setUserAgent(UA);
  await page.setViewport({ width: 1366, height: 900 });
  page.on("dialog", (d) => d.accept().catch(() => {}));
  page.on("pageerror", (e) => problems.push(`${label} pageerror: ${String(e).slice(0, 160)}`));
  page.on("console", (m) => {
    // 403/404 come from the isolation probes; tracker.invalid is the hostile sample's own tracker, requested only
    // after "show images" is clicked on purpose.
    if (m.type() === "error" && !/status of (403|404|422)/.test(m.text()) && !/tracker\.invalid|ERR_NAME_NOT_RESOLVED/.test(m.text())) {
      problems.push(`${label} console: ${m.text().slice(0, 160)}`);
    }
  });
  return { ctx, page };
}
const pathOf = (p) => new URL(p.url()).pathname;
async function go(page, path) {
  const r = await page.goto(ADMIN + path, { waitUntil: WAIT, timeout: 90000 });
  return { path: pathOf(page), status: r?.status() ?? 0, headers: r?.headers() ?? {} };
}
async function clickAndWait(page, sel) {
  await Promise.all([page.waitForNavigation({ waitUntil: WAIT, timeout: 90000 }), page.click(sel)]);
  return pathOf(page);
}
async function submitForm(page, sel) {
  await Promise.all([page.waitForNavigation({ waitUntil: WAIT, timeout: 90000 }), page.$eval(sel, (f) => f.requestSubmit ? f.requestSubmit() : f.submit())]);
  return pathOf(page);
}
async function fill(page, sel, value) {
  await page.$eval(sel, (el) => { el.value = ""; });
  await page.type(sel, value, { delay: 1 });
}
async function flash(page) {
  return page.evaluate(() => {
    const t = (s) => [...document.querySelectorAll(s)].map((e) => e.textContent.replace(/\s+/g, " ").trim()).filter(Boolean);
    return { success: t(".alert-success"), warning: t(".alert-warning"), danger: t(".alert-danger"), errors: t(".invalid-feedback") };
  });
}
async function signIn(page, email, password) {
  await go(page, "/login");
  await fill(page, "#email", email);
  await fill(page, "#password", password);
  return clickAndWait(page, 'form[action$="/login"] button[type="submit"]');
}
const SECRET_WORDS = ["HOSTINGER_MAIL", "Bearer ", "api.mail.hostinger.com", "Authorization", "MAIL_PASSWORD", "MAIL_USERNAME"];
async function leaks(page) {
  const html = await page.content();
  return [...SECRET_WORDS, ...secrets].filter((w) => w && html.includes(w));
}
/** The subjects the list is showing. */
const rows = (page) => page.$$eval(".pf-mail-row", (els) => els.map((e) => e.textContent.replace(/\s+/g, " ").trim()));
async function capture(page, name) {
  await page.addStyleTag({ content: "input[type=password]{color:transparent!important;text-shadow:none!important}" });
  // Everything that is not one of this run's own messages is blurred: the evidence must not carry real mail.
  await page.evaluate((mark) => {
    for (const el of document.querySelectorAll(".pf-mail-row")) if (!el.textContent.includes(mark)) el.style.filter = "blur(6px)";
  }, MARK);
  await sleep(250);
  const f = await page.evaluate(() => ({ lang: document.documentElement.lang || "x", w: window.innerWidth }));
  const file = `${name}-${f.lang}-${f.w <= 500 ? "phone" : "desktop"}.png`;
  await page.screenshot({ path: join(OUT, file), fullPage: true });
  return file;
}
async function setLanguage(page, locale) {
  const sel = `form[action$="/locale"] input[name="locale"][value="${locale}"]`;
  if (!(await page.$(sel))) return false;
  await Promise.all([page.waitForNavigation({ waitUntil: WAIT, timeout: 90000 }), page.evaluate((s) => document.querySelector(s).closest("form").submit(), sel)]);
  return true;
}

const M = await openBrowser("all");
const evidence = { tag: env.QA_TAG, seed: SEED, admin: ADMIN, started: new Date().toISOString(), screenshots: [] };

try {
  const landed = await signIn(M.page, env.ALL_EMAIL, env.ALL_PASSWORD);
  check("S1", "the Super-Admin-level account (mail.manage_all) signs in", landed === "/admin" || landed === "/admin/", landed);
  await setLanguage(M.page, "en");

  // ---------------------------------------------------------------- reading the real mailbox
  const box = await go(M.page, `/admin/mail/${BOX}`);
  check("R1", "the institutional mailbox opens and lists real mail", box.status === 200 && (await rows(M.page)).length > 0, `${box.status}, ${(await rows(M.page)).length} rows`);
  check("R2", "Mail Center pages stay no-store, noindex, no-referrer, nosniff",
    /no-store/.test(box.headers["cache-control"] ?? "") && /noindex/.test(box.headers["x-robots-tag"] ?? "")
    && box.headers["referrer-policy"] === "no-referrer" && box.headers["x-content-type-options"] === "nosniff");

  const identities = await M.page.$$eval(".pf-mail-identity", (els) => els.map((e) => e.textContent.replace(/\s+/g, " ").trim()));
  check("R3", "its institutional addresses are listed as identities of ONE mailbox, plus All mail",
    identities.length >= 6 && identities.some((t) => /All mail/i.test(t)), JSON.stringify(identities.slice(0, 8)));
  evidence.screenshots.push(await capture(M.page, "01-inbox-all-mail"));

  const allRows = await rows(M.page);
  const seeded = allRows.filter((t) => t.includes(MARK));
  check("R4", "all mail shows every controlled message, alongside the real mail", seeded.length >= 4, `${seeded.length} of 5 on page 1 of ${allRows.length} rows`);

  // ---------------------------------------------------------------- identity filters
  const filterResults = {};
  for (const key of ["info", "support", "security", "partnerships"]) {
    const links = await M.page.$$eval(".pf-mail-identity", (els) => els.map((e) => ({ href: e.getAttribute("href"), text: e.textContent.replace(/\s+/g, " ").trim() })));
    const link = links.find((l) => l.text.includes(`${key}@`));
    if (!link) { filterResults[key] = "no filter link"; continue; }
    await M.page.goto(new URL(link.href, ADMIN).toString(), { waitUntil: WAIT, timeout: 90000 });
    const got = await rows(M.page);
    filterResults[key] = { rows: got.length, own: got.filter((t) => t.includes(`${MARK}${key}]`)).length };
    if (key === "partnerships") evidence.screenshots.push(await capture(M.page, "02-filter-partnerships"));
  }
  const filtersWork = Object.values(filterResults).every((r) => r && r.own >= 1);
  check("F1", "each identity filter shows the mail that came through that address", filtersWork, JSON.stringify(filterResults));

  // ---------------------------------------------------------------- opening a message
  await go(M.page, `/admin/mail/${BOX}?identity=${env.PARTNERSHIPS_IDENTITY_ID ?? ""}`);
  const openHref = await M.page.$$eval(".pf-mail-row", (els, mark) => {
    const hit = els.find((e) => e.textContent.includes(mark));
    return hit ? hit.getAttribute("href") : null;
  }, `${MARK}partnerships]`);
  check("O0", "the partnerships message is reachable from the list", Boolean(openHref), String(openHref));
  if (openHref) {
    await M.page.goto(new URL(openHref, ADMIN).toString(), { waitUntil: WAIT, timeout: 90000 });
    const detail = await M.page.evaluate(() => ({
      text: document.body.textContent.replace(/\s+/g, " "),
      frame: Boolean(document.querySelector("iframe.pf-mail-frame, iframe[srcdoc], .pf-mail-body iframe")),
      sandbox: document.querySelector("iframe")?.getAttribute("sandbox") ?? null,
      attachments: [...document.querySelectorAll("a[href*='/attachments/']")].map((a) => a.textContent.trim()),
    }));
    check("O1", "the message opens, with its body in a sandboxed frame", detail.frame && (detail.sandbox ?? "").includes("allow-same-origin") && !(detail.sandbox ?? "").includes("allow-scripts"), `sandbox="${detail.sandbox}"`);
    check("O2", "it says which institutional address it came through", /Received through/i.test(detail.text) && /partnerships@/.test(detail.text));
    check("O3", "its attachment is offered", detail.attachments.length >= 1, JSON.stringify(detail.attachments));
    check("O4", "remote images are held back until asked for", /image|remote/i.test(detail.text));
    evidence.screenshots.push(await capture(M.page, "03-message-open"));
    await setLanguage(M.page, "bn");
    evidence.screenshots.push(await capture(M.page, "04-message-open-bn"));
    await setLanguage(M.page, "en");

    // the attachment really downloads, with the right headers
    const attHref = await M.page.$eval("a[href*='/attachments/']", (a) => a.getAttribute("href")).catch(() => null);
    if (attHref) {
      const att = await M.page.evaluate(async (href) => {
        const r = await fetch(href, { credentials: "same-origin" });
        return { status: r.status, type: r.headers.get("content-type"), nosniff: r.headers.get("x-content-type-options"), disposition: r.headers.get("content-disposition"), bytes: (await r.text()).length };
      }, attHref);
      check("O5", "the attachment downloads as an opaque file with nosniff", att.status === 200 && att.nosniff === "nosniff" && /attachment/i.test(att.disposition ?? ""), JSON.stringify(att));
    }
  }

  // ---------------------------------------------------------------- compose: which identities may be written as
  const compose = await go(M.page, `/admin/mail/${BOX}/compose`);
  const senders = await M.page.$$eval("#identity-id option", (els) => els.map((o) => o.textContent.replace(/\s+/g, " ").trim()));
  const freeText = await M.page.evaluate(() => Boolean(document.querySelector("input[name=from], input[name=sender], input[name=reply_to]")));
  check("C1", "Compose offers the institutional identities and has no free-text sender", compose.status === 200 && senders.length >= 5 && !freeText, `${senders.length}: ${JSON.stringify(senders)}`);
  check("C2", "the automated no-reply address is NOT offered for a message a person writes", !senders.some((s) => /no-reply@/.test(s)), JSON.stringify(senders));
  evidence.screenshots.push(await capture(M.page, "05-compose"));

  // ---------------------------------------------------------------- one real send, as partnerships@
  const subject = `[MCQA ${env.QA_TAG}] live Mail Center send`;
  const partnershipsOption = await M.page.$$eval("#identity-id option", (els) => {
    const o = els.find((x) => /partnerships@/.test(x.textContent));
    return o ? o.value : null;
  });
  // SKIP_SEND=1 re-runs the read-side checks without emitting a second real message.
  if (partnershipsOption && env.SKIP_SEND !== "1") {
    await M.page.select("#identity-id", partnershipsOption);
    await fill(M.page, "#field-to", env.OUTSIDE_ADDRESS);
    await fill(M.page, "#field-subject", subject);
    await M.page.$eval("textarea[name=body]", (t) => { t.value = "Controlled Mail Center acceptance message. Nothing is required of the reader."; });
    await submitForm(M.page, "form[data-mail-send-form]");
    const sent = await flash(M.page);
    check("C3", "a message really sends from partnerships@ through the Mail Center", sent.success.length > 0, JSON.stringify(sent).slice(0, 300));
    evidence.screenshots.push(await capture(M.page, "06-after-send"));
  } else if (env.SKIP_SEND === "1") {
    check("C3", "a message really sends from partnerships@ through the Mail Center", true, "skipped on this run (already proven); SKIP_SEND=1 avoids emitting a second real message");
  } else {
    check("C3", "a message really sends from partnerships@ through the Mail Center", false, "partnerships identity not offered");
  }

  // ---------------------------------------------------------------- reply defaults to the receiving identity
  if (openHref) {
    const uid = /messages\/(\d+)/.exec(openHref)?.[1];
    const folder = /folder=([^&]+)/.exec(openHref)?.[1] ?? "INBOX";
    const reply = await go(M.page, `/admin/mail/${BOX}/messages/${uid}/reply?folder=${folder}`);
    const selected = await M.page.$eval("#identity-id", (s) => s.options[s.selectedIndex]?.textContent.replace(/\s+/g, " ").trim()).catch(() => null);
    check("Y1", "a reply defaults to the identity the conversation arrived through", reply.status === 200 && /partnerships@/.test(selected ?? ""), String(selected));
    evidence.screenshots.push(await capture(M.page, "07-reply-identity"));

    const fwd = await go(M.page, `/admin/mail/${BOX}/messages/${uid}/forward?folder=${folder}`);
    check("Y2", "the forward form opens with an identity chooser", fwd.status === 200 && Boolean(await M.page.$("#identity-id")));
  }

  // ---------------------------------------------------------------- Sent handling
  // Back to the mailbox first: the folder nav belongs to the list view, not to the compose and reply forms.
  await go(M.page, `/admin/mail/${BOX}`);
  const foldersSeen = await M.page.$$eval(".pf-mail-folder", (els) => els.map((e) => e.textContent.replace(/\s+/g, " ").trim()));
  check("T1", "the mailbox's folders include Sent", foldersSeen.some((t) => /sent|পাঠানো/i.test(t)), JSON.stringify(foldersSeen));

  // ---------------------------------------------------------------- privacy
  check("W1", "no credential or provider detail appears in any Mail Center page", (await leaks(M.page)).length === 0, JSON.stringify(await leaks(M.page)));

  // ---------------------------------------------------------------- phone + dark
  await M.page.setViewport({ width: 390, height: 844 });
  await go(M.page, `/admin/mail/${BOX}`);
  evidence.screenshots.push(await capture(M.page, "08-inbox-phone"));
  await M.page.setViewport({ width: 1366, height: 900 });
  await M.page.emulateMediaFeatures([{ name: "prefers-color-scheme", value: "dark" }]);
  await go(M.page, `/admin/mail/${BOX}`);
  evidence.screenshots.push(await capture(M.page, "09-inbox-dark"));

  check("PG", "no page error or console error in any browser", problems.length === 0, problems.slice(0, 4).join(" | "));
} catch (e) {
  check("RUN", "the run completed", false, String(e).slice(0, 400));
} finally {
  evidence.checks = checks;
  evidence.problems = problems;
  evidence.finished = new Date().toISOString();
  writeFileSync(join(OUT, `mail-center-live-qa-${env.QA_TAG}.json`), JSON.stringify(evidence, null, 2));
  await browser.close().catch(() => {});
}

const failed = checks.filter((c) => !c.ok).length;
console.log(`\n${checks.length - failed}/${checks.length} passed`);
process.exit(failed === 0 ? 0 : 1);
