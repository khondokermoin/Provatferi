// Behavioural QA for the homepage hero carousel, in real Chrome.
//
// scripts/screenshot.mjs proves a page loaded and nothing 404'd. It cannot
// prove the carousel BEHAVES: that later slides really are withheld, that the
// controls and swipe work, that autoplay starts and can be stopped, that
// prefers-reduced-motion is honoured, that the box doesn't shift while the
// image arrives, and that it sits on the right of the hero on desktop and
// below the copy on mobile. Those are each an explicit requirement, so each
// is measured here and reported as a pass/fail, not eyeballed.
//
// Usage: node scripts/carousel-qa.mjs --url https://provatferi.org/en [--shots <dir>]
// Exits non-zero if any check fails.

import { existsSync } from "node:fs";
import { mkdir } from "node:fs/promises";
import { resolve } from "node:path";
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
if (!url || !CHROME) {
  console.error("Usage: node scripts/carousel-qa.mjs --url <homepage url> [--shots <dir>]   (needs Chrome; set CHROME_PATH)");
  process.exit(2);
}
const shotsDir = arg("shots") ? resolve(arg("shots")) : null;
if (shotsDir) await mkdir(shotsDir, { recursive: true });

const results = [];
const check = (name, pass, detail = "") => {
  results.push({ name, pass: Boolean(pass), detail });
};
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

const state = (page) =>
  page.evaluate(() => {
    const slides = [...document.querySelectorAll(".carousel-slide")];
    return {
      slides: slides.length,
      active: slides.findIndex((s) => s.classList.contains("is-active")),
      imgs: document.querySelectorAll(".carousel-image").length,
    };
  });

const browser = await puppeteer.launch({ executablePath: CHROME, headless: true, args: ["--no-sandbox", "--hide-scrollbars"] });

async function open(viewport, { reducedMotion = false } = {}) {
  const page = await browser.newPage();
  await page.setViewport(viewport);
  if (reducedMotion) await page.emulateMediaFeatures([{ name: "prefers-reduced-motion", value: "reduce" }]);
  const carouselRequests = [];
  page.on("request", (r) => {
    if (r.url().includes("/storage/homepage-carousel/")) carouselRequests.push(r.url().split("/").pop());
  });
  const errors = [];
  page.on("pageerror", (e) => errors.push(e.message));
  page.on("console", (m) => m.type() === "error" && errors.push(m.text()));
  return { page, carouselRequests, errors };
}

const DESKTOP = { width: 1440, height: 900 };
const MOBILE = { width: 390, height: 844, deviceScaleFactor: 2, isMobile: true, hasTouch: true };

