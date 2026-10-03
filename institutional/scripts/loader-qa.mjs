// Real-Chrome QA for the brand loader on the volunteer application form (components/BrandLoader.tsx).
//
//   node scripts/loader-qa.mjs --base https://provatferi.org --slug <posting> --out <dir> \
//        [--mode held|mock|mockerror] [--hold 3500] [--spacing 13] [--matrix full|quick] [--only bn-desktop-light-motion]
//
// For every combination of  language (bn/en) x viewport (desktop 1440x900 / mobile 390x844@3x) x theme
// (light/dark) x motion (normal/reduced) it opens the real form, fills a few fields, clicks submit and
// checks, with the request held in flight so the pending state can be studied:
//   - feedback: the button is disabled + aria-busy + holds the loader on the first frame after the click
//   - the loader: both icon images actually loaded (naturalWidth > 0), the mark is centred in its ring,
//     the ring moves with motion and stands still with reduced motion
//   - no layout shift: the button's box and everything below it stay where they were
//   - the helper sentence is absent at ~0.5 s and present after ~2 s, in the right language
//   - one live region (role=status) announces; the button's own loader does not add a second
//   - nothing on the page logged a console error, threw, or failed to load
// then lets the request finish:
//   held  the REAL request goes through (an empty-ish form: Laravel answers validation errors, no
//         application is created) and the error state must restore the button and keep the typed data
//   mock  the answer is faked as success, to prove that the button never falls back to its idle label
//         between the success and the confirmation page opening
//   mockerror  the answer is faked as a validation error (nothing reaches any server)
// Screenshots go to --out: the whole viewport while pending (at ~0.4 s and ~2.6 s) and a 3x crop of the button.

import { existsSync } from "node:fs";
import { mkdir, writeFile } from "node:fs/promises";
import { resolve } from "node:path";
import puppeteer from "puppeteer-core";

const arg = (n, d = null) => {
  const i = process.argv.indexOf(`--${n}`);
  return i === -1 ? d : process.argv[i + 1];
};
const base = arg("base", "http://localhost:3100").replace(/\/+$/, "");
const slug = arg("slug", "prvatfereer-swecchasebee-time-zukt-hoozar-ahwan-0rkb");
const out = resolve(arg("out", "./loader-qa"));
const mode = arg("mode", "held");
const holdMs = Number(arg("hold", 3500));
const matrix = arg("matrix", "full");
const only = arg("only");
const chrome = [process.env.CHROME_PATH, "C:/Program Files/Google/Chrome/Application/chrome.exe", "C:/Program Files (x86)/Google/Chrome/Application/chrome.exe", "/usr/bin/google-chrome", "/usr/bin/chromium"].filter(Boolean).find(existsSync);
if (!chrome) throw new Error("No Chrome found; set CHROME_PATH");
await mkdir(out, { recursive: true });

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const VIEWPORTS = {
  desktop: { width: 1440, height: 900, deviceScaleFactor: 1 },
  mobile: { width: 390, height: 844, deviceScaleFactor: 3, isMobile: true, hasTouch: true },
};
const TEXT = {
  bn: { submit: "আবেদন জমা দিন", submitting: "আবেদন জমা হচ্ছে…", wait: "অনুগ্রহ করে অপেক্ষা করুন, আপনার তথ্য নিরাপদভাবে জমা হচ্ছে।", received: "আবেদন গৃহীত হয়েছে" },
  en: { submit: "Submit Application", submitting: "Submitting application…", wait: "Please wait while your application is being submitted securely.", received: "Application received" },
};

const combos = [];
for (const lang of ["bn", "en"]) for (const vp of ["desktop", "mobile"]) for (const theme of ["light", "dark"]) for (const motion of ["motion", "reduced"]) combos.push({ lang, vp, theme, motion, id: `${lang}-${vp}-${theme}-${motion}` });
const selected = only ? combos.filter((c) => c.id === only) : matrix === "quick" ? combos.filter((c) => c.motion === "motion" && (c.lang === "bn" ? c.vp === "desktop" || c.theme === "dark" : c.vp === "mobile")) : combos;

