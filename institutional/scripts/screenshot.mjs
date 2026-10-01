// Real-Chrome screenshot + render verification for production QA.
//
// Exists because the owner's working rule (2026-10-02) requires every
// priority to end in REAL PRODUCTION BROWSER QA with a screenshot — not an
// HTTP status check, which cannot tell a rendered page from a broken one.
// puppeteer-core is already a devDependency; it drives the Chrome already
// installed on this machine rather than downloading its own.
//
// Beyond the screenshot it reports the two things a curl check cannot see:
// every failed network request (a 404 image is invisible to `curl /`) and
// every console error. Those are what actually prove "the browser renders
// it", so they are returned as data and printed, not just captured.
//
// Usage:
//   node scripts/screenshot.mjs --url https://provatferi.org/ --out shot.png
//   node scripts/screenshot.mjs --url ... --out ... --viewport mobile
//   node scripts/screenshot.mjs --url ... --out ... --full
//
// Exits non-zero when the page returned a non-2xx/3xx status, so it can be
// used as a gate and not only as a capture tool.

import { mkdir } from "node:fs/promises";
import { dirname, resolve } from "node:path";
import puppeteer from "puppeteer-core";

const CHROME_CANDIDATES = [
  process.env.CHROME_PATH,
  "C:/Program Files/Google/Chrome/Application/chrome.exe",
  "C:/Program Files (x86)/Google/Chrome/Application/chrome.exe",
  "/usr/bin/google-chrome",
  "/usr/bin/chromium",
  "/Applications/Google Chrome.app/Contents/MacOS/Google Chrome",
].filter(Boolean);

const VIEWPORTS = {
  desktop: { width: 1440, height: 900, deviceScaleFactor: 1, isMobile: false },
  mobile: { width: 390, height: 844, deviceScaleFactor: 2, isMobile: true, hasTouch: true },
  tablet: { width: 820, height: 1180, deviceScaleFactor: 2, isMobile: true, hasTouch: true },
};

function arg(name, fallback = null) {
  const i = process.argv.indexOf(`--${name}`);
  if (i === -1) return fallback;
  const next = process.argv[i + 1];
  return next && !next.startsWith("--") ? next : true;
}

async function findChrome() {
  const { existsSync } = await import("node:fs");
  for (const candidate of CHROME_CANDIDATES) {
    if (existsSync(candidate)) return candidate;
  }
  throw new Error(`No Chrome found. Tried:\n  ${CHROME_CANDIDATES.join("\n  ")}\nSet CHROME_PATH to override.`);
}

const url = arg("url");
const out = arg("out");
if (!url || !out) {
  console.error("Usage: node scripts/screenshot.mjs --url <url> --out <file.png> [--viewport desktop|mobile|tablet] [--full] [--wait <ms>]");
  process.exit(2);
}

const viewportName = arg("viewport", "desktop");
const viewport = VIEWPORTS[viewportName];
if (!viewport) {
  console.error(`Unknown viewport "${viewportName}". Known: ${Object.keys(VIEWPORTS).join(", ")}`);
  process.exit(2);
}

const executablePath = await findChrome();
const browser = await puppeteer.launch({
  executablePath,
  headless: true,
  args: ["--no-sandbox", "--disable-dev-shm-usage", "--hide-scrollbars"],
});

const consoleErrors = [];
const failedRequests = [];

try {
  const page = await browser.newPage();
  await page.setViewport(viewport);

  page.on("console", (msg) => {
    if (msg.type() === "error") consoleErrors.push(msg.text());
  });
  page.on("pageerror", (err) => consoleErrors.push(`pageerror: ${err.message}`));
  // Next.js cancels its own RSC prefetches when a hover doesn't become a
  // navigation; those surface as ERR_ABORTED on ?_rsc= URLs and are normal,
  // so they must not be counted as failures or every run looks broken.
  const isBenignPrefetchAbort = (url, reason) => url.includes("?_rsc=") && reason === "net::ERR_ABORTED";

  page.on("requestfailed", (req) => {
    const reason = req.failure()?.errorText ?? "unknown";
    if (!isBenignPrefetchAbort(req.url(), reason)) {
      failedRequests.push({ url: req.url(), reason });
    }
  });
  page.on("response", (res) => {
    if (res.status() >= 400) failedRequests.push({ url: res.url(), reason: `HTTP ${res.status()}` });
  });

  const response = await page.goto(url, { waitUntil: "networkidle2", timeout: 45000 });
  const status = response?.status() ?? 0;

  const waitMs = Number(arg("wait", 0)) || 0;
  if (waitMs > 0) await new Promise((r) => setTimeout(r, waitMs));

  const outPath = resolve(out);
  await mkdir(dirname(outPath), { recursive: true });
  await page.screenshot({ path: outPath, fullPage: arg("full") === true });

  // A broken image still occupies DOM and still passes an HTML grep, so this
  // is the only reliable "did the picture actually appear" signal. Scoped to
  // images that are actually LAID OUT and finished loading: this site ships
  // theme-paired logos where one of the pair is always CSS-hidden and never
  // fetched, and lazy images below the fold may not have loaded yet — both
  // would otherwise be reported as broken when they are perfectly fine.
  const brokenImages = await page.evaluate(() =>
    Array.from(document.images)
      .filter((img) => {
        const laidOut = img.getClientRects().length > 0;
        return laidOut && img.complete && img.naturalWidth === 0;
      })
      .map((img) => img.currentSrc || img.src)
  );

  const result = {
    url,
    status,
    viewport: viewportName,
    screenshot: outPath,
    console_errors: consoleErrors,
    failed_requests: failedRequests,
    broken_images: brokenImages,
    ok: status >= 200 && status < 400 && brokenImages.length === 0 && failedRequests.length === 0,
  };
  console.log(JSON.stringify(result, null, 2));

  if (status < 200 || status >= 400) process.exitCode = 1;
} finally {
  await browser.close();
}
