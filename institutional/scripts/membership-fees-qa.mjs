// Real-Chrome acceptance for membership types + effective-dated fee policies (Membership Registry, task 1).
//
//   node scripts/membership-fees-qa.mjs --admin-base https://admin.provatferi.org --site-base https://provatferi.org \
//        --admin-email … --admin-password … [--steps initial,create,cancel] [--future-date 2031-03-17] \
//        [--expect LM=500/200,GM=100/0,ST=0/0] [--shots <dir>] [--out report.json]
//
// Credentials come from the command line only (never stored). Steps, each independent and re-runnable:
//
//   initial  signs in to the real Admin UI; the Membership Types list and each type's own page show the fees
//            (default: Lifetime 500/200, General 100/0, Student 0/0); then the PUBLIC membership page, in Bangla and
//            English, desktop and phone width, shows the same two figures on every type card — the Student card reads
//            ৳0 / ৳০ — and the public API agrees (decimal strings, `fee` alias = registration fee).
//   create   creates ONE disposable Student policy through the Admin form, dated far in the future: it appears in the
//            history as "Scheduled" with the amounts, the type page and the list announce a scheduled change, and —
//            the point — today's quote everywhere (admin current fee, public cards, public API) is unchanged.
//   cancel   cancels that scheduled policy through the Admin modal: the history marks it cancelled, the scheduled
//            banner is gone, the original policy is open-ended again, and the public figures are still unchanged.
//
// Exit code 1 if anything differs. Screenshots (desktop + mobile) go to --shots.

import { existsSync } from "node:fs";
import { mkdir, writeFile } from "node:fs/promises";
import { resolve } from "node:path";
import puppeteer from "puppeteer-core";

const arg = (n, d = null) => {
  const i = process.argv.indexOf(`--${n}`);
  return i === -1 ? d : process.argv[i + 1];
};
const adminBase = (arg("admin-base", "") ?? "").replace(/\/+$/, "");
const siteBase = (arg("site-base", "") ?? "").replace(/\/+$/, "");
const email = arg("admin-email");
const password = arg("admin-password");
const steps = (arg("steps", "initial,create,cancel") ?? "").split(",").map((s) => s.trim());
const futureDate = arg("future-date", "2031-03-17");
const shotsDir = arg("shots");
const outFile = arg("out");
const QA_NOTE = "QA disposable fee policy — scheduled for the far future; cancelled again by the same run";
const QA_AMOUNTS = { registration: "137", monthly: "42" }; // odd figures: nothing in the real schedule can be mistaken for them
const expectArg = arg("expect", "LM=500/200,GM=100/0,ST=0/0");
const EXPECT = Object.fromEntries(expectArg.split(",").map((pair) => { const [code, fees] = pair.split("="); const [r, m] = fees.split("/"); return [code, { registration: r, monthly: m }]; }));
if (!adminBase || !siteBase || !email || !password) {
  console.error("usage: node scripts/membership-fees-qa.mjs --admin-base <url> --site-base <url> --admin-email … --admin-password … [--steps initial,create,cancel] [--shots dir] [--out file]");
  process.exit(2);
}

const chrome = [process.env.CHROME_PATH, "C:/Program Files/Google/Chrome/Application/chrome.exe", "C:/Program Files (x86)/Google/Chrome/Application/chrome.exe", "/usr/bin/google-chrome", "/usr/bin/chromium"].filter(Boolean).find(existsSync);
if (!chrome) throw new Error("No Chrome found; set CHROME_PATH");

const rows = [];
const expect = (group, name, ok, detail = "") => rows.push({ group, name, ok: Boolean(ok), detail: ok ? "" : String(detail) });
const shots = [];