const fill = (values) => {
  const set = (id, value) => {
    const el = document.getElementById(id);
    if (!el) return;
    const proto = el.tagName === "TEXTAREA" ? HTMLTextAreaElement.prototype : HTMLInputElement.prototype;
    Object.getOwnPropertyDescriptor(proto, "value").set.call(el, value);
    el.dispatchEvent(new Event("input", { bubbles: true }));
    el.dispatchEvent(new Event("change", { bubbles: true }));
  };
  for (const [id, value] of Object.entries(values)) set(id, value);
  for (const id of ["accuracy_declaration", "privacy_consent", "contact_consent"]) { const el = document.getElementById(id); if (el && !el.checked) el.click(); }
};

/** Facts about the loader, read in the page at one instant. */
const readLoader = () => {
  const form = document.querySelector("form.volunteer-form");
  const btn = form.querySelector('button[type="submit"]');
  const loader = btn.querySelector(".brand-loader");
  const status = form.querySelector(".form-submit-status");
  const box = (el) => { const r = el.getBoundingClientRect(); return { x: r.x, y: r.y + scrollY, w: r.width, h: r.height }; };
  const out = {
    buttonDisabled: btn.disabled, ariaBusy: btn.getAttribute("aria-busy"), formAriaBusy: form.getAttribute("aria-busy"), buttonText: btn.innerText.trim(),
    loaderInButton: !!loader, liveRegionsInForm: form.querySelectorAll('[role="status"], [aria-live]').length, liveRegionsInButton: btn.querySelectorAll('[role="status"], [aria-live]').length,
    statusText: status ? status.innerText.trim() : null, helperVisible: !!form.querySelector(".form-submit-helper"),
    button: box(btn), footer: box(document.querySelector("footer")),
    scrollY: Math.round(scrollY), buttonInView: (() => { const r = btn.getBoundingClientRect(); return r.top >= 0 && r.bottom <= innerHeight; })(),
  };
  if (loader) {
    const mark = loader.querySelector(".brand-loader-mark");
    const icons = [...loader.querySelectorAll(".brand-loader-icon")].map((i) => ({ shown: getComputedStyle(i).display !== "none", loaded: i.complete && i.naturalWidth > 0, natural: [i.naturalWidth, i.naturalHeight], src: i.getAttribute("src") }));
    const shown = [...loader.querySelectorAll(".brand-loader-icon")].find((i) => getComputedStyle(i).display !== "none");
    const m = mark.getBoundingClientRect();
    const ic = shown?.getBoundingClientRect();
    const arc = loader.querySelector(".brand-loader-arc");
    out.loader = {
      size: [Math.round(m.width * 10) / 10, Math.round(m.height * 10) / 10], icons,
      centreOffset: ic ? [Math.round(((ic.x + ic.width / 2) - (m.x + m.width / 2)) * 100) / 100, Math.round(((ic.y + ic.height / 2) - (m.y + m.height / 2)) * 100) / 100] : null,
      iconSize: ic ? [Math.round(ic.width * 10) / 10, Math.round(ic.height * 10) / 10] : null,
      arcAnimation: getComputedStyle(arc).animationName, arcTransform: getComputedStyle(arc).transform, arcStroke: getComputedStyle(arc).stroke,
      chip: getComputedStyle(loader.querySelector(".brand-loader-chip")).backgroundColor, ariaHiddenMark: mark.getAttribute("aria-hidden"),
      labelColor: getComputedStyle(loader.querySelector(".brand-loader-label")).color, labelFont: getComputedStyle(loader.querySelector(".brand-loader-label")).fontSize,
    };
  }
  return out;
};

const browser = await puppeteer.launch({ executablePath: chrome, headless: true, args: ["--no-sandbox", "--disable-dev-shm-usage"] });
const report = [];
let failures = 0;
let lastClickAt = 0; // the apply route is limited to 5 requests/minute per IP: clicks that reach the real server are spaced
const SPACING_MS = Number(arg("spacing", 13)) * 1000;
const check = (row, name, ok, detail) => { row.checks.push({ name, ok: !!ok, detail }); if (!ok) { failures++; console.log(`   FAIL ${name} ${detail !== undefined ? JSON.stringify(detail) : ""}`); } };

