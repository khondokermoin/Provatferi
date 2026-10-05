// Real-Chrome check of the PUBLIC membership application form's fee panel (Membership Registry, task 1).
//
//   node scripts/membership-form-fees-qa.mjs --base https://provatferi.org [--expect LM=500/200,GM=100/0,ST=0/0] \
//        [--submit "QA FEES TEST <tag>" --email you+tag@gmail.com --phone 01712345678 --type ST] [--shots <dir>] [--out report.json]
//
// Needs an OPEN registration season that offers the types (otherwise the page shows "contact us" and no form — the
// script then fails loudly rather than pretending). For each expected type it opens the form in Bangla and English,
// selects the type and reads the fee panel: the two figures, in the right language and digits ("৳0" / "৳০" for a free
// tier), the panel appears only after a choice and changes when the choice changes, there is no "Free" wording and no
// payment widget — only the existing "online payment coming soon" note. With --submit it also submits ONE real
// application (use a throwaway address and a name that starts with "QA FEES TEST") and prints its application number.

import { existsSync } from "node:fs";
import { mkdir, writeFile } from "node:fs/promises";
import { resolve } from "node:path";
import puppeteer from "puppeteer-core";

const arg = (n, d = null) => {
  const i = process.argv.indexOf(`--${n}`);
  return i === -1 ? d : process.argv[i + 1];
};
const base = (arg("base", "") ?? "").replace(/\/+$/, "");
const expectArg = arg("expect", "LM=500/200,GM=100/0,ST=0/0");
const EXPECT = Object.fromEntries(expectArg.split(",").map((pair) => { const [code, fees] = pair.split("="); const [r, m] = fees.split("/"); return [code, { registration: r, monthly: m }]; }));
const submitName = arg("submit");
const submitEmail = arg("email");
const submitPhone = arg("phone", "01712345678");
const submitType = arg("type", "ST");
const shotsDir = arg("shots");
const outFile = arg("out");
if (!base) { console.error("usage: node scripts/membership-form-fees-qa.mjs --base <url> [--expect LM=500/200,…] [--submit <name> --email <addr> --type ST] [--shots dir] [--out file]"); process.exit(2); }
if (submitName && (!submitEmail || !/^QA FEES TEST /.test(submitName))) { console.error('--submit needs --email and a name starting with "QA FEES TEST "'); process.exit(2); }

const chrome = [process.env.CHROME_PATH, "C:/Program Files/Google/Chrome/Application/chrome.exe", "C:/Program Files (x86)/Google/Chrome/Application/chrome.exe", "/usr/bin/google-chrome", "/usr/bin/chromium"].filter(Boolean).find(existsSync);
if (!chrome) throw new Error("No Chrome found; set CHROME_PATH");

const BN = "০১২৩৪৫৬৭৮৯";
const taka = (n, locale) => `৳${locale === "bn" ? String(n).replace(/[0-9]/g, (d) => BN[Number(d)]) : n}`;
const NAMES = { LM: { bn: "আজীবন সদস্য", en: "Lifetime Member" }, GM: { bn: "সাধারণ সদস্য", en: "General Member" }, ST: { bn: "শিক্ষার্থী সদস্য", en: "Student Member" } };
const LABELS = { bn: { registration: "নিবন্ধন ফি", monthly: "মাসিক চাঁদা" }, en: { registration: "Registration fee", monthly: "Monthly contribution" } };

const rows = [];
const expect = (group, name, ok, detail = "") => rows.push({ group, name, ok: Boolean(ok), detail: ok ? "" : String(detail) });
const shots = [];
let applicationNo = null;

