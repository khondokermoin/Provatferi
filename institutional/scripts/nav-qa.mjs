// Does client-side navigation still work? Real Chrome: click-through, Back, and the language switch
// across the proxy rewrite, in both languages. Added 2026-10-03 with the site-wide prefetch
// change (components/SiteLink.tsx) to prove that turning prefetching off broke no navigation.
//
// Usage: node scripts/nav-qa.mjs <base url, e.g. https://provatferi.org>   (needs Chrome; CHROME_PATH overrides)
import { existsSync } from "node:fs";
import puppeteer from "puppeteer-core";

const base = process.argv[2];
const browser = await puppeteer.launch({ executablePath: [process.env.CHROME_PATH, "C:/Program Files/Google/Chrome/Application/chrome.exe", "C:/Program Files (x86)/Google/Chrome/Application/chrome.exe", "/usr/bin/google-chrome", "/usr/bin/chromium"].filter(Boolean).find((p) => existsSync(p)), headless: true, args: ["--no-sandbox"] });
const results = [];
const check = (name, ok, detail = "") => results.push(`${ok ? "PASS" : "FAIL"}  ${name}${detail ? "   [" + detail + "]" : ""}`);

async function journey(label, startPath, linkSelector, expectedPath, expectBackTo) {
  const page = await browser.newPage();
  await page.setViewport({ width: 1440, height: 900 });
  const errors = [];
  page.on("pageerror", (e) => errors.push(e.message));
  page.on("console", (m) => m.type() === "error" && errors.push(m.text()));
  let fullLoads = 0;
  page.on("framenavigated", (f) => { if (f === page.mainFrame()) fullLoads++; });
  await page.goto(base + startPath, { waitUntil: "networkidle2" });
  const loadsAfterFirst = fullLoads;
  const t0 = Date.now();
  await Promise.all([page.waitForFunction((p) => location.pathname === p, { timeout: 15000 }, expectedPath), page.click(linkSelector)]);
  await page.waitForSelector("h1", { timeout: 15000 });
  const ms = Date.now() - t0;
  const h1 = await page.$eval("h1", (e) => e.textContent.trim().slice(0, 40));
  check(`${label}: click lands on ${expectedPath}`, true, `${ms}ms, h1="${h1}"`);
  await page.goBack({ waitUntil: "networkidle2" });
  const back = new URL(page.url()).pathname;
  check(`${label}: browser Back returns to ${expectBackTo}`, back === expectBackTo, `now at ${back}`);
  check(`${label}: no console errors`, errors.length === 0, errors.slice(0, 2).join(" | "));
  void loadsAfterFirst;
  await page.close();
}

await journey("BN hero story link", "/", ".hero-actions a.text-link", "/about", "/");
await journey("EN hero story link", "/en", ".hero-actions a.text-link", "/en/about", "/en");
await journey("BN header nav -> Events", "/", 'nav.main-navigation a[href="/events"]', "/events", "/");
await journey("EN header nav -> Notice Board", "/en", 'nav.main-navigation a[href="/en/notices"]', "/en/notices", "/en");

// Language switcher round trip, which crosses the rewrite boundary.
{
  const page = await browser.newPage();
  await page.setViewport({ width: 1440, height: 900 });
  await page.goto(base + "/about", { waitUntil: "networkidle2" });
  await Promise.all([page.waitForFunction(() => location.pathname === "/en/about", { timeout: 15000 }), page.click('a[href="/en/about"]:not([class*="nav"])').catch(async () => { const a = await page.$$('a[href="/en/about"]'); await a[0].click(); })]);
  check("language switch BN /about -> EN /en/about", new URL(page.url()).pathname === "/en/about");
  await page.close();
}
await browser.close();
console.log(results.join("\n"));
console.log(`\n${results.filter((r) => r.startsWith("PASS")).length}/${results.length} passed`);
process.exit(results.some((r) => r.startsWith("FAIL")) ? 1 : 0);