for (const combo of selected) {
  const row = { ...combo, checks: [], problems: { console: [], pageErrors: [], failedRequests: [], badResponses: [] } };
  const context = await browser.createBrowserContext();
  const page = await context.newPage();
  const T = TEXT[combo.lang];
  await page.setViewport(VIEWPORTS[combo.vp]);
  await page.emulateMediaFeatures([{ name: "prefers-color-scheme", value: combo.theme }, { name: "prefers-reduced-motion", value: combo.motion === "reduced" ? "reduce" : "no-preference" }]);
  page.on("console", (m) => { if (m.type() === "error") row.problems.console.push(m.text().slice(0, 200)); });
  page.on("pageerror", (e) => row.problems.pageErrors.push(String(e).slice(0, 200)));
  page.on("requestfailed", (r) => {
    // Next's own flight fetch for the next page (?_rsc=…) is sometimes reported as ERR_ABORTED a few ms after its 200,
    // when the client has read what it needs and closes the stream. The navigation still completes (asserted in mock mode).
    if (r.url().includes("_rsc=") && r.failure()?.errorText === "net::ERR_ABORTED") return;
    row.problems.failedRequests.push(`${r.method()} ${r.url().slice(0, 120)} ${r.failure()?.errorText ?? ""}`);
  });
  page.on("response", (r) => { if (r.status() >= 400 && r.request().method() === "GET") row.problems.badResponses.push(`${r.status()} ${r.url().slice(0, 120)}`); });

  await page.setRequestInterception(true);
  let held = 0;
  page.on("request", (req) => {
    const submit = req.method() === "POST" && req.url().includes("/api/recruitment/");
    if (!submit) return void req.continue().catch(() => {});
    held++;
    setTimeout(() => {
      if (mode === "mock") req.respond({ status: 200, contentType: "application/json", body: JSON.stringify({ status: "success" }) }).catch(() => {});
      else if (mode === "mockerror") req.respond({ status: 200, contentType: "application/json", body: JSON.stringify({ status: "validation", errors: { applicant_phone: ["সঠিক মোবাইল নম্বর লিখুন।"] }, values: {}, skills: [], consents: [] }) }).catch(() => {});
      else req.continue().catch(() => {});
    }, holdMs);
  });

  console.log(`\n== ${combo.id}`);
  await page.goto(`${base}${combo.lang === "en" ? "/en" : ""}/recruitment/${slug}/apply`, { waitUntil: "networkidle0", timeout: 60000 });
  await page.waitForFunction(() => { const f = document.querySelector("form.volunteer-form"); return f && Object.keys(f).some((k) => k.startsWith("__reactProps$")); }, { timeout: 30000 });
  row.theme = await page.evaluate(() => document.documentElement.dataset.theme);
  check(row, "page theme matches the emulated one", row.theme === combo.theme, row.theme);

  await page.evaluate(fill, { applicant_name: "QA Loader Test", applicant_phone: "abc", applicant_email: "qa-loader@example.test", district: "Dhaka", current_location: "Mirpur", profession: "QA" });
  // The site's stylesheet makes every scroll smooth, so a plain scrollIntoView() is still moving when the idle state is
  // sampled. "instant" puts the button in view at once; then wait until the scroll position has been steady for 300 ms.
  await page.evaluate(() => document.querySelector('form.volunteer-form button[type="submit"]').scrollIntoView({ block: "center", behavior: "instant" }));
  for (let steady = 0, last = -1, tries = 0; steady < 3 && tries < 40; tries++) {
    await sleep(100);
    const y = await page.evaluate(() => Math.round(scrollY));
    steady = y === last ? steady + 1 : 0;
    last = y;
  }
  const idle = await page.evaluate(readLoader);
  check(row, "idle button shows the submit label and no loader", idle.buttonText === T.submit && !idle.loaderInButton, idle.buttonText);

  // Watch the button for the whole transition: every text / disabled / aria-busy state it passes through.
  await page.evaluate(() => {
    const btn = document.querySelector('form.volunteer-form button[type="submit"]');
    window.__states = [];
    const note = () => window.__states.push({ t: Math.round(performance.now()), text: btn.innerText.trim(), disabled: btn.disabled, busy: btn.getAttribute("aria-busy") });
    new MutationObserver(note).observe(btn, { attributes: true, childList: true, subtree: true, characterData: true });
    window.__noteState = note;
  });
  if (mode === "held") await sleep(Math.max(0, lastClickAt + SPACING_MS - Date.now()));
  const clickAt = (lastClickAt = Date.now());
  await page.click('form.volunteer-form button[type="submit"]');
  await sleep(60);
  const first = await page.evaluate(readLoader);
  check(row, "first frames: button disabled, aria-busy, loader inside, busy label", first.buttonDisabled && first.ariaBusy === "true" && first.loaderInButton && first.buttonText.includes(T.submitting), { text: first.buttonText, disabled: first.buttonDisabled });
  check(row, "form is aria-busy", first.formAriaBusy === "true");
  check(row, "exactly one live region (the loader inside the button adds none)", first.liveRegionsInForm === 1 && first.liveRegionsInButton === 0, { form: first.liveRegionsInForm, button: first.liveRegionsInButton });
  check(row, "no helper sentence yet", !first.helperVisible);
  check(row, "button box unchanged", Math.abs(first.button.w - idle.button.w) < 0.6 && Math.abs(first.button.h - idle.button.h) < 0.6, { idle: idle.button, busy: first.button });
  check(row, "nothing below the button moved", Math.abs(first.footer.y - idle.footer.y) < 0.6, { idle: idle.footer.y, busy: first.footer.y });
  const L = first.loader;
  if (L) {
    check(row, "both icon images loaded", L.icons.length === 2 && L.icons.every((i) => i.loaded), L.icons);
    check(row, "the right icon variant is shown for the theme", L.icons.filter((i) => i.shown).length === 1 && L.icons.find((i) => i.shown).src.includes(`icon-${combo.theme}-256`), L.icons.filter((i) => i.shown).map((i) => i.src));
    check(row, "mark centred in its ring (±0.5px)", L.centreOffset && Math.abs(L.centreOffset[0]) <= 0.5 && Math.abs(L.centreOffset[1]) <= 0.5, L.centreOffset);
    check(row, "mark is aria-hidden", L.ariaHiddenMark === "true");
    check(row, "loader label keeps the button's text size (not the 19px arrow-glyph rule)", L.labelFont === "15px" || L.labelFont === "14px", L.labelFont);
    // The ring: moving under normal motion, standing still under reduced motion.
    const t1 = (await page.evaluate(readLoader)).loader.arcTransform;
    await sleep(170);
    const t2 = (await page.evaluate(readLoader)).loader.arcTransform;
    if (combo.motion === "reduced") check(row, "reduced motion: ring does not move, no animation", L.arcAnimation === "none" && t1 === t2, { anim: L.arcAnimation, t1, t2 });
    else check(row, "normal motion: ring orbits", L.arcAnimation.includes("pf-brand-orbit") && t1 !== t2, { anim: L.arcAnimation, t1, t2 });
  } else check(row, "loader present", false);

  check(row, "the page did not scroll by itself on the click, and the button is still in view", first.scrollY === idle.scrollY && first.buttonInView, { idle: idle.scrollY, busy: first.scrollY, inView: first.buttonInView });

  await sleep(380 - 230);
  // Screenshots: the whole viewport, then the button itself (an element screenshot of something already in view, with
  // captureBeyondViewport off, so the capture cannot move the page — a clip in document coordinates did, on mobile
  // emulation). The scroll checks before and after prove it.
  await page.screenshot({ path: resolve(out, `${combo.id}-1-pending.png`) });
  await (await page.$('form.volunteer-form button[type="submit"]')).screenshot({ path: resolve(out, `${combo.id}-2-button-crop.png`), captureBeyondViewport: false });

  // Past ~2 s the helper sentence must have appeared, in the right language, without moving anything.
  await sleep(Math.max(0, 2600 - (Date.now() - clickAt)));
  const slow = await page.evaluate(readLoader);
  check(row, "after ~2 s the helper sentence is visible in the right language", slow.helperVisible && slow.statusText === T.wait, slow.statusText);
  check(row, "the helper appearing moved nothing", Math.abs(slow.footer.y - idle.footer.y) < 0.6 && Math.abs(slow.button.h - idle.button.h) < 0.6, { idle: idle.footer.y, slow: slow.footer.y });
  check(row, "still no scroll after the screenshots and ~2.6 s, button still in view", slow.scrollY === idle.scrollY && slow.buttonInView, { idle: idle.scrollY, slow: slow.scrollY, inView: slow.buttonInView });
  await page.screenshot({ path: resolve(out, `${combo.id}-3-pending-helper.png`) });

  // The request is released at holdMs; then success (mock) or the real answer (held).
  await sleep(Math.max(0, holdMs + 1200 - (Date.now() - clickAt)));
  const states = await page.evaluate(() => window.__states ?? []).catch(() => null);
  if (mode === "mock") {
    const onSuccess = await page.waitForFunction(() => location.pathname.endsWith("/apply/success") && document.querySelector(".apply-success"), { timeout: 20000 }).then(() => true).catch(() => false);
    check(row, "mock success: the confirmation page opened", onSuccess);
    // The states recorded before the page was replaced: the idle label must never come back.
    const idleBack = (states ?? []).filter((s) => s.text === T.submit || (s.disabled === false && s.busy === null));
    check(row, "success: the button never fell back to its idle label while waiting for the confirmation", states !== null && idleBack.length === 0, { recorded: states?.length, idleBack });
    await page.screenshot({ path: resolve(out, `${combo.id}-4-success.png`) });
  } else {
    const after = await page.evaluate(() => {
      const f = document.querySelector("form.volunteer-form");
      const b = f.querySelector('button[type="submit"]');
      return { buttonText: b.innerText.trim(), disabled: b.disabled, busy: b.getAttribute("aria-busy"), loader: !!b.querySelector(".brand-loader"), helper: !!f.querySelector(".form-submit-helper"), name: f.querySelector("#applicant_name").value, email: f.querySelector("#applicant_email").value, phone: f.querySelector("#applicant_phone").value, consents: ["accuracy_declaration", "privacy_consent", "contact_consent"].filter((i) => f.querySelector("#" + i).checked).length, focusedId: document.activeElement?.id ?? document.activeElement?.className ?? null, invalid: [...f.querySelectorAll('[aria-invalid="true"]')].map((e) => e.id), summary: f.querySelector(".form-summary")?.innerText ?? null, formAriaBusy: f.getAttribute("aria-busy") };
    });
    check(row, "error: loader gone, button restored and enabled", after.buttonText === T.submit && !after.disabled && after.busy === null && !after.loader && after.formAriaBusy === null, after);
    check(row, "error: the helper sentence is gone", !after.helper);
    check(row, "error: what was typed is still there", after.name === "QA Loader Test" && after.email === "qa-loader@example.test" && after.phone === "abc" && after.consents === 3, { name: after.name, email: after.email, phone: after.phone, consents: after.consents });
    check(row, "error: focus is on the first thing to fix", after.invalid.length > 0 && after.focusedId === after.invalid[0], { focused: after.focusedId, invalid: after.invalid.slice(0, 3) });
    await sleep(700);
    await page.screenshot({ path: resolve(out, `${combo.id}-4-after-error.png`) });
  }

  check(row, "released request count is 1", held === 1, held);
  check(row, "no console errors", row.problems.console.length === 0, row.problems.console);
  check(row, "no uncaught page errors", row.problems.pageErrors.length === 0, row.problems.pageErrors);
  check(row, "no failed requests", row.problems.failedRequests.length === 0, row.problems.failedRequests);
  check(row, "no 4xx/5xx asset or page responses", row.problems.badResponses.length === 0, row.problems.badResponses);
  row.sample = { idle: { button: idle.button, footerY: idle.footer.y }, firstLoader: first.loader };
  report.push(row);
  console.log(`   ${row.checks.filter((c) => c.ok).length}/${row.checks.length} checks passed`);
  await context.close();
}

await browser.close();
await writeFile(resolve(out, "report.json"), JSON.stringify({ base, slug, mode, holdMs, at: new Date().toISOString(), failures, report }, null, 2));
console.log(`\n${selected.length} combinations, ${failures} failed checks. Report + screenshots in ${out}`);
process.exit(failures ? 1 : 0);
