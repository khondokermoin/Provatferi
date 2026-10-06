// Real-Chrome check of the processing overlay (components/ProcessingOverlay.tsx, shown by components/SubmitControl.tsx)
// on the four other upload forms: committee registration, committee correction, membership application and the
// member's profile. The volunteer form has its own, fuller run: scripts/loader-qa.mjs.
//
// Every submit is answered INSIDE the browser (request interception): nothing reaches Laravel, nothing is created.
// The pages themselves need real data to render — a registration link, a correction token, an open season, a member
// to sign in as — so this reads the fixture state written by admin-erp/deploy/qa/upload-forms-qa.php (`setup`),
// meant to be run against a LOCAL Laravel (point the site at it with LARAVEL_API_URL).
//
//   node scripts/overlay-forms-qa.mjs --base http://127.0.0.1:3200 --state <qa-upload-forms-state.json> --out <dir> \
//        [--forms registration,correction,membership,membership-en,profile] [--hold 2600]
//
// Per form, twice — the answer held ~2.6 s, then a validation error; then again with a success:
//   - the overlay is in the DOM within ~100 ms of the click, covers the page, blocks the button, centred card, the
//     form's own busy words, aria-hidden text, ONE speaking live region; after ~2 s the form's helper sentence;
//   - error: the overlay is gone, the page is not dimmed, the button is back, what was typed is still there, focus is
//     on the field to fix;
//   - success: the overlay leaves in the same commit that shows the form's success message (never an idle form between).

import { existsSync } from "node:fs";
import { mkdir, readFile, writeFile } from "node:fs/promises";
import { resolve } from "node:path";
import puppeteer from "puppeteer-core";

const arg = (n, d = null) => {
  const i = process.argv.indexOf(`--${n}`);
  return i === -1 ? d : process.argv[i + 1];
};
const base = arg("base", "http://127.0.0.1:3200").replace(/\/+$/, "");
const state = JSON.parse(await readFile(arg("state"), "utf8"));
const out = resolve(arg("out", "./overlay-forms-qa"));
const holdMs = Number(arg("hold", 2600));
const only = (arg("forms", "registration,correction,membership,membership-en,profile") ?? "").split(",");
const chrome = [process.env.CHROME_PATH, "C:/Program Files/Google/Chrome/Application/chrome.exe", "C:/Program Files (x86)/Google/Chrome/Application/chrome.exe", "/usr/bin/google-chrome", "/usr/bin/chromium"].filter(Boolean).find(existsSync);
await mkdir(out, { recursive: true });
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

const WAIT = { bn: "অনুগ্রহ করে অপেক্ষা করুন, আপনার তথ্য নিরাপদভাবে জমা হচ্ছে।", en: "Please wait while your application is being submitted securely." };
const FORMS = [
  { id: "registration", url: `/committee/register/${state.registrationToken}`, endpoint: "/api/committee/register/", busy: "জমা হচ্ছে…", idle: "জমা দিন", helper: WAIT.bn, field: "email", success: { status: "success" } },
  { id: "correction", url: `/committee/register/correct/${state.corrections?.A?.token}`, endpoint: "/api/committee/correct/", busy: "জমা হচ্ছে…", idle: "সংশোধিত তথ্য জমা দিন", helper: WAIT.bn, field: "email", success: { status: "success" } },
  { id: "membership", url: "/membership", endpoint: "/api/membership/apply", busy: "আবেদন জমা হচ্ছে…", idle: "আবেদন করুন", helper: WAIT.bn, field: "applicant_email", success: { status: "success", applicationNo: "QA-0000" } },
  { id: "membership-en", url: "/en/membership", endpoint: "/api/membership/apply", busy: "Submitting application…", idle: "Submit Application", helper: WAIT.en, field: "applicant_email", success: { status: "success", applicationNo: "QA-0000" }, mobileDark: true },
  { id: "profile", url: "/member/dashboard/profile", endpoint: "/api/member/profile", busy: "সংরক্ষণ করা হচ্ছে…", idle: "সংরক্ষণ করুন", helper: "অনুগ্রহ করে অপেক্ষা করুন, আপনার তথ্য নিরাপদভাবে সংরক্ষণ করা হচ্ছে।", field: "facebook_url", success: { status: "success" }, login: true },
].filter((f) => only.includes(f.id));

const browser = await puppeteer.launch({ executablePath: chrome, headless: true, args: ["--no-sandbox", "--disable-dev-shm-usage"] });
const report = [];
let failures = 0;

