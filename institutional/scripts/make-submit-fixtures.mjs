// Generates realistic-size upload fixtures for scripts/submit-qa.mjs — never committed (see --out).
//
//   node scripts/make-submit-fixtures.mjs --out <dir>
//
// What it writes, and why those sizes:
//   photo-tiny.png   the smallest valid image (a few hundred bytes) — "smallest valid submission"
//   photo-mid.jpg    ~1.2 MB, 2000x1500 — a downsized/messaging-app phone photo
//   photo-phone.jpg  ~3.4 MB, 4000x3000 (12 MP) — a normal, un-resized phone photo; the form's own
//                    ceiling is 5 MB, so this is realistic without being at the limit
//   cv.pdf           ~1.2 MB, a real multi-page PDF produced by Chrome (the same engine a "Save as
//                    PDF" CV would come from), text + an embedded photo
//
// Everything is drawn in headless Chrome so the JPEGs have real texture (flat or random-noise images
// compress to the wrong size and decode at the wrong speed) and the PDF is a genuine one that passes
// the server's extension + magic-bytes + libmagic checks.

import { existsSync } from "node:fs";
import { mkdir, stat, writeFile } from "node:fs/promises";
import { resolve } from "node:path";
import puppeteer from "puppeteer-core";

const arg = (n, d = null) => {
  const i = process.argv.indexOf(`--${n}`);
  return i === -1 ? d : process.argv[i + 1];
};
const out = resolve(arg("out", "./submit-fixtures"));
const chrome = [process.env.CHROME_PATH, "C:/Program Files/Google/Chrome/Application/chrome.exe", "C:/Program Files (x86)/Google/Chrome/Application/chrome.exe", "/usr/bin/google-chrome", "/usr/bin/chromium"].filter(Boolean).find(existsSync);
if (!chrome) throw new Error("No Chrome found; set CHROME_PATH");
await mkdir(out, { recursive: true });

const browser = await puppeteer.launch({ executablePath: chrome, headless: true, args: ["--no-sandbox", "--disable-dev-shm-usage"] });
const page = await browser.newPage();
await page.setContent("<!doctype html><meta charset=utf-8><body></body>");

/** Draws a photo-like image (smooth gradients + soft blobs + fine grain) and returns it as a data URL. */
async function drawPhoto(width, height, mime, quality, grain) {
  return page.evaluate(
    ({ width, height, mime, quality, grain }) => {
      const canvas = document.createElement("canvas");
      canvas.width = width;
      canvas.height = height;
      const ctx = canvas.getContext("2d");
      const sky = ctx.createLinearGradient(0, 0, 0, height);
      sky.addColorStop(0, "#f6b78a");
      sky.addColorStop(0.55, "#e98a6c");
      sky.addColorStop(1, "#2f4b3f");
      ctx.fillStyle = sky;
      ctx.fillRect(0, 0, width, height);
      let seed = 12345;
      const rand = () => (seed = (seed * 1664525 + 1013904223) % 4294967296) / 4294967296;
      for (let i = 0; i < 140; i++) {
        const x = rand() * width, y = rand() * height, r = (0.02 + rand() * 0.12) * Math.min(width, height);
        const g = ctx.createRadialGradient(x, y, 0, x, y, r);
        const hue = Math.floor(10 + rand() * 60), light = Math.floor(25 + rand() * 50);
        g.addColorStop(0, `hsla(${hue}, 60%, ${light}%, .55)`);
        g.addColorStop(1, `hsla(${hue}, 60%, ${light}%, 0)`);
        ctx.fillStyle = g;
        ctx.fillRect(x - r, y - r, r * 2, r * 2);
      }
      // Fine sensor-like grain, which is what makes a real photo cost real bytes.
      const tile = ctx.createImageData(256, 256);
      for (let p = 0; p < tile.data.length; p += 4) {
        const v = (rand() - 0.5) * grain;
        tile.data[p] = tile.data[p + 1] = tile.data[p + 2] = 128 + v;
        tile.data[p + 3] = 40;
      }
      const tcanvas = document.createElement("canvas");
      tcanvas.width = tcanvas.height = 256;
      tcanvas.getContext("2d").putImageData(tile, 0, 0);
      ctx.fillStyle = ctx.createPattern(tcanvas, "repeat");
      ctx.globalCompositeOperation = "overlay";
      ctx.fillRect(0, 0, width, height);
      return canvas.toDataURL(mime, quality);
    },
    { width, height, mime, quality, grain },
  );
}

