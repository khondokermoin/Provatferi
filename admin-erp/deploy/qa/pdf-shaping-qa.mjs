#!/usr/bin/env node
/**
 * VISUAL QA of the Bengali PDF engine — the check that can actually see shaping (docs/PDF_BENGALI_STANDARD.md).
 *
 * Extracted text, Unicode comparisons and glyph counts cannot tell a correctly shaped Bengali word from one with a split vowel
 * sign or an unformed conjunct: for months the PDFs came out unshaped and every such test passed. This script looks at pixels.
 *
 *   1. `php artisan pdf:qa-grid` writes, for every word of a corpus, one PAGE drawn by the product's own mPDF configuration
 *      (PdfRenderer), and the same pages as HTML.
 *   2. Chrome prints that HTML to PDF: HarfBuzz shapes the same font file — the reference.
 *   3. PDFium rasterises both PDFs at 4 px/pt. Each word is aligned (the two engines place the baseline slightly differently)
 *      and compared: the share of ink in either rendering that lies more than 0.5 pt from ink in the other. Identical
 *      shaping scores 0; a split vowel sign, a visible hasanta or an unformed conjunct scores 0.2–0.6.
 *   4. Any word above the threshold fails the run, and its two renderings are written (red = PDF, blue = Chrome) so it can be LOOKED at.
 *
 * Corpora: `fixture` (the shaping fixture: the owner's words and every shaping feature) and `lang` (every Bengali word the
 * product's own strings can print: ~1,200 words). `--control` also runs the fixture through an UNSHAPED configuration and
 * requires that the comparison flags it — proof that the check can fail.
 *
 * Requires Chrome (CHROME_PATH or a standard install location) and, once, `npm install` in admin-erp.
 *
 *   node deploy/qa/pdf-shaping-qa.mjs [--sets fixture,lang] [--weights regular,bold] [--threshold 0.15] [--out DIR] [--control] [--php php]
 */
import { spawnSync } from "node:child_process";
import fs from "node:fs";
import os from "node:os";
import path from "node:path";
import { pathToFileURL } from "node:url";
import { PDFiumLibrary } from "@hyzyla/pdfium";
import { PNG } from "pngjs";
import puppeteer from "puppeteer-core";

const ROOT = path.resolve(import.meta.dirname, "../..");
const args = process.argv.slice(2);
const opt = (name, fallback) => (args.includes(`--${name}`) ? args[args.indexOf(`--${name}`) + 1] : fallback);
const sets = opt("sets", "fixture").split(",");
const weights = opt("weights", "regular,bold").split(",");
const threshold = Number(opt("threshold", "0.15"));
const php = opt("php", "php");
const out = path.resolve(opt("out", fs.mkdtempSync(path.join(os.tmpdir(), "pdf-shaping-qa-"))));
const control = args.includes("--control");
fs.mkdirSync(out, { recursive: true });

const chromePath = [process.env.CHROME_PATH, "C:/Program Files/Google/Chrome/Application/chrome.exe", "C:/Program Files (x86)/Google/Chrome/Application/chrome.exe", "/usr/bin/google-chrome", "/usr/bin/chromium", "/usr/bin/chromium-browser"].filter(Boolean).find(fs.existsSync);
if (!chromePath) throw new Error("No Chrome found; set CHROME_PATH");

const PT_SCALE = 4; // rasterisation: px per pt
const INK = 150; // grey level below which a pixel is ink
const TOLERANCE_PX = 2; // 0.5 pt

function mask(img) {
  const { width: w, height: h, data } = img;
  const m = new Uint8Array(w * h);
  for (let i = 0; i < w * h; i++) {
    if (0.299 * data[i * 4] + 0.587 * data[i * 4 + 1] + 0.114 * data[i * 4 + 2] < INK) m[i] = 1;
  }
  return { m, w, h };
}
function bbox({ m, w, h }) {
  let minX = w, minY = h, maxX = -1, maxY = -1, n = 0;
  for (let y = 0; y < h; y++) for (let x = 0; x < w; x++) if (m[y * w + x]) { n++; if (x < minX) minX = x; if (x > maxX) maxX = x; if (y < minY) minY = y; if (y > maxY) maxY = y; }
  return { minX, minY, maxX, maxY, n };
}
function dilate(m, w, h, r) {
  const tmp = new Uint8Array(w * h), res = new Uint8Array(w * h);
  for (let y = 0; y < h; y++) for (let x = 0; x < w; x++) if (m[y * w + x]) for (let k = Math.max(0, x - r); k <= Math.min(w - 1, x + r); k++) tmp[y * w + k] = 1;
  for (let y = 0; y < h; y++) for (let x = 0; x < w; x++) if (tmp[y * w + x]) for (let k = Math.max(0, y - r); k <= Math.min(h - 1, y + r); k++) res[k * w + x] = 1;
  return res;
}
function shift(m, w, h, dx, dy) {
  const res = new Uint8Array(w * h);
  for (let y = 0; y < h; y++) { const sy = y - dy; if (sy < 0 || sy >= h) continue; for (let x = 0; x < w; x++) { const sx = x - dx; if (sx >= 0 && sx < w) res[y * w + x] = m[sy * w + sx]; } }
  return res;
}
function miss(a, b, w, h) {
  const da = dilate(a, w, h, TOLERANCE_PX), db = dilate(b, w, h, TOLERANCE_PX);
  let na = 0, nb = 0, ma = 0, mb = 0;
  for (let i = 0; i < w * h; i++) { if (a[i]) { na++; if (!db[i]) ma++; } if (b[i]) { nb++; if (!da[i]) mb++; } }
  return (ma + mb) / Math.max(1, na + nb);
}
function overlay(file, a, b, w, h) {
  const png = new PNG({ width: w, height: h });
  for (let i = 0; i < w * h; i++) {
    const c = a[i] && b[i] ? [70, 70, 70] : a[i] ? [220, 40, 40] : b[i] ? [40, 90, 220] : [255, 255, 255];
    png.data[i * 4] = c[0]; png.data[i * 4 + 1] = c[1]; png.data[i * 4 + 2] = c[2]; png.data[i * 4 + 3] = 255;
  }
  fs.writeFileSync(file, PNG.sync.write(png));
}