for (const form of FORMS) {
  for (const answer of ["error", "success"]) {
    const row = { form: form.id, answer, checks: [], problems: { console: [], pageErrors: [], failed: [] } };
    const check = (name, ok, detail) => { row.checks.push({ name, ok: !!ok, detail }); if (!ok) { failures++; console.log(`   FAIL ${name} ${detail !== undefined ? JSON.stringify(detail) : ""}`); } };
    console.log(`\n== ${form.id} / ${answer}`);
    const context = await browser.createBrowserContext();
    const page = await context.newPage();
    await page.setViewport(form.mobileDark ? { width: 390, height: 844, deviceScaleFactor: 3, isMobile: true, hasTouch: true } : { width: 1440, height: 900 });
    await page.emulateMediaFeatures([{ name: "prefers-color-scheme", value: form.mobileDark ? "dark" : "light" }]);
    if (form.login) {
      await page.goto(`${base}/member/login`, { waitUntil: "networkidle0", timeout: 90000 });
      await page.waitForFunction(() => { const f = document.querySelector("form"); return f && Object.keys(f).some((k) => k.startsWith("__reactProps$")); }, { timeout: 30000 });
      await page.type("#email", state.member.email, { delay: 5 });
      await page.type("#password", state.member.password, { delay: 5 });
      await Promise.all([page.waitForFunction(() => location.pathname.startsWith("/member/dashboard"), { timeout: 60000 }), page.click('form button[type="submit"]')]);
    }
    // Problems are recorded from the form's page on (signing in is not what is being tested here).
    page.on("console", (m) => { if (m.type() === "error") row.problems.console.push(m.text().slice(0, 200)); });
    page.on("pageerror", (e) => row.problems.pageErrors.push(String(e).slice(0, 200)));
    page.on("requestfailed", (r) => { if (!(r.url().includes("_rsc=") && /ERR_ABORTED/.test(r.failure()?.errorText ?? ""))) row.problems.failed.push(`${r.method()} ${r.url().slice(0, 100)} ${r.failure()?.errorText}`); });

    // From here on, the form's own post is answered by this script; everything else goes through.
    let posts = 0;
    await page.setRequestInterception(true);
    page.on("request", (req) => {
      if (req.method() !== "POST" || !req.url().includes(form.endpoint)) return void req.continue().catch(() => {});
      posts++;
      const body = answer === "success" ? form.success : { status: "validation", errors: { [form.field]: [form.id === "membership-en" ? "Enter a valid email address." : "সঠিক ই-মেইল লিখুন।"] } };
      // 200 either way, as the real routes answer (lib/upload-route.ts): the outcome is in the JSON.
      setTimeout(() => req.respond({ status: 200, contentType: "application/json", body: JSON.stringify(body) }).catch(() => {}), holdMs);
    });

    await page.goto(`${base}${form.url}`, { waitUntil: "networkidle0", timeout: 90000 });
    await page.waitForFunction(() => { const f = document.querySelector("form.application-form"); return f && Object.keys(f).some((k) => k.startsWith("__reactProps$")); }, { timeout: 30000 });
    // Something typed, to prove it survives an error.
    const typed = await page.evaluate((field) => {
      const el = document.querySelector(`form.application-form [name="${field}"]`);
      if (!el) return null;
      const value = el.type === "url" ? "https://example.test/qa-overlay" : "qa-overlay@example.test";
      Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, "value").set.call(el, value);
      el.dispatchEvent(new Event("input", { bubbles: true }));
      return value;
    }, form.field);
    check("the field to type into exists", typed !== null, form.field);
    await page.evaluate(() => document.querySelector('form.application-form button[type="submit"]').scrollIntoView({ block: "center", behavior: "instant" }));
    await sleep(400);
    await page.evaluate(() => {
      const btn = document.querySelector('form.application-form button[type="submit"]');
      // Success messages are SuccessNote callouts (role=status); count the ones already on the page (a notice, say).
      const callouts = () => document.querySelectorAll(".callout[role='status']").length;
      window.__ov = { clickAt: null, addedAt: null, removed: [], calloutsBefore: callouts() };
      btn.addEventListener("click", () => { window.__ov.clickAt ??= performance.now(); }, { capture: true });
      new MutationObserver((records) => {
        for (const r of records) {
          for (const n of r.addedNodes) if (n.nodeType === 1 && n.matches(".processing-overlay")) window.__ov.addedAt ??= performance.now();
          for (const n of r.removedNodes) if (n.nodeType === 1 && n.matches(".processing-overlay")) {
            const b = document.querySelector('form.application-form button[type="submit"]');
            window.__ov.removed.push({ successShown: callouts() > window.__ov.calloutsBefore, formPresent: !!b, buttonIdle: !!b && !b.disabled && b.getAttribute("aria-busy") === null });
          }
        }
      }).observe(document.body, { childList: true });
    });

    await page.click('form.application-form button[type="submit"]');
    await sleep(400);
    const busy = await page.evaluate(() => {
      const ov = document.querySelector(".processing-overlay");
      const btn = document.querySelector('form.application-form button[type="submit"]');
      const b = btn.getBoundingClientRect();
      const hit = document.elementFromPoint(b.x + b.width / 2, b.y + b.height / 2);
      const o = ov?.getBoundingClientRect();
      const c = ov?.querySelector(".processing-overlay-card").getBoundingClientRect();
      return {
        overlay: !!ov, appearMs: window.__ov.addedAt !== null ? Math.round(window.__ov.addedAt - window.__ov.clickAt) : null,
        title: ov?.querySelector(".processing-overlay-title").innerText.trim() ?? null, helper: ov?.querySelector(".processing-overlay-helper").innerText.trim() ?? null,
        textHidden: !!ov && [...ov.querySelectorAll(".processing-overlay-title, .processing-overlay-helper")].every((e) => e.getAttribute("aria-hidden") === "true"),
        speaking: [...document.querySelectorAll('[role="status"], [aria-live]')].filter((e) => e.closest('[aria-hidden="true"]') === null && e.textContent.trim() !== "").length,
        blocks: !!ov && hit !== null && ov.contains(hit), centre: o && c ? [Math.round(c.x + c.width / 2 - (o.x + o.width / 2)), Math.round(c.y + c.height / 2 - (o.y + o.height / 2))] : null,
        mark: (() => { const i = ov && [...ov.querySelectorAll(".brand-loader-icon")].find((x) => getComputedStyle(x).display !== "none"); return i ? { src: i.getAttribute("src"), loaded: i.complete && i.naturalWidth > 0 } : null; })(),
        button: { disabled: btn.disabled, busy: btn.getAttribute("aria-busy") }, fieldsetDisabled: !!document.querySelector("form.application-form fieldset.form-body")?.disabled,
      };
    });
    check("overlay up within ~100 ms of the click", busy.overlay && busy.appearMs !== null && busy.appearMs <= 100, busy.appearMs);
    check("overlay: the form's own busy words, no helper yet", busy.title === form.busy && busy.helper === "", { title: busy.title, helper: busy.helper });
    check("overlay: covers and blocks the button, card centred, the right mark loaded", busy.blocks && busy.centre && Math.abs(busy.centre[0]) <= 1 && Math.abs(busy.centre[1]) <= 1 && busy.mark?.loaded && busy.mark.src.includes(`icon-${form.mobileDark ? "dark" : "light"}-256`), { blocks: busy.blocks, centre: busy.centre, mark: busy.mark });
    check("overlay text aria-hidden; one speaking live region", busy.textHidden && busy.speaking === 1, { hidden: busy.textHidden, speaking: busy.speaking });
    check("button disabled + aria-busy, fields parked", busy.button.disabled && busy.button.busy === "true" && busy.fieldsetDisabled, busy.button);
    await page.mouse.click(10, 10); // a click on the wash (top-left corner) does nothing
    await page.keyboard.press("Enter");
    await sleep(Math.max(0, 2300 - 400));
    const slow = await page.evaluate(() => document.querySelector(".processing-overlay-helper")?.innerText.trim() ?? null);
    check("after ~2 s: the form's helper sentence on the overlay", slow === form.helper, slow);
    if (answer === "error") await page.screenshot({ path: resolve(out, `${form.id}-pending.png`) });

    await sleep(holdMs + 900 - 2300);
    const after = await page.evaluate((field) => {
      const btn = document.querySelector('form.application-form button[type="submit"]');
      const el = document.activeElement;
      return {
        overlay: !!document.querySelector(".processing-overlay"), removed: window.__ov.removed, scroll: Math.round(scrollY),
        success: document.querySelectorAll(".callout[role='status']").length > window.__ov.calloutsBefore ? [...document.querySelectorAll(".callout[role='status']")].pop().innerText.trim().slice(0, 80) : null,
        button: btn ? { text: btn.innerText.trim(), disabled: btn.disabled, busy: btn.getAttribute("aria-busy") } : null,
        dim: btn ? getComputedStyle(document.querySelector("form.application-form .form-body")).opacity : null,
        value: document.querySelector(`form.application-form [name="${field}"]`)?.value ?? null,
        focused: el ? { name: el.getAttribute("name"), errorShown: el.closest(".form-field")?.querySelector(".form-field-error")?.innerText.trim() ?? null } : null,
      };
    }, form.field);
    check("one request only (second click on the wash + Enter sent nothing)", posts === 1, posts);
    check("the overlay is gone, once", !after.overlay && after.removed.length === 1, after.removed);
    if (answer === "error") {
      check("error: button back to idle, page not dimmed", after.button && after.button.text === form.idle && !after.button.disabled && after.button.busy === null && after.dim === "1", { button: after.button, dim: after.dim });
      check("error: what was typed is still there", after.value === typed, after.value);
      check("error: focus on the field to fix, its message shown", after.focused?.name === form.field && Boolean(after.focused.errorShown), after.focused);
      await sleep(600);
      await page.screenshot({ path: resolve(out, `${form.id}-after-error.png`) });
    } else {
      check("success: the success message is shown", after.success !== null, after.success);
      check("success: the overlay left in the same commit that showed it (no idle form in between)", after.removed[0]?.successShown === true, after.removed[0]);
      await page.screenshot({ path: resolve(out, `${form.id}-success.png`) });
    }
    check("no console errors, page errors or failed requests", !row.problems.console.length && !row.problems.pageErrors.length && !row.problems.failed.length, row.problems);
    console.log(`   ${row.checks.filter((c) => c.ok).length}/${row.checks.length} checks passed`);
    report.push(row);
    await context.close();
  }
}

await browser.close();
await writeFile(resolve(out, "report.json"), JSON.stringify({ base, at: new Date().toISOString(), failures, report }, null, 2));
console.log(`\n${report.length} runs, ${failures} failed checks. Report + screenshots in ${out}`);
process.exit(failures ? 1 : 0);
