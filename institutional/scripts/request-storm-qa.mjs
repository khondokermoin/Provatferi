// Does the page SETTLE? Real Chrome, several fresh browsers, counting requests over time.
//
// Added 2026-10-03 after a prefetch loop on the Bangla homepage went
// unnoticed for the life of the site: Next's router re-fetched `/about?_rsc=…`
// in a tight loop, at roughly one request per network round trip, forever —
// ~10 requests/second per open tab in production. Curl can't see it (the HTML
// comes back fine in 0.17s), the HTTP status is 200, no console error fires,
// and it only happened on ~2 of 3 loads, so a single screenshot run usually
// passed. The only symptom is that the page never goes quiet — so that is
// exactly what this measures: load the page in N FRESH browsers, let it sit,
// and fail if requests are still being made well after load.
//
// Usage: node scripts/request-storm-qa.mjs --url https://provatferi.org/ [--runs 6] [--settle 6] [--max-total 60]
//   --settle     seconds after load by which activity must have stopped (default 6)
//   --max-total  most requests a healthy page may make in the whole window (default 60)
// Exits non-zero if any run storms.

import { existsSync } from "node:fs";
import puppeteer from "puppeteer-core";

const CHROME = [
  process.env.CHROME_PATH,
  "C:/Program Files/Google/Chrome/Application/chrome.exe",
  "C:/Program Files (x86)/Google/Chrome/Application/chrome.exe",
  "/usr/bin/google-chrome",
  "/usr/bin/chromium",
].filter(Boolean).find((p) => existsSync(p));

const arg = (name, fallback = null) => {
  const i = process.argv.indexOf(`--${name}`);
  return i === -1 ? fallback : process.argv[i + 1];
};
const url = arg("url");
const runs = Number(arg("runs", 6));
const settle = Number(arg("settle", 6));
const maxTotal = Number(arg("max-total", 60));
const watchSeconds = settle + 6;

if (!url || !CHROME) {
  console.error("Usage: node scripts/request-storm-qa.mjs --url <url> [--runs N] [--settle S] [--max-total N]   (needs Chrome; set CHROME_PATH)");
  process.exit(2);
}

let stormed = 0;
for (let run = 1; run <= runs; run++) {
  const browser = await puppeteer.launch({ executablePath: CHROME, headless: true, args: ["--no-sandbox"] });
  const page = await browser.newPage();
  await page.setViewport({ width: 1440, height: 900 });

  const t0 = Date.now();
  const stamps = [];
  page.on("request", (r) => stamps.push({ t: Date.now() - t0, rsc: r.url().includes("_rsc=") }));
  await page.goto(url, { waitUntil: "domcontentloaded", timeout: 30000 });
  await new Promise((r) => setTimeout(r, watchSeconds * 1000));

  const late = stamps.filter((s) => s.t > settle * 1000);
  const total = stamps.length;
  const rsc = stamps.filter((s) => s.rsc).length;
  const lastAt = stamps.length ? (stamps[stamps.length - 1].t / 1000).toFixed(1) : "0.0";
  const healthy = late.length === 0 && total <= maxTotal;
  if (!healthy) stormed++;
  console.log(`${healthy ? "PASS" : "STORM"}  run ${run}: ${total} requests (${rsc} RSC), ${late.length} after ${settle}s, last at ${lastAt}s`);
  await browser.close();
}

console.log(`\n${runs - stormed}/${runs} loads settled${stormed ? ` — ${stormed} STORMED` : ""}`);
process.exit(stormed ? 1 : 0);