const BN = "০১২৩৪৫৬৭৮৯";
/** "৳১,৫০০.৫০" or "৳1,500.50" -> "1500.50". Null when there is no amount in the text. */
const amountOf = (text) => {
  const ascii = String(text ?? "").replace(/[০-৯]/g, (d) => String(BN.indexOf(d)));
  const m = /৳\s*([\d,]+(?:\.\d+)?)/.exec(ascii);
  return m ? m[1].replace(/,/g, "") : null;
};
const same = (a, b) => a !== null && b !== null && Number(a) === Number(b) && !Number.isNaN(Number(a));
/** A history row's period reads "<from> → <until>" or "<from> → ongoing" (or "→ চলমান"): an end DATE has a digit after the arrow, whatever the language. */
const hasUntil = (periodText) => /→\s*[\d০-৯]/.test(String(periodText ?? ""));

const browser = await puppeteer.launch({ executablePath: chrome, headless: true, args: ["--no-sandbox", "--disable-dev-shm-usage"] });
try {
  const ctx = await browser.createBrowserContext();
  const page = await ctx.newPage();
  await page.setViewport({ width: 1366, height: 900 });
  const consoleErrors = [];
  // `next dev` (local runs only) keeps a hot-reload WebSocket that Chrome reports as an error; production has none.
  page.on("console", (m) => { if (m.type() === "error" && !m.text().includes("/_next/hmr")) consoleErrors.push(m.text().slice(0, 200)); });
  page.on("pageerror", (e) => consoleErrors.push(String(e).slice(0, 200)));

  const shot = async (name, { fullPage = false, width, height } = {}) => {
    if (!shotsDir) return;
    await mkdir(shotsDir, { recursive: true });
    if (width) await page.setViewport({ width, height: height ?? 900, deviceScaleFactor: 1 });
    await page.screenshot({ path: resolve(shotsDir, `${name}.png`), fullPage });
    shots.push(name);
    if (width) await page.setViewport({ width: 1366, height: 900 });
  };
  const go = (url) => page.goto(url, { waitUntil: "networkidle0", timeout: 90000 });

  // ------------------------------------------------------------------------------------------------ sign in
  await go(`${adminBase}/login`);
  await page.type("#email", email, { delay: 4 });
  await page.type("#password", password, { delay: 4 });
  await Promise.all([page.waitForNavigation({ waitUntil: "networkidle0", timeout: 90000 }), page.click('button[type="submit"]')]);
  expect("session", "signed in to the Admin (not left on the login page)", !page.url().includes("/login"), page.url());

  /** Reads the types list: code -> { href, registration, monthly, scheduled, since }. */
  const readList = async () => {
    await go(`${adminBase}/admin/membership/types`);
    return page.evaluate(() => {
      const out = {};
      for (const tr of document.querySelectorAll("table tbody tr")) {
        const tds = tr.querySelectorAll("td");
        if (tds.length < 8) continue;
        const code = tds[1].querySelector(".badge.bg-primary-subtle")?.textContent.trim();
        if (!code) continue;
        out[code] = {
          href: tds[1].querySelector("a")?.getAttribute("href"),
          registrationText: tds[2].textContent.trim(),
          monthlyText: tds[3].textContent.trim(),
          sinceText: tds[4].textContent.replace(/\s+/g, " ").trim(),
          scheduled: tds[4].querySelector(".badge.bg-info-subtle") !== null,
        };
      }
      return out;
    });
  };

  /** Reads a type's own page. */
  const readTypePage = async (href) => {
    await go(href.startsWith("http") ? href : `${adminBase}${href}`);
    return page.evaluate(() => ({
      registrationText: document.querySelector('[data-testid="current-registration-fee"]')?.textContent.trim() ?? null,
      monthlyText: document.querySelector('[data-testid="current-monthly-contribution"]')?.textContent.trim() ?? null,
      upcomingText: document.querySelector('[data-testid="upcoming-policy"]')?.textContent.replace(/\s+/g, " ").trim() ?? null,
      history: [...document.querySelectorAll('[data-testid="fee-history"] tbody tr[data-policy-state]')].map((tr) => ({
        state: tr.getAttribute("data-policy-state"),
        text: tr.textContent.replace(/\s+/g, " ").trim(),
        cells: [...tr.querySelectorAll("td")].map((td) => td.textContent.replace(/\s+/g, " ").trim()),
      })),
    }));
  };

  /** The public cards of the membership page, keyed by the type's name. */
  const readPublicCards = async (path) => {
    await go(`${siteBase}${path}`);
    return page.evaluate(() => {
      const out = {};
      for (const card of document.querySelectorAll(".info-card")) {
        const name = card.querySelector("h3")?.textContent.trim();
        const dl = card.querySelector('[data-testid="type-fees"]');
        if (!name) continue;
        out[name] = dl ? { registration: dl.querySelector('[data-fee="registration"]')?.textContent.trim() ?? null, monthly: dl.querySelector('[data-fee="monthly"]')?.textContent.trim() ?? null } : null;
      }
      return out;
    });
  };

  const readApi = async () => {
    const res = await fetch(`${adminBase}/api/v1/membership-types`, { headers: { Accept: "application/json" } });
    return { status: res.status, data: (await res.json()).data };
  };

  const NAMES = { LM: { bn: "আজীবন সদস্য", en: "Lifetime Member" }, GM: { bn: "সাধারণ সদস্য", en: "General Member" }, ST: { bn: "শিক্ষার্থী সদস্য", en: "Student Member" } };
  const taka = (n, locale) => `৳${locale === "bn" ? String(n).replace(/[0-9]/g, (d) => BN[Number(d)]) : n}`;

  /** The public figures for every expected type, in both languages, against the expectation. */
  const checkPublic = async (group, expected) => {
    for (const locale of ["bn", "en"]) {
      const cards = await readPublicCards(locale === "bn" ? "/membership" : "/en/membership");
      for (const [code, fees] of Object.entries(expected)) {
        const card = cards[NAMES[code][locale]];
        const label = `${code} on the ${locale === "bn" ? "Bangla" : "English"} membership page`;
        expect(group, `${label} has a fee block`, card, JSON.stringify(Object.keys(cards)));
        if (!card) continue;
        expect(group, `${label}: registration fee reads ${taka(fees.registration, locale)}`, card.registration === taka(fees.registration, locale), card.registration);
        expect(group, `${label}: monthly contribution reads ${taka(fees.monthly, locale)}`, card.monthly === taka(fees.monthly, locale), card.monthly);
      }
    }
    const api = await readApi();
    expect(group, "the public API answers 200", api.status === 200, api.status);
    for (const [code, fees] of Object.entries(expected)) {
      const t = api.data?.find((x) => x.code === code);
      expect(group, `API ${code}: registration_fee ${fees.registration}, monthly_contribution ${fees.monthly}, fee alias = registration`, t && same(t.registration_fee, fees.registration) && same(t.monthly_contribution, fees.monthly) && t.fee === t.registration_fee && typeof t.registration_fee === "string", JSON.stringify(t));
    }
  };

  // ================================================================================================ initial
  if (steps.includes("initial")) {
    const list = await readList();
    await shot("admin-types-list");
    for (const [code, fees] of Object.entries(EXPECT)) {
      const row = list[code];
      expect(`admin list ${code}`, "the type is listed with its code", row, `codes seen: ${Object.keys(list)}`);
      if (!row) continue;
      expect(`admin list ${code}`, `registration fee shows ${fees.registration}`, same(amountOf(row.registrationText), fees.registration), row.registrationText);
      expect(`admin list ${code}`, `monthly contribution shows ${fees.monthly}`, same(amountOf(row.monthlyText), fees.monthly), row.monthlyText);

      const detail = await readTypePage(row.href);
      await shot(`admin-type-${code}`);
      expect(`admin page ${code}`, `current registration fee ${fees.registration}`, same(amountOf(detail.registrationText), fees.registration), detail.registrationText);
      expect(`admin page ${code}`, `current monthly contribution ${fees.monthly}`, same(amountOf(detail.monthlyText), fees.monthly), detail.monthlyText);
      expect(`admin page ${code}`, "the fee history lists the policy as in force", detail.history.some((h) => h.state === "current"), JSON.stringify(detail.history.map((h) => h.state)));
    }
    await checkPublic("public initial", EXPECT);
    await readPublicCards("/membership");
    await shot("public-membership-bn", { fullPage: true });
    await shot("public-membership-bn-mobile", { fullPage: true, width: 390, height: 844 });
    await readPublicCards("/en/membership");
    await shot("public-membership-en", { fullPage: true });
    await shot("public-membership-en-mobile", { fullPage: true, width: 390, height: 844 });
  }

  // ================================================================================================ create
  if (steps.includes("create")) {
    const list = await readList();
    const student = list.ST;
    expect("create", "the Student type is in the list", student);
    if (student) {
      const before = await readTypePage(student.href);
      const policiesBefore = before.history.length;
      await page.$eval("#field-registration_fee", (el, v) => { el.value = v; el.dispatchEvent(new Event("input", { bubbles: true })); }, QA_AMOUNTS.registration);
      await page.$eval("#field-monthly_contribution", (el, v) => { el.value = v; el.dispatchEvent(new Event("input", { bubbles: true })); }, QA_AMOUNTS.monthly);
      await page.$eval("#field-effective_from", (el, v) => { el.value = v; el.dispatchEvent(new Event("input", { bubbles: true })); el.dispatchEvent(new Event("change", { bubbles: true })); }, futureDate);
      await page.type("#field-note", QA_NOTE);
      await shot("admin-type-ST-form-filled");
      await Promise.all([page.waitForNavigation({ waitUntil: "networkidle0", timeout: 90000 }), page.click('form[action$="/fee-policies"] button[type="submit"]')]);

      const flash = await page.$eval(".alert-success", (el) => el.textContent.replace(/\s+/g, " ").trim()).catch(() => null);
      expect("create", "the Admin confirms with a success message", flash, "no .alert-success");
      const after = await readTypePage(student.href);
      await shot("admin-type-ST-after-create");
      const scheduled = after.history.find((h) => h.state === "scheduled");
      expect("create", "the history gained exactly one row", after.history.length === policiesBefore + 1, `${policiesBefore} -> ${after.history.length}`);
      expect("create", "the new row is Scheduled with the entered amounts and note", scheduled && same(amountOf(scheduled.cells[1]), QA_AMOUNTS.registration) && same(amountOf(scheduled.cells[2]), QA_AMOUNTS.monthly) && scheduled.text.includes("QA disposable fee policy"), JSON.stringify(scheduled));
      expect("create", "the type page announces a scheduled change", after.upcomingText, "no [data-testid=upcoming-policy]");
      expect("create", "today's CURRENT fee on the Admin page is unchanged (registration, monthly)", same(amountOf(after.registrationText), EXPECT.ST.registration) && same(amountOf(after.monthlyText), EXPECT.ST.monthly), `${after.registrationText} / ${after.monthlyText}`);
      expect("create", "the earlier policy was closed the day before, not edited (its amounts are unchanged)", after.history.some((h) => h.state === "current" && same(amountOf(h.cells[1]), EXPECT.ST.registration) && same(amountOf(h.cells[2]), EXPECT.ST.monthly) && hasUntil(h.cells[0])), JSON.stringify(after.history.map((h) => [h.state, h.cells.slice(0, 3)])));
      const listAfter = await readList();
      await shot("admin-types-list-with-scheduled");
      expect("create", "the list shows the scheduled-change indicator on Student only", listAfter.ST?.scheduled && !listAfter.LM?.scheduled && !listAfter.GM?.scheduled, JSON.stringify({ ST: listAfter.ST?.scheduled, LM: listAfter.LM?.scheduled, GM: listAfter.GM?.scheduled }));
      expect("create", "the list's current figures for Student are unchanged", same(amountOf(listAfter.ST?.registrationText), EXPECT.ST.registration) && same(amountOf(listAfter.ST?.monthlyText), EXPECT.ST.monthly), `${listAfter.ST?.registrationText} / ${listAfter.ST?.monthlyText}`);
      await checkPublic("public after create", EXPECT);
      await readPublicCards("/membership");
      await shot("public-membership-bn-after-create", { fullPage: true });
    }
  }

  // ================================================================================================ cancel
  if (steps.includes("cancel")) {
    const list = await readList();
    const student = list.ST;
    expect("cancel", "the Student type is in the list", student);
    if (student) {
      const before = await readTypePage(student.href);
      const target = await page.$('button[data-bs-target^="#cancel-policy-"]');
      expect("cancel", "a scheduled policy offers a Cancel button", target, "no cancel button");
      if (target) {
        const modalId = await target.evaluate((el) => el.getAttribute("data-bs-target").slice(1));
        const policyId = modalId.replace("cancel-policy-", "");
        await target.click();
        await page.waitForSelector(`#${modalId}.show`, { visible: true, timeout: 15000 });
        await page.type(`#cancel-reason-${policyId}`, "QA run finished — removing the disposable policy");
        await shot("admin-type-ST-cancel-modal");
        await Promise.all([page.waitForNavigation({ waitUntil: "networkidle0", timeout: 90000 }), page.click(`#cancel-form-${policyId} button[type="submit"]`)]);
        const flash = await page.$eval(".alert-success", (el) => el.textContent.replace(/\s+/g, " ").trim()).catch(() => null);
        expect("cancel", "the Admin confirms the cancellation", flash, "no .alert-success");

        const after = await readTypePage(student.href);
        await shot("admin-type-ST-after-cancel");
        const cancelled = after.history.find((h) => h.state === "cancelled");
        expect("cancel", "the row is kept in the history, marked cancelled, with who/when/why", cancelled && /QA run finished/.test(cancelled.text), JSON.stringify(cancelled));
        expect("cancel", "the history still has every row (nothing was deleted)", after.history.length === before.history.length, `${before.history.length} -> ${after.history.length}`);
        expect("cancel", "no scheduled banner remains", after.upcomingText === null, after.upcomingText);
        expect("cancel", "the original policy is open-ended again", after.history.some((h) => h.state === "current" && !hasUntil(h.cells[0])), JSON.stringify(after.history.map((h) => [h.state, h.cells[0]])));
        expect("cancel", "the current fee is still the original", same(amountOf(after.registrationText), EXPECT.ST.registration) && same(amountOf(after.monthlyText), EXPECT.ST.monthly), `${after.registrationText} / ${after.monthlyText}`);
        const listAfter = await readList();
        expect("cancel", "the list no longer shows a scheduled change", !listAfter.ST?.scheduled, "still shown");
        await checkPublic("public after cancel", EXPECT);
      }
    }
  }

  expect("console", "no console error or page error on any screen", consoleErrors.length === 0, consoleErrors.join(" | "));
} finally {
  await browser.close();
}

const failed = rows.filter((r) => !r.ok);
const report = { at: new Date().toISOString(), steps, checks: rows.length, passed: rows.length - failed.length, failed: failed.length, screenshots: shots, rows };
if (outFile) await writeFile(outFile, JSON.stringify(report, null, 2));
for (const r of rows) console.log(`${r.ok ? "PASS" : "FAIL"}  ${r.group} — ${r.name}${r.ok ? "" : `  [${r.detail}]`}`);
console.log(`\n${report.passed}/${report.checks} checks passed${shots.length ? ` · ${shots.length} screenshots` : ""}`);
process.exit(failed.length ? 1 : 0);