const browser = await puppeteer.launch({ executablePath: chrome, headless: true, args: ["--no-sandbox", "--disable-dev-shm-usage"] });
try {
  const consoleErrors = [];
  for (const locale of ["bn", "en"]) {
    const context = await browser.createBrowserContext();
    const page = await context.newPage();
    await page.setViewport({ width: 1366, height: 900 });
    page.on("console", (m) => { if (m.type() === "error" && !m.text().includes("/_next/hmr")) consoleErrors.push(m.text().slice(0, 200)); });
    page.on("pageerror", (e) => consoleErrors.push(String(e).slice(0, 200)));
    const group = `form ${locale}`;

    await page.goto(`${base}${locale === "en" ? "/en" : ""}/membership`, { waitUntil: "networkidle0", timeout: 90000 });
    const hasForm = await page.$("form.application-form");
    expect(group, "an open season shows the application form", hasForm, "no form on the page — is a registration season open?");
    if (!hasForm) { await page.close(); continue; }
    // Wait for React to attach to the form: a choice made before hydration changes the <select> but never reaches React state.
    await page.waitForFunction(() => {
      const f = document.querySelector("form.application-form");
      return f && document.querySelector("#membership_type_id") && Object.keys(f).some((k) => k.startsWith("__reactProps$") || k.startsWith("__reactFiber$"));
    }, { timeout: 30000 });

    /** The type field with its fee panel, screenshotted as an element (so it is captured wherever the page is scrolled). */
    const fieldShot = async (name) => {
      if (!shotsDir) return;
      await mkdir(shotsDir, { recursive: true });
      const field = (await page.evaluateHandle(() => document.querySelector("#membership_type_id").closest(".form-field"))).asElement();
      await field.screenshot({ path: resolve(shotsDir, `${name}.png`) });
      shots.push(name);
    };

    const panel = async () => page.evaluate(() => {
      const dl = document.querySelector('[data-testid="fee-summary"]');
      return dl ? { text: dl.textContent.replace(/\s+/g, " ").trim(), registration: dl.querySelector('[data-fee="registration"]')?.textContent.trim() ?? null, monthly: dl.querySelector('[data-fee="monthly"]')?.textContent.trim() ?? null, labels: [...dl.querySelectorAll("dt")].map((d) => d.textContent.trim()) } : null;
    });
    const optionFor = (code) => page.evaluate((name) => { const o = [...document.querySelectorAll("#membership_type_id option")].find((x) => x.textContent.trim() === name); return o ? o.value : null; }, NAMES[code][locale]);

    expect(group, "no fee panel before a type is chosen", (await panel()) === null);
    const optionTexts = await page.$$eval("#membership_type_id option", (os) => os.map((o) => o.textContent.trim()));
    expect(group, "the dropdown lists plain type names, with no price or 'Free' suffix", optionTexts.every((t) => !/৳|Free|বিনামূল্যে/.test(t)), JSON.stringify(optionTexts));

    for (const [code, fees] of Object.entries(EXPECT)) {
      const value = await optionFor(code);
      expect(group, `${code} (${NAMES[code][locale]}) is offered`, value, JSON.stringify(optionTexts));
      if (!value) continue;
      await page.select("#membership_type_id", value);
      await page.waitForSelector('[data-testid="fee-summary"]', { timeout: 10000 });
      const shown = await panel();
      expect(group, `${code}: registration fee reads ${taka(fees.registration, locale)}`, shown?.registration === taka(fees.registration, locale), JSON.stringify(shown));
      expect(group, `${code}: monthly contribution reads ${taka(fees.monthly, locale)}`, shown?.monthly === taka(fees.monthly, locale), JSON.stringify(shown));
      expect(group, `${code}: the two labels are ${LABELS[locale].registration} / ${LABELS[locale].monthly}`, shown && shown.labels[0] === LABELS[locale].registration && shown.labels[1] === LABELS[locale].monthly, JSON.stringify(shown?.labels));
      expect(group, `${code}: no "Free"/"বিনামূল্যে" wording in the panel`, shown && !/Free|বিনামূল্যে/.test(shown.text), shown?.text);
      if (code === "ST") await fieldShot(`form-${locale}-ST`);
    }

    // the panel follows the choice
    const first = await optionFor("LM");
    const second = await optionFor("GM");
    if (first && second) {
      await page.select("#membership_type_id", first);
      const a = await panel();
      await page.select("#membership_type_id", second);
      const b = await panel();
      expect(group, "the panel changes when another type is chosen", a && b && a.text !== b.text, `${a?.text} | ${b?.text}`);
    }

    // honest scope: a quote only — the page keeps its existing cash-only note and adds no payment control
    const bodyText = await page.evaluate(() => document.body.innerText);
    expect(group, "the page keeps its 'online payment coming soon' note and offers no payment widget", /Online payment — coming soon|অনলাইন পেমেন্ট — শিগগিরই চালু হবে/.test(bodyText) && (await page.$('input[name*="card"], input[name*="bkash"], [data-payment]')) === null, "");

    if (shotsDir) {
      await page.setViewport({ width: 390, height: 844 });
      await page.select("#membership_type_id", (await optionFor("LM")) ?? "");
      await page.waitForSelector('[data-testid="fee-summary"]', { timeout: 10000 });
      await fieldShot(`form-${locale}-LM-mobile`);
      await page.setViewport({ width: 1366, height: 900 });
    }

    if (submitName && locale === "en") {
      const value = await optionFor(submitType);
      await page.select("#membership_type_id", value);
      await page.type("#applicant_name", submitName);
      await page.type("#applicant_email", submitEmail);
      await page.type("#applicant_phone", submitPhone);
      await Promise.all([
        page.waitForFunction(() => /Application number|আবেদন নম্বর/.test(document.body.innerText), { timeout: 60000 }),
        page.click('button[type="submit"]'),
      ]);
      const text = await page.evaluate(() => document.body.innerText);
      applicationNo = (/(APP-\d{4}-\d{4})/.exec(text) ?? [])[1] ?? null;
      expect("submit", "the application was accepted and an application number shown", applicationNo, text.slice(0, 300));
      if (shotsDir) { await page.screenshot({ path: resolve(shotsDir, "form-en-submitted.png") }); shots.push("form-en-submitted"); }
    }
    await context.close();
  }
  expect("console", "no console error or page error", consoleErrors.length === 0, consoleErrors.join(" | "));
} finally {
  await browser.close();
}

const failed = rows.filter((r) => !r.ok);
const report = { at: new Date().toISOString(), base, applicationNo, checks: rows.length, passed: rows.length - failed.length, failed: failed.length, screenshots: shots, rows };
if (outFile) await writeFile(outFile, JSON.stringify(report, null, 2));
for (const r of rows) console.log(`${r.ok ? "PASS" : "FAIL"}  ${r.group} — ${r.name}${r.ok ? "" : `  [${r.detail}]`}`);
if (applicationNo) console.log(`\nSUBMITTED: ${applicationNo}`);
console.log(`\n${report.passed}/${report.checks} checks passed${shots.length ? ` · ${shots.length} screenshots` : ""}`);
process.exit(failed.length ? 1 : 0);
