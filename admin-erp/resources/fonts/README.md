# Fonts used by the admin's PDF documents and print views

All four files are **Noto Sans Bengali**, released under the SIL Open Font License 1.1
(<https://openfontlicense.org>) by the Noto Project authors — Google (2.001) and notofonts/bengali (3.011). They never leave the
application: nothing is fetched from, or sent to, a font service.

| File | Release | Used for |
|---|---|---|
| `NotoSansBengali-Regular.ttf`, `NotoSansBengali-Bold.ttf` | 3.011 (2025), **unmodified** | The browser print view (converted to WOFF2 by `deploy/build-print-font.mjs`) and, in the PDF, every **Latin letter, digit and punctuation mark** the Bengali file lacks (mPDF's glyph substitution). |
| `NotoSansBengali-Shaping-Regular.ttf`, `NotoSansBengali-Shaping-Bold.ttf` | 2.001 (2017), **name table only** changed | The **Bengali** in every PDF (`App\Services\Pdf\PdfRenderer`). No Latin glyphs. |

`FONTS.json` records every file — release, SHA-256, SHA-256 of every table, PostScript name, and for the two derived files the
SHA-256 of the upstream file they come from. `tests/Feature/Pdf/PdfFontFilesTest` and `php artisan pdf:self-check` hold the
files to it. Regenerate it with `node deploy/fonts-manifest.mjs` — only after looking at rendered pages again.

## Why two releases of one typeface

mPDF 8.3.1 can only typeset Bengali correctly (vowel signs on the right side of their consonants, conjuncts joined) with its
OpenType engine switched on for the font, and that engine cannot read release 3.011: its GDEF 1.2 table is read at the
wrong offset and it rejects the newer lookup formats. Release 2.001 (GDEF 1.0, classic lookups) is read and shaped
correctly, but has no Latin letters — hence the pairing.

## Why the 2.001 files have a different internal name

mPDF names every embedded font `MPDFAA+<PostScript name>`. With both releases called `NotoSansBengali-Regular`, a receipt held two
different font programs under one name — forbidden by the PDF specification, and a viewer that identifies fonts by name can draw
glyphs of one from the other. `deploy/patch-font-names.mjs` rewrites the `name` table (IDs 3, 4, 6) of the 2.001 files to
`NotoSansBengaliShaping-Regular|Bold` and nothing else (glyphs, cmap, GSUB, GPOS, GDEF, metrics are byte-identical to upstream;
the manifest keeps the upstream hashes).

## Changing any of these files

Look at the rendered pages again — `node deploy/qa/pdf-shaping-qa.mjs --sets fixture,lang --control` compares them with a HarfBuzz
rendering — then regenerate `FONTS.json`, record the new glyph counts (`php artisan pdf:self-check --write-golden`) and bump
`CACHE_VERSION` in `App\Services\Pdf\PdfRenderer`. The whole story: `docs/PDF_BENGALI_STANDARD.md`.
