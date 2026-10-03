/**
 * Generates the small production copies of the official Provatferi icon mark (the sun, NOT the wide
 * wordmark) that components/BrandLoader.tsx shows.
 *
 * Run with: node scripts/build-brand-marks.mjs
 *
 * The masters are the official PNGs in public/brand/ (provatferi-icon-light.png for light surfaces,
 * provatferi-icon-dark.png for dark ones — see COLOR_AND_LOGO_GUIDELINES.md §4). They are ~850 px and
 * ~60 KB each, far more than a 30-76 px loader needs, and a loader has to be on screen the instant it is
 * wanted, so this makes a 256 px copy of each: sharp enough for a 76 px mark on a 3x phone, ~7 KB.
 *
 * It only RESIZES. The artwork is not redrawn, recoloured, cropped, stretched or re-balanced: each master
 * is fitted into a transparent square (it is 834x860 / 851x860, nearly square already) and scaled down
 * with Lanczos, then palette-quantised (the mark is flat colour). If a master changes, run this again —
 * the 256 px copies are build products of the masters, never edited by hand.
 */
import sharp from "sharp";
import { stat } from "node:fs/promises";
import { fileURLToPath } from "node:url";
import path from "node:path";

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..");
const BRAND = path.join(root, "public", "brand");
const SIZE = 256;

for (const variant of ["light", "dark"]) {
  const master = path.join(BRAND, `provatferi-icon-${variant}.png`);
  const out = path.join(BRAND, `provatferi-icon-${variant}-${SIZE}.png`);
  await sharp(master)
    .resize(SIZE, SIZE, { fit: "contain", background: { r: 0, g: 0, b: 0, alpha: 0 }, kernel: "lanczos3" })
    .png({ palette: true, quality: 95, effort: 10, compressionLevel: 9 })
    .toFile(out);
  const { size } = await stat(out);
  console.log(`${path.basename(out).padEnd(34)} ${(size / 1024).toFixed(1).padStart(6)} KB   (from ${path.basename(master)})`);
}
