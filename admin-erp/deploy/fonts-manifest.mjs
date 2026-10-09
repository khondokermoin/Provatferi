/**
 * Build-time only: writes resources/fonts/FONTS.json, the record of the font files the PDF engine embeds — release, whole-file
 * SHA-256, SHA-256 of every table, and where each file comes from. tests/Unit/Pdf/PdfFontFilesTest.php holds the files to it:
 * a font that was swapped, re-exported, subset or edited changes a hash, and the test says so before a receipt does.
 *
 * Run (after changing a font file): node deploy/fonts-manifest.mjs
 * The two "Shaping" files are Noto Sans Bengali 2.001 with only the name table rewritten (deploy/patch-font-names.mjs);
 * their upstream hashes are the ones recorded below — keep them when regenerating.
 */
import crypto from "node:crypto";
import fs from "node:fs";
import path from "node:path";
import { readName, readTables } from "./patch-font-names.mjs";

const ROOT = path.resolve(import.meta.dirname, "..");
const DIR = path.join(ROOT, "resources/fonts");
const OUT = path.join(DIR, "FONTS.json");

const sha = (data) => crypto.createHash("sha256").update(data).digest("hex");

const FILES = {
  "NotoSansBengali-Shaping-Regular.ttf": {
    role: "Bengali glyphs, shaped by mPDF (useOTL) — the Bengali of every PDF",
    release: "Noto Sans Bengali 2.001 (2017, Monotype / Google), OFL 1.1",
    derived: "name table only: PostScript name NotoSansBengaliShaping-Regular (deploy/patch-font-names.mjs)",
    upstream_sha256: "0841c3c2642810eaed9540b40703348e3d133e98e3df2ae1bbc159f24135b25a",
  },
  "NotoSansBengali-Shaping-Bold.ttf": {
    role: "Bengali glyphs, bold, shaped by mPDF (useOTL)",
    release: "Noto Sans Bengali 2.001 (2017, Monotype / Google), OFL 1.1",
    derived: "name table only: PostScript name NotoSansBengaliShaping-Bold (deploy/patch-font-names.mjs)",
    upstream_sha256: "75ab012faf1c9e1f4b83fe33f6f1cd011f714fd7e2f932c6cce355e02cf7e17f",
  },
  "NotoSansBengali-Regular.ttf": {
    role: "Latin letters, digits and punctuation in PDFs (glyph substitution); the browser print view's WOFF2 source",
    release: "Noto Sans Bengali 3.011 (2025, Noto Project Authors), OFL 1.1",
    derived: null,
  },
  "NotoSansBengali-Bold.ttf": {
    role: "Bold Latin letters, digits and punctuation in PDFs; the browser print view's WOFF2 source",
    release: "Noto Sans Bengali 3.011 (2025, Noto Project Authors), OFL 1.1",
    derived: null,
  },
};

const fonts = {};
for (const [file, info] of Object.entries(FILES)) {
  const bytes = fs.readFileSync(path.join(DIR, file));
  const { tables } = readTables(bytes);
  const name = tables.find((t) => t.tag === "name").data;
  fonts[file] = {
    ...info,
    version: readName(name, 5),
    postscript_name: readName(name, 6),
    bytes: bytes.length,
    sha256: sha(bytes),
    upstream_sha256: info.upstream_sha256 ?? sha(bytes),
    tables_sha256: Object.fromEntries(tables.map((t) => [t.tag, sha(t.data)])),
  };
}

// The browser print view's WOFF2 files (public/brand/fonts, built by deploy/build-print-font.mjs from the 3.011 files): recorded by
// hash so a regenerated or edited one is noticed. Their layout tables were checked equal to the TTF's when they were built
// (docs/PDF_BENGALI_STANDARD.md).
const PRINT = path.join(ROOT, "public/brand/fonts");
const print_fonts = {};
for (const file of fs.readdirSync(PRINT).filter((f) => f.startsWith("NotoSansBengali-") && f.endsWith(".woff2")).sort()) {
  const bytes = fs.readFileSync(path.join(PRINT, file));
  print_fonts[file] = { source: file.replace(".woff2", ".ttf") + " (3.011) through deploy/build-print-font.mjs", bytes: bytes.length, sha256: sha(bytes) };
}

fs.writeFileSync(OUT, JSON.stringify({ fonts, print_fonts }, null, 2) + "\n");
console.log(`wrote ${path.relative(ROOT, OUT)} (${Object.keys(fonts).length} fonts, ${Object.keys(print_fonts).length} print fonts)`);
