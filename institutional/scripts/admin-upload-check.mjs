// Real-Chrome check that the ADMIN screens still show and serve the uploads of volunteer applications after a deploy —
// the user-visible half of the stage -> switch upload-race acceptance (admin-erp/deploy/qa/uploads-race-qa.php is the
// server half). Before the fix the symptom was a broken photo / CV on exactly the applications submitted between `stage`
// and `switch`.
//
//   node scripts/admin-upload-check.mjs --apps registered.json --admin-base https://admin.provatferi.org \
//        --admin-email … --admin-password … [--shots <dir>] [--out report.json] [--label after-switch]
//
// --apps is the output of `uploads-race-qa.php register-application` (it lists each application's id, number and the
// SHA-256 of its photo and CV as they were before the deploy). For each one the script signs in (credentials come from the
// command line only — never stored), opens the application's page, and checks:
//   - the photo <img> actually decoded (naturalWidth > 0), not just that the tag exists
//   - the photo file route answers 200 with the SAME bytes (SHA-256) as before the deploy
//   - the CV file route answers 200 with the same bytes and a PDF signature
//   - the application's PDF export answers 200 and is a PDF (it embeds the photo)
//   - no console error / failed request on the page
// and takes a screenshot of the page. Exit code 1 if anything differs.

import { mkdir, readFile, writeFile } from "node:fs/promises";
import { existsSync } from "node:fs";
import { resolve } from "node:path";
import puppeteer from "puppeteer-core";

const arg = (n, d = null) => {
  const i = process.argv.indexOf(`--${n}`);
  return i === -1 ? d : process.argv[i + 1];
};
const text = await readFile(arg("apps"), "utf8");
const registered = JSON.parse(text.slice(text.indexOf("{"))).registered ?? [];
const adminBase = (arg("admin-base", "") ?? "").replace(/\/+$/, "");
const adminEmail = arg("admin-email");
const adminPassword = arg("admin-password");
const shotsDir = arg("shots");
const outFile = arg("out");
const label = arg("label", "check");
if (!adminBase || !adminEmail || !adminPassword || registered.length === 0) {
  console.error("usage: node scripts/admin-upload-check.mjs --apps registered.json --admin-base <url> --admin-email … --admin-password … [--shots dir] [--out file]");
  process.exit(2);
}

const chrome = [process.env.CHROME_PATH, "C:/Program Files/Google/Chrome/Application/chrome.exe", "C:/Program Files (x86)/Google/Chrome/Application/chrome.exe", "/usr/bin/google-chrome", "/usr/bin/chromium"].filter(Boolean).find(existsSync);
if (!chrome) throw new Error("No Chrome found; set CHROME_PATH");

const rows = [];
const expect = (group, name, ok, detail = "") => rows.push({ group, name, ok: Boolean(ok), detail: ok ? "" : String(detail) });

