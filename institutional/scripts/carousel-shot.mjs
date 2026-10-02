// Screenshot the homepage hero carousel with a SPECIFIC slide showing.
//
// scripts/screenshot.mjs always captures slide 0, which can't show how the
// carousel handles a differently-shaped slide (a tall poster, a wide banner)
// — and slide shape is exactly where a fixed-aspect box tends to break. This
// clicks the dot for the requested slide, waits out the cross-fade, and
// captures either the carousel card alone or the whole viewport.
//
// Usage:
//   node scripts/carousel-shot.mjs --url https://provatferi.org/en --out x.png --slide 2
//        [--viewport desktop|mobile] [--page]   (--page = full viewport, not just the card)
//
// --slide is 1-based, matching the dots a visitor sees.

import { existsSync } from "node:fs";
import { mkdir } from "node:fs/promises";
import { dirname, resolve } from "node:path";
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
  if (i === -1) return fallback;
  const next = process.argv[i + 1];
  return next && !next.startsWith("--") ? next : true;
};

const url = arg("url");
const out = arg("out");
const slide = Number(arg("slide", 1));
const viewportName = arg("viewport", "desktop");
const VIEWPORTS = {
  desktop: { width: 1440, height: 900 },
  mobile: { width: 390, height: 844, deviceScaleFactor: 2, isMobile: true, hasTouch: true },
};

if (!url || !out || !CHROME || !VIEWPORTS[viewportName] || !Number.isInteger(slide) || slide < 1) {
  console.error("Usage: node scripts/carousel-shot.mjs --url <url> --out <png> [--slide N] [--viewport desktop|mobile] [--page]   (needs Chrome; set CHROME_PATH)");
  process.exit(2);
}

const browser = await puppeteer.launch({ executablePath: CHROME, headless: true, args: ["--no-sandbox", "--hide-scrollbars"] });
try {
  const page = await browser.newPage();
  await page.setViewport(VIEWPORTS[viewportName]);
  await page.goto(url, { waitUntil: "networkidle2", timeout: 45000 });

  const dots = await page.$$(".carousel-dot");
  if (slide > 1) {
    if (slide > dots.length) throw new Error(`asked for slide ${slide} but the carousel has ${Math.max(dots.length, 1)}`);
    await dots[slide - 1].click();
  }
  // Longer than the 550ms cross-fade, and long enough for the next slide's
  // image to load, so the capture is of the settled state.
  await new Promise((r) => setTimeout(r, 1200));
  // Park the pointer away from the card so no hover state is in the frame.
  await page.mouse.move(2, 2);
  await page.evaluate(() => document.activeElement instanceof HTMLElement && document.activeElement.blur());

  const outPath = resolve(out);
  await mkdir(dirname(outPath), { recursive: true });
  const card = await page.$(".home-carousel");
  if (!card) throw new Error("no .home-carousel on this page");
  if (arg("page")) {
    // On mobile the controls sit just below the card, at the very bottom of
    // the first screen, so a plain viewport capture shows half-cut buttons.
    // Centre the carousel first so the capture shows it the way a visitor
    // who scrolled to it sees it, controls included.
    await card.evaluate((el) => el.scrollIntoView({ block: "center" }));
    await new Promise((r) => setTimeout(r, 400));
    await page.screenshot({ path: outPath });
  } else {
    await card.screenshot({ path: outPath });
  }
  console.log(JSON.stringify({ url, slide, viewport: viewportName, screenshot: outPath }));
} finally {
  await browser.close();
}
