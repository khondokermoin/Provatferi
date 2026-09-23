/**
 * Build-time only (never shipped as a script — only its output is): converts
 * the vendored Noto Sans Bengali TTFs (resources/fonts/, used by mPDF — see
 * RecruitmentPdfService) into self-hosted WOFF2 for the browser-facing print
 * view and the standalone error-page layout, removing the fonts.googleapis.com
 * / fonts.gstatic.com dependency both had (§7 of the 2026-09-24 defect pass —
 * an error page in particular must not depend on a third-party request to
 * render legibly, and shouldn't during a real outage either).
 *
 * Same conversion tool already used by build-icon-subset.mjs — no new
 * dependency. Full Bengali + Latin coverage is kept (this is body text for
 * arbitrary applicant-submitted names/content, not a fixed icon glyph set,
 * so it is not narrowed to a per-page character list).
 *
 * Run: node deploy/build-print-font.mjs
 * Then commit the generated public/brand/fonts/* files.
 */
import fs from "node:fs";
import path from "node:path";
import subsetFont from "subset-font";

const ROOT = path.resolve(import.meta.dirname, "..");
const OUT_DIR = path.join(ROOT, "public/brand/fonts");
fs.mkdirSync(OUT_DIR, { recursive: true });

const sources = [
  { src: "resources/fonts/NotoSansBengali-Regular.ttf", out: "NotoSansBengali-Regular.woff2" },
  { src: "resources/fonts/NotoSansBengali-Bold.ttf", out: "NotoSansBengali-Bold.woff2" },
];

for (const { src, out } of sources) {
  const input = fs.readFileSync(path.join(ROOT, src));
  // keepAllGlyphs: full Bengali + Latin + punctuation coverage kept as-is —
  // an empty `text` with the default (non-keepAllGlyphs) behaviour subsets
  // down to ZERO glyphs, not "everything" (confirmed the hard way: first
  // pass produced a 0.6 KB file with no usable glyphs at all).
  const woff2 = await subsetFont(input, null, { targetFormat: "woff2", keepAllGlyphs: true });
  fs.writeFileSync(path.join(OUT_DIR, out), woff2);
  console.log(`${src} (${(input.length / 1024).toFixed(1)} KB) -> ${out} (${(woff2.length / 1024).toFixed(1)} KB)`);
}
