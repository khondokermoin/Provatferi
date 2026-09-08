/**
 * Generates the production background-texture variants from the master PNG.
 *
 * Run with: node scripts/build-textures.mjs
 *
 * The dark variant is a controlled per-channel tonal remap, NOT an inversion:
 * the source's [min..255] band is compressed into a dark charcoal band anchored
 * on the dark theme's --bg. Bright floral stays brighter than the ground, so the
 * pattern reads the same way it does in light mode, just at dark luminance.
 */
import sharp from "sharp";
import { mkdir, stat } from "node:fs/promises";
import { fileURLToPath } from "node:url";
import path from "node:path";

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..");
const MASTER = path.join(root, "..", "Provatferi Backgrounds", "provatferi-floral-light-master.png");
const OUT = path.join(root, "public", "textures");

// Dark theme --bg (#1b1e22) as the ground, with the brightest floral landing
// just above --surface (#24282e) so the texture stays a whisper, not a pattern.
const DARK_GROUND = [27, 30, 34];
const DARK_SPAN = [13, 15, 18];

const SIZES = [
  { name: "", width: 1254 },
  { name: "-sm", width: 627 },
];

async function report(file) {
  const { size } = await stat(file);
  console.log(`  ${path.basename(file).padEnd(36)} ${(size / 1024).toFixed(1).padStart(7)} KB`);
}

async function main() {
  await mkdir(OUT, { recursive: true });

  const { channels } = await sharp(MASTER).stats();
  const mins = channels.map((c) => c.min);
  const a = DARK_SPAN.map((span, i) => span / (255 - mins[i]));
  const b = DARK_GROUND.map((ground, i) => ground - a[i] * mins[i]);

  console.log("Source per-channel min:", mins.join(", "));
  console.log("Dark remap  a:", a.map((v) => v.toFixed(4)).join(", "));
  console.log("Dark remap  b:", b.map((v) => v.toFixed(2)).join(", "));

  // Dark master, kept alongside the light master so the pair is reproducible.
  const darkMaster = path.join(root, "..", "Provatferi Backgrounds", "provatferi-floral-dark-master.png");
  await sharp(MASTER).linear(a, b).png({ compressionLevel: 9 }).toFile(darkMaster);
  console.log("\nDark master written:");
  await report(darkMaster);

  for (const [theme, source] of [["light", MASTER], ["dark", darkMaster]]) {
    console.log(`\n${theme}:`);
    for (const { name, width } of SIZES) {
      const base = path.join(OUT, `provatferi-floral-${theme}${name}`);
      const resized = () => sharp(source).resize({ width, withoutEnlargement: true });

      // Settings picked by measuring per-pixel error against the master: this
      // image's whole dynamic range is only ~47 levels, so quality was chosen
      // where error stops improving meaningfully. 4:2:0 is free here because
      // the source is very nearly achromatic.
      await resized().avif({ quality: 55, effort: 9, chromaSubsampling: "4:2:0" }).toFile(`${base}.avif`);
      await report(`${base}.avif`);

      await resized().webp({ quality: 80, effort: 6 }).toFile(`${base}.webp`);
      await report(`${base}.webp`);
    }
  }
}

main();