// ---------------------------------------------------------------- desktop
{
  const { page, carouselRequests, errors } = await open(DESKTOP);
  let earlyRect = null;
  page.on("domcontentloaded", async () => {
    earlyRect = await page.evaluate(() => {
      const r = document.querySelector(".carousel-viewport")?.getBoundingClientRect();
      return r ? { top: r.top, height: r.height, width: r.width } : null;
    }).catch(() => null);
  });
  await page.goto(url, { waitUntil: "networkidle2", timeout: 45000 });
  const s0 = await state(page);

  check("carousel is present", s0.slides >= 1, `${s0.slides} slide(s)`);
  if (s0.slides >= 1) {
    // Placement: inside the hero, on its right, level with the copy.
    const geo = await page.evaluate(() => {
      const hero = document.querySelector(".home-hero");
      const copy = document.querySelector(".hero-copy")?.getBoundingClientRect();
      const car = document.querySelector(".home-carousel")?.getBoundingClientRect();
      return { inHero: Boolean(hero?.querySelector(".home-carousel")), copyRight: copy?.right, carLeft: car?.left, copyTop: copy?.top, copyBottom: copy?.bottom, carTop: car?.top, carBottom: car?.bottom };
    });
    check("desktop: carousel is INSIDE the hero, not above it", geo.inHero);
    check("desktop: carousel is on the RIGHT of the hero copy", geo.carLeft >= geo.copyRight - 1, `copy ends x=${Math.round(geo.copyRight)}, carousel starts x=${Math.round(geo.carLeft)}`);
    check("desktop: carousel is vertically level with the copy (not pushed below it)", geo.carTop < geo.copyBottom && geo.carBottom > geo.copyTop);
    check("desktop: hero headline is above the fold", geo.copyTop < DESKTOP.height);

    // No layout shift: the box's size at DOMContentLoaded (before images)
    // equals its size after everything loaded.
    const lateRect = await page.evaluate(() => {
      const r = document.querySelector(".carousel-viewport").getBoundingClientRect();
      return { top: r.top, height: r.height, width: r.width };
    });
    check("no layout shift: box size is identical before and after images load", earlyRect && Math.abs(earlyRect.height - lateRect.height) < 1 && Math.abs(earlyRect.width - lateRect.width) < 1, JSON.stringify({ early: earlyRect, late: lateRect }));

    // Lazy loading.
    const expectedImgs = Math.min(2, s0.slides);
    check("lazy: only slide 0 (LCP) and slide 1 (preload) have an <img> at first paint", s0.imgs === expectedImgs, `${s0.imgs} of ${s0.slides} slides have an <img>; want ${expectedImgs}`);
    check("lazy: only those images were actually requested", carouselRequests.length === expectedImgs, `${carouselRequests.length} network request(s) for carousel images`);
    const first = await page.evaluate(() => {
      const i = document.querySelector(".carousel-image");
      return { loading: i?.loading, priority: i?.fetchPriority, decoding: i?.decoding };
    });
    check("LCP: first image is eager + high priority", first.loading === "eager" && first.priority === "high", JSON.stringify(first));

    if (s0.slides > 1) {
      // Controls.
      const before = carouselRequests.length;
      await (await page.$$(".carousel-arrow"))[1].click();
      await sleep(900);
      const s1 = await state(page);
      check("next arrow advances", s1.active === 1, `active ${s0.active} -> ${s1.active}`);
      check("lazy: reaching a slide loads the one after it", s1.imgs === Math.min(s0.slides, 3), `${s1.imgs} <img> after advancing`);
      check("lazy: nothing already loaded is ever unloaded", s1.imgs >= s0.imgs);
      void before;
      await (await page.$$(".carousel-arrow"))[0].click();
      await sleep(700);
      check("previous arrow goes back", (await state(page)).active === 0);
      await (await page.$$(".carousel-dot"))[s0.slides - 1].click();
      await sleep(700);
      check("dots jump to a slide", (await state(page)).active === s0.slides - 1);
      await (await page.$$(".carousel-dot"))[0].click();
      await sleep(700);

      // Autoplay + pause. The clicks above leave the pointer hovering the
      // carousel and focus on a control, and the carousel deliberately pauses
      // under either (WCAG 2.2.2) — so release both first, or this measures
      // hover/focus suspension instead of autoplay. (An earlier version of
      // this script skipped that and reported a bogus failure.)
      await page.mouse.move(2, 2);
      await page.evaluate(() => document.activeElement instanceof HTMLElement && document.activeElement.blur());
      await sleep(300);
      const a0 = (await state(page)).active;
      await sleep(7000);
      const a1 = (await state(page)).active;
      check("autoplay advances on its own after ~6s", a1 !== a0, `active ${a0} -> ${a1}`);
      await page.click(".carousel-playpause");
      const p0 = (await state(page)).active;
      await sleep(7000);
      check("the pause button really stops autoplay", (await state(page)).active === p0);
    }
  }
  check("desktop: no console errors", errors.length === 0, errors.join(" | "));
  if (shotsDir) await page.screenshot({ path: resolve(shotsDir, "desktop.png") });
  await page.close();
}