const browser = await puppeteer.launch({ executablePath: chrome, headless: true, args: ["--no-sandbox", "--disable-dev-shm-usage"] });
try {
  const ctx = await browser.createBrowserContext();
  const page = await ctx.newPage();
  await page.setViewport({ width: 1366, height: 900 });
  const consoleErrors = [];
  const failedRequests = [];
  page.on("console", (m) => { if (m.type() === "error") consoleErrors.push(m.text().slice(0, 160)); });
  page.on("pageerror", (e) => consoleErrors.push(String(e).slice(0, 160)));
  page.on("requestfailed", (r) => failedRequests.push(`${r.url().slice(0, 120)} ${r.failure()?.errorText}`));
  page.on("response", (r) => { if (r.status() >= 400 && r.url().startsWith(adminBase)) failedRequests.push(`${r.status()} ${r.url().slice(0, 120)}`); });

  await page.goto(`${adminBase}/login`, { waitUntil: "networkidle0", timeout: 90000 });
  await page.type("#email", adminEmail, { delay: 4 });
  await page.type("#password", adminPassword, { delay: 4 });
  await Promise.all([page.waitForNavigation({ waitUntil: "networkidle0", timeout: 90000 }), page.click('button[type="submit"]')]);
  expect("session", "signed in (not left on the login page)", !page.url().includes("/login"), page.url());

  for (const app of registered) {
    const group = `application ${app.application_no}`;
    consoleErrors.length = 0;
    failedRequests.length = 0;
    const res = await page.goto(`${adminBase}/admin/recruitment-applications/${app.id}`, { waitUntil: "networkidle0", timeout: 90000 });
    expect(group, "the application page opens (200)", res?.status() === 200, res?.status());
    const body = await page.evaluate(() => document.body.innerText);
    expect(group, "the page shows the application number", body.includes(app.application_no), "application number not found");

    // the photo as the browser decoded it — a tag pointing at a missing file renders a broken image
    const photos = await page.evaluate(() => [...document.images].filter((i) => i.src.includes("/files/photo")).map((i) => ({ src: i.src, complete: i.complete, naturalWidth: i.naturalWidth, naturalHeight: i.naturalHeight })));
    expect(group, "the photo <img> is on the page", photos.length > 0, "no <img> pointing at /files/photo (the page hides it when the file is missing)");
    expect(group, "the photo decoded in the browser (not a broken image)", photos.length > 0 && photos.every((p) => p.complete && p.naturalWidth > 0), JSON.stringify(photos));

    // file routes: status, signature and the exact bytes recorded before the deploy
    const fetched = await page.evaluate(async (base, id) => {
      const sha = async (buf) => [...new Uint8Array(await crypto.subtle.digest("SHA-256", buf))].map((b) => b.toString(16).padStart(2, "0")).join("");
      const get = async (path) => {
        const r = await fetch(`${base}/admin/recruitment-applications/${id}${path}`, { credentials: "include" });
        const buf = await r.arrayBuffer();
        const head = new TextDecoder().decode(new Uint8Array(buf.slice(0, 5)));
        return { status: r.status, type: r.headers.get("content-type"), bytes: buf.byteLength, sha256: await sha(buf), head };
      };
      return { photo: await get("/files/photo"), cv: await get("/files/cv"), pdf: await get("/pdf") };
    }, adminBase, app.id);
    expect(group, "photo route: 200 and an image", fetched.photo.status === 200 && /^image\//.test(fetched.photo.type ?? ""), JSON.stringify(fetched.photo));
    expect(group, "photo bytes are identical to before the deploy", fetched.photo.sha256 === app.photo_sha256, `${fetched.photo.sha256} vs ${app.photo_sha256}`);
    if (app.cv_path) {
      expect(group, "CV route: 200 and a PDF signature", fetched.cv.status === 200 && fetched.cv.head === "%PDF-", JSON.stringify(fetched.cv));
      expect(group, "CV bytes are identical to before the deploy", fetched.cv.sha256 === app.cv_sha256, `${fetched.cv.sha256} vs ${app.cv_sha256}`);
    }
    expect(group, "PDF export: 200 and a PDF (it embeds the photo)", fetched.pdf.status === 200 && fetched.pdf.head === "%PDF-", JSON.stringify({ status: fetched.pdf.status, head: fetched.pdf.head, bytes: fetched.pdf.bytes }));
    expect(group, "no console error and no failed request on the page", consoleErrors.length === 0 && failedRequests.length === 0, `${consoleErrors.join(" | ")} ${failedRequests.join(" | ")}`);

    if (shotsDir) {
      await mkdir(shotsDir, { recursive: true });
      await page.screenshot({ path: resolve(shotsDir, `admin-${label}-${app.application_no}.png`), fullPage: false });
    }
  }
} finally {
  await browser.close();
}

const failed = rows.filter((r) => !r.ok);
const report = { label, at: new Date().toISOString(), checks: rows.length, passed: rows.length - failed.length, failed: failed.length, rows };
if (outFile) await writeFile(outFile, JSON.stringify(report, null, 2));
for (const r of rows) console.log(`${r.ok ? "PASS" : "FAIL"}  ${r.group} — ${r.name}${r.ok ? "" : `  [${r.detail}]`}`);
console.log(`\n${report.passed}/${report.checks} checks passed`);
process.exit(failed.length ? 1 : 0);
