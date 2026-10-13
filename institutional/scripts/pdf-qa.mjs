// Real-Chrome acceptance of the recruitment application PDF (2026-10-10, admin-erp/docs/PDF_BENGALI_STANDARD.md): the PDF of an
// application with an ordinary photo and of one with the kind of photo that used to make it answer HTTP 500, in both document
// languages, downloaded through a real admin session. Only the disposable "QA PDF TEST" applications made by
// `admin-erp/deploy/qa/pdf-qa.php setup` are used. The admin login comes from the environment (QA_ADMIN_EMAIL, QA_ADMIN_PASSWORD —
// load them from a scratch env file, so they never appear on a command line the harness records) or, failing that, --email/--password.
//
//   node scripts/pdf-qa.mjs --admin <admin url> --setup <pdf-qa setup JSON> --out <dir>
//
// Writes <out>/pdf/*.pdf, a pdf-manifest.json for scripts/pdf-rasterize.mjs (render and LOOK at every page), and print-view
// screenshots. The PDFs are requested from Node with the session's cookies (a download manager on the machine running this
// answers an in-page PDF fetch with its own stub).

import { existsSync, readFileSync } from "node:fs";
import { mkdir, writeFile } from "node:fs/promises";
import { resolve } from "node:path";
import puppeteer from "puppeteer-core";

const arg = (n, d = null) => {
  const i = process.argv.indexOf(`--${n}`);
  return i === -1 ? d : process.argv[i + 1];
};
const admin = (arg("admin") ?? "").replace(/\/+$/, "");
const email = process.env.QA_ADMIN_EMAIL || arg("email");
const password = process.env.QA_ADMIN_PASSWORD || arg("password");
const out = resolve(arg("out", "./pdf-qa"));
const raw = readFileSync(resolve(arg("setup")), "utf8");
const setup = JSON.parse(raw.slice(raw.indexOf("{")));
const chrome = [process.env.CHROME_PATH, "C:/Program Files/Google/Chrome/Application/chrome.exe", "C:/Program Files (x86)/Google/Chrome/Application/chrome.exe", "/usr/bin/google-chrome", "/usr/bin/chromium"].filter(Boolean).find(existsSync);
if (!chrome || !admin || !email || !password) throw new Error("usage: --admin --email --password --setup --out (and a Chrome: CHROME_PATH)");
await mkdir(resolve(out, "pdf"), { recursive: true });

let failures = 0;
const check = (name, ok, detail) => {
  if (!ok) failures++;
  console.log(`${ok ? "  ok  " : "  FAIL"} ${name}${detail !== undefined ? " " + JSON.stringify(detail).slice(0, 400) : ""}`);
};

const browser = await puppeteer.launch({ executablePath: chrome, headless: true, args: ["--no-sandbox", "--disable-dev-shm-usage"] });
const manifest = [];
try {
  const page = await browser.newPage();
  await page.setViewport({ width: 1100, height: 900 });
  const problems = [];
  page.on("pageerror", (e) => problems.push(String(e).slice(0, 200)));
  await page.goto(`${admin}/login`, { waitUntil: "networkidle2", timeout: 60000 });
  await page.type("#email", email, { delay: 2 });
  await page.type("#password", password, { delay: 2 });
  await Promise.all([page.waitForNavigation({ waitUntil: "networkidle2", timeout: 60000 }), page.click('form button[type="submit"]')]);
  check("admin: signed in", page.url().includes("/admin"));

  const fetchPdf = async (path) => {
    const url = `${admin}${path}`;
    const cookies = await page.cookies(url);
    const r = await fetch(url, { headers: { Cookie: cookies.map((c) => `${c.name}=${c.value}`).join("; "), Accept: "application/pdf, */*" }, redirect: "manual" });
    const buf = Buffer.from(await r.arrayBuffer());
    return { status: r.status, type: r.headers.get("content-type"), disposition: r.headers.get("content-disposition"), cache: r.headers.get("cache-control"), buf };
  };

  for (const app of setup.applications) {
    const kind = app.application_no === "QA-PDF-0001" ? "normal-photo" : "large-photo";
    check(`${kind}: the stored photo is ${(app.photo_bytes / 1024 / 1024).toFixed(2)} MB`, kind === "normal-photo" ? app.photo_bytes < 200_000 : app.photo_bytes > 3_000_000, app.photo_bytes);
    for (const lang of ["bn", "en"]) {
      const name = `recruit-${kind}-${lang}`;
      const r = await fetchPdf(`${app.pdf_path}?doclang=${lang}`);
      const text = r.buf.toString("latin1");
      check(`${name}: HTTP 200, application/pdf, attachment, never cached`, r.status === 200 && /^application\/pdf/.test(r.type ?? "") && (r.disposition ?? "").startsWith("attachment") && /no-store/.test(r.cache ?? ""), { status: r.status, type: r.type, disposition: r.disposition });
      check(`${name}: real PDF bytes, ${r.buf.length} bytes (a photo's original is never embedded)`, text.startsWith("%PDF-") && text.slice(-1024).includes("%%EOF") && r.buf.length > 20_000 && r.buf.length < 600_000, r.buf.length);
      check(`${name}: the photo is the prepared 360 px JPEG`, /\/Subtype \/Image \/Width 360 \/Height 360[^>]*DCTDecode/.test(text));
      const names = [...new Set([...text.matchAll(/\/BaseFont \/([A-Za-z0-9+_-]+)/g)].map((m) => m[1]))].sort();
      check(`${name}: every font is embedded and named once (${names.join(", ")})`, names.length >= 3 && names.every((n) => n.startsWith("MPDFAA+")) && !names.some((n) => /Helvetica|ZapfDingbats|Times|Courier/.test(n)), names);
      await writeFile(resolve(out, "pdf", `${name}.pdf`), r.buf);
      manifest.push({ name, file: `pdf/${name}.pdf`, expect: [app.application_no, "Provatferi Literary and Cultural Center", "+8801711223344"] });
    }
    await page.goto(`${admin}${app.print_path}?doclang=bn`, { waitUntil: "networkidle0", timeout: 60000 });
    await page.screenshot({ path: resolve(out, `print-${kind}-bn.png`), fullPage: true });
    const fonts = await page.evaluate(async () => { await document.fonts.ready; return [...document.fonts].map((f) => `${f.family}:${f.weight}:${f.status}`); });
    check(`${kind}: the print view loads the self-hosted Bengali font (${fonts.join(", ")})`, fonts.length >= 2 && fonts.every((f) => f.endsWith(":loaded")), fonts);
  }
  check("no page errors", problems.length === 0, problems);
  await page.close();
} finally {
  await browser.close();
}
await writeFile(resolve(out, "pdf-manifest.json"), JSON.stringify(manifest, null, 2));
console.log(failures === 0 ? "\nALL CHECKS PASSED — now render and LOOK at every page (scripts/pdf-rasterize.mjs)" : `\n${failures} CHECK(S) FAILED`);
process.exit(failures === 0 ? 0 : 1);