// ---------------------------------------------------------- reduced motion
{
  const { page } = await open(DESKTOP, { reducedMotion: true });
  await page.goto(url, { waitUntil: "networkidle2", timeout: 45000 });
  const s = await state(page);
  if (s.slides > 1) {
    await sleep(7500);
    check("prefers-reduced-motion: autoplay NEVER starts", (await state(page)).active === 0);
    await (await page.$$(".carousel-arrow"))[1].click();
    await sleep(500);
    check("prefers-reduced-motion: manual navigation still works", (await state(page)).active === 1);
  }
  await page.close();
}

// ------------------------------------------------------------------ mobile
{
  const { page, errors } = await open(MOBILE);
  await page.goto(url, { waitUntil: "networkidle2", timeout: 45000 });
  const s = await state(page);
  if (s.slides >= 1) {
    const geo = await page.evaluate(() => {
      const copy = document.querySelector(".hero-copy")?.getBoundingClientRect();
      const car = document.querySelector(".home-carousel")?.getBoundingClientRect();
      const cta = document.querySelector(".hero-actions")?.getBoundingClientRect();
      return { copyBottom: copy.bottom, ctaBottom: cta.bottom, carTop: car.top, carLeft: car.left, carRight: car.right, vw: window.innerWidth, scrollW: document.documentElement.scrollWidth };
    });
    check("mobile: order is copy -> CTA -> carousel", geo.carTop >= geo.ctaBottom - 1, `CTA ends y=${Math.round(geo.ctaBottom)}, carousel starts y=${Math.round(geo.carTop)}`);
    check("mobile: carousel stays inside the viewport", geo.carLeft >= 0 && geo.carRight <= geo.vw + 1, `x ${Math.round(geo.carLeft)}..${Math.round(geo.carRight)} of ${geo.vw}`);
    check("mobile: no horizontal page scroll", geo.scrollW <= geo.vw + 1, `scrollWidth ${geo.scrollW} vs viewport ${geo.vw}`);

    if (s.slides > 1) {
      const swipe = (page, fromX, toX, fromY, toY) =>
        page.evaluate(([fx, tx, fy, ty]) => {
          const el = document.querySelector(".carousel-viewport");
          const mk = (type, x, y) => {
            const t = new Touch({ identifier: 1, target: el, clientX: x, clientY: y });
            el.dispatchEvent(new TouchEvent(type, { bubbles: true, cancelable: true, touches: type === "touchend" ? [] : [t], targetTouches: type === "touchend" ? [] : [t], changedTouches: [t] }));
          };
          mk("touchstart", fx, fy);
          mk("touchmove", (fx + tx) / 2, (fy + ty) / 2);
          mk("touchend", tx, ty);
        }, [fromX, toX, fromY, toY]);

      const before = (await state(page)).active;
      await swipe(page, 300, 80, 400, 405);
      await sleep(600);
      const afterLeft = (await state(page)).active;
      check("mobile: swipe left goes to the next slide", afterLeft === (before + 1) % s.slides, `active ${before} -> ${afterLeft}`);
      await swipe(page, 80, 300, 400, 405);
      await sleep(600);
      check("mobile: swipe right goes back", (await state(page)).active === before);
      await swipe(page, 200, 215, 300, 520);
      await sleep(600);
      check("mobile: a mostly-vertical drag (page scroll) is NOT treated as a swipe", (await state(page)).active === before);
    }
  }
  check("mobile: no console errors", errors.length === 0, errors.join(" | "));
  if (shotsDir) await page.screenshot({ path: resolve(shotsDir, "mobile.png") });
  await page.close();
}

await browser.close();

const failed = results.filter((r) => !r.pass);
for (const r of results) console.log(`${r.pass ? "PASS" : "FAIL"}  ${r.name}${r.detail && (!r.pass || r.detail.length < 90) ? `   [${r.detail}]` : ""}`);
console.log(`\n${results.length - failed.length}/${results.length} checks passed${failed.length ? ` — ${failed.length} FAILED` : ""}`);
process.exit(failed.length ? 1 : 0);