const save = async (name, dataUrl) => {
  const bytes = Buffer.from(dataUrl.split(",")[1], "base64");
  await writeFile(resolve(out, name), bytes);
  return bytes.length;
};
const report = async (name) => {
  const { size } = await stat(resolve(out, name));
  console.log(`${name.padEnd(16)} ${(size / 1048576).toFixed(2)} MB (${size} bytes)`);
};

await save("photo-tiny.png", await drawPhoto(8, 8, "image/png", 1, 0));
await report("photo-tiny.png");

// Quality is binary-searched per image so each lands within ~6% of its target regardless of the browser build.
async function tuned(name, width, height, targetMb, grain) {
  let lo = 0.3, hi = 0.97, best = null;
  for (let i = 0; i < 9; i++) {
    const q = (lo + hi) / 2;
    const url = await drawPhoto(width, height, "image/jpeg", q, grain);
    const mb = Buffer.from(url.split(",")[1], "base64").length / 1048576;
    best = url;
    if (Math.abs(mb - targetMb) / targetMb < 0.06) break;
    if (mb > targetMb) hi = q; else lo = q;
  }
  return save(name, best);
}
await tuned("photo-phone.jpg", 4000, 3000, 3.4, 90);
await report("photo-phone.jpg");
await tuned("photo-mid.jpg", 2000, 1500, 1.2, 90);
await report("photo-mid.jpg");

// CV: a real PDF from Chrome — Bangla + English text plus an embedded scan-like image. The image is a
// lossless PNG on purpose: Chrome re-encodes embedded JPEGs, which makes the PDF's size unpredictable;
// the PNG's pixel dimensions are tuned instead so the PDF lands near ~1.1 MB.
async function buildCv(scanWidth) {
  const scan = await drawPhoto(scanWidth, Math.round(scanWidth * 1.38), "image/png", 1, 60);
  await page.setContent(`<!doctype html><meta charset="utf-8"><style>
    body{font-family:system-ui,"Nirmala UI","Noto Sans Bengali",sans-serif;margin:0;color:#1d2330}
    h1{font-size:26px;margin:0 0 4px} h2{font-size:15px;margin:18px 0 6px;color:#b4502c;text-transform:uppercase;letter-spacing:.06em}
    p,li{font-size:12.5px;line-height:1.55} img{width:100%;margin:10px 0}
    .pg{page-break-after:always;padding:36px 44px}
  </style>
  <div class="pg"><h1>QA TIMING TEST — Curriculum Vitae</h1><p>স্বেচ্ছাসেবী আবেদনকারী · Dhaka, Bangladesh · qa-timing@example.com</p>
  <h2>Summary</h2><p>Programme coordinator with six years of experience in community events, literary programmes and volunteer management. প্রতিষ্ঠানের সাংস্কৃতিক কর্মসূচি পরিকল্পনা ও বাস্তবায়নে অভিজ্ঞ।</p>
  <h2>Experience</h2><ul>${Array.from({ length: 9 }, (_, i) => `<li>Role ${i + 1} — organised programmes, coordinated volunteers and reported to the executive committee. কর্মসূচি ${i + 1}: সমন্বয় ও প্রতিবেদন।</li>`).join("")}</ul></div>
  <div class="pg"><h2>Certificates (scanned)</h2><img src="${scan}" alt=""></div>`);
  return page.pdf({ format: "A4", printBackground: true, margin: { top: 0, right: 0, bottom: 0, left: 0 } });
}
let width = 700, pdf = await buildCv(width);
for (let i = 0; i < 5 && Math.abs(pdf.length / 1048576 - 1.1) / 1.1 > 0.12; i++) {
  width = Math.round(width * Math.sqrt(1.1 * 1048576 / pdf.length));
  pdf = await buildCv(width);
}
await writeFile(resolve(out, "cv.pdf"), pdf);
await report("cv.pdf");

await browser.close();
console.log(`\nfixtures in ${out}`);