const pdfium = await PDFiumLibrary.init();
const browser = await puppeteer.launch({ executablePath: chromePath, headless: true, args: ["--no-sandbox", "--allow-file-access-from-files", "--disable-dev-shm-usage"] });

/** Runs one corpus/weight; returns { name, words, flagged: [{word, score}] }. */
async function run(set, weight, { unshaped = false } = {}) {
  const dir = path.join(out, `${unshaped ? "control-" : ""}${set}-${weight}`);
  fs.mkdirSync(dir, { recursive: true });
  const cmd = spawnSync(php, ["artisan", "pdf:qa-grid", dir, `--set=${set}`, `--weight=${weight}`, ...(unshaped ? ["--unshaped"] : [])], { cwd: ROOT, encoding: "utf8", maxBuffer: 64 << 20 });
  if (cmd.status !== 0) throw new Error(`artisan pdf:qa-grid failed:\n${cmd.stdout}\n${cmd.stderr}`);

  const result = { name: path.basename(dir), words: 0, flagged: [] };
  for (const layoutFile of fs.readdirSync(dir).filter((f) => f.endsWith(".layout.json")).sort()) {
    const base = layoutFile.replace(".layout.json", "");
    const layout = JSON.parse(fs.readFileSync(path.join(dir, layoutFile), "utf8"));
    const page = await browser.newPage();
    await page.goto(pathToFileURL(path.join(dir, `${base}.chrome.html`)).href, { waitUntil: "load" });
    await page.evaluate(() => document.fonts.ready);
    fs.writeFileSync(path.join(dir, `${base}.chrome.pdf`), await page.pdf({ preferCSSPageSize: true, printBackground: true }));
    await page.close();

    const docA = await pdfium.loadDocument(fs.readFileSync(path.join(dir, `${base}.mpdf.pdf`)));
    const docB = await pdfium.loadDocument(fs.readFileSync(path.join(dir, `${base}.chrome.pdf`)));
    if (docA.getPageCount() !== layout.cells.length || docB.getPageCount() !== layout.cells.length) {
      throw new Error(`${base}: ${layout.cells.length} words but ${docA.getPageCount()} PDF pages and ${docB.getPageCount()} reference pages`);
    }
    for (let i = 0; i < layout.cells.length; i++) {
      const A = mask(await docA.getPage(i).render({ scale: PT_SCALE, render: "bitmap" }));
      const B = mask(await docB.getPage(i).render({ scale: PT_SCALE, render: "bitmap" }));
      const word = layout.cells[i].word;
      result.words++;
      const ba = bbox(A), bb = bbox(B);
      if (ba.n === 0 || bb.n === 0) { result.flagged.push({ word, score: 9, note: ba.n === 0 ? "nothing drawn in the PDF" : "nothing drawn in the reference" }); continue; }
      const dx0 = ba.minX - bb.minX, dy0 = Math.round((ba.minY + ba.maxY) / 2 - (bb.minY + bb.maxY) / 2);
      let best = { score: Infinity, ref: null };
      for (let dy = -3; dy <= 3; dy++) for (let dx = -2; dx <= 2; dx++) {
        const ref = shift(B.m, B.w, B.h, dx0 + dx, dy0 + dy);
        const score = miss(A.m, ref, A.w, A.h);
        if (score < best.score) best = { score, ref };
      }
      if (best.score > threshold) {
        result.flagged.push({ word, score: +best.score.toFixed(4) });
        overlay(path.join(dir, `flagged-${base}-${i}.png`), A.m, best.ref, A.w, A.h);
      }
    }
    docA.destroy();
    docB.destroy();
  }
  return result;
}

let failed = false;
const report = [];
try {
  for (const set of sets) {
    for (const weight of weights) {
      const r = await run(set, weight);
      report.push(r);
      console.log(`${r.name}: ${r.words} words, ${r.flagged.length} differ from the HarfBuzz reference`);
      for (const f of r.flagged.slice(0, 25)) console.log(`   ${f.word}  score ${f.score}${f.note ? ` (${f.note})` : ""}`);
      if (r.flagged.length > 0) failed = true;
    }
  }
  if (control) {
    const r = await run("fixture", "regular", { unshaped: true });
    const share = r.flagged.length / r.words;
    console.log(`control (UNSHAPED fixture): ${r.flagged.length}/${r.words} words flagged (${Math.round(share * 100)}%)`);
    if (share < 0.3) {
      console.error("the control was NOT flagged: this check cannot tell shaped from unshaped Bengali — do not trust it");
      failed = true;
    }
  }
} finally {
  await browser.close();
  pdfium.destroy();
  fs.writeFileSync(path.join(out, "report.json"), JSON.stringify(report, null, 2));
}
console.log(failed ? `FAILED — renderings of the flagged words are in ${out}` : `OK — every word matches the HarfBuzz reference (artifacts: ${out})`);
process.exit(failed ? 1 : 0);
