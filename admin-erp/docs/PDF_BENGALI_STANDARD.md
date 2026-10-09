# The Bengali PDF standard

One engine, one typography configuration, every generated PDF. Written 2026-10-10, after the owner looked at rendered receipts
and application copies and found Bengali that was "not consistently production-quality": split vowel signs, visible hasantas,
unformed conjuncts. Extracted text was correct every time — **only the rendered page shows shaping**, so everything below is
checked on pixels, and the extraction-based checks are labelled for what they are.

## 1. What generates PDFs (the audit)

| Document | Route / entry | Template | Engine & font |
|---|---|---|---|
| Payment receipt (admin, member portal) | `Admin\PaymentReceiptController::pdf`, `Api\V1\Member\ReceiptController::pdf` → `PaymentReceiptPdfService::pdf()` | `admin/membership/receipts/document.blade.php` (+ `admin/pdf/_typography`) | `App\Services\Pdf\PdfRenderer` (mPDF 8.3.1) — Noto Sans Bengali 2.001 shaped, 3.011 Latin, DejaVu Sans ✓ ✗ |
| Recruitment / volunteer application copy | `Admin\JobApplicationController::pdf` | `admin/recruitment/applications/document.blade.php` (+ `admin/pdf/_typography`) | the same `PdfRenderer`, the same fonts |
| Browser print views of both | `…::print`, `PaymentReceiptController::show` | the same two document partials inside `layouts/print.blade.php` | the browser: self-hosted Noto Sans Bengali 3.011 WOFF2 (`public/brand/fonts`), shaped by HarfBuzz |

Nothing else in the repository generates a PDF: committee and notice PDFs are **uploaded** files that are stored and served
(`ApplicationDocumentService`, `NoticeFileService`); there is no other PDF library in `composer.json` or `package.json`
(`puppeteer-core` is a dev tool for QA, not a runtime dependency). The institutional site (`institutional/`) generates none.

## 2. The standard

* **Engine: mPDF**, the only one. mPDF has its own OpenType layout engine; it is **off unless a font asks for it** (`useOTL`).
* **Bengali: Noto Sans Bengali 2.001** (`resources/fonts/NotoSansBengali-Shaping-{Regular,Bold}.ttf`) with `useOTL => 0xFF`:
  reordering of pre-base vowel signs, reph, conjunct and half forms from the font's GSUB/GPOS. mPDF 8.3.1 cannot read release
  3.x with shaping on (GDEF 1.2 is read at the wrong offset; GPOS lookup type 5 format 3 is rejected), so 3.x is not an option for Bengali.
* **Latin letters, digits, punctuation** the 2.001 file lacks: Noto Sans Bengali 3.011 through mPDF's glyph substitution
  (`useSubstitutions` + `backupSubsFont`) — the same typeface the browser print view uses. **✓ ✗** come from DejaVu Sans (mPDF's own `ttfonts`).
* **Every font is embedded** (as a subset) and **every program has its own PostScript name** — see §4. No viewer font, no browser
  font, no remote request. mPDF's built-in substitution of *core* fonts (Helvetica for `·` `–` `—`, ZapfDingbats for ✓ ✗ — never
  embedded, so the viewer draws them) is switched off (`subArrMB`).
* **Font keys of their own** (`provatferibn`, `provatferilatin`, `provatferisymbols`); the CSS `font-family` in the documents is `provatferibn`.
* **No unshaped fallback.** A configuration that cannot shape Bengali throws `PdfEngineException` (logged); it never hands out a document.
* **Images are passed to the engine**, not written into the HTML: `render($html, $title, ['photo' => $bytes])` and `<img src="var:photo">`.
  `PdfImagePreparer` makes a photo PDF-sized first (centred square, upright by its EXIF tag, 360 px, JPEG ≈ 75 KB).
* **Typography** is one partial, `admin/pdf/_typography.blade.php`: body 10.5 pt / line height 1.5, tables 10 pt, small print
  8.5–9.5 pt, headings 10.5–11 pt bold, a document's own title 14–18 pt bold, one set of colours, one label/value table style.
  Plain CSS 2 — mPDF has no flexbox, grid or variables.
* mPDF's `autoScriptToLang` / `autoLangToFont` stay **off**: with a custom font registered they routed bold text to a core font with no Bengali glyphs.

## 3. Why it was broken (root cause)

Three independent defects, found by comparing rendered pages with a HarfBuzz rendering of the same font (not by reading text):

1. **Unshaped documents after a failure — the "right, wrong, right again".** mPDF shapes Bengali from OpenType data it parsed and
   cached per *font key* (`vendor/mpdf/mpdf/tmp/mpdf/ttfontdata`). Its `FontCache::jsonLoad()` memoises every JSON file it reads
   for the life of the process and `jsonWrite()` never invalidates the memo. So whenever mPDF *regenerates an existing cache
   entry* — the font file changed, `useOTL` changed, or another configuration wrote the same key — that document is built from the
   **stale** copy: no GSUB/GPOS script data, therefore no shaping (`GSUBScriptLang=[]`, `GSUBLookups=0` in memory, a perfect
   file on disk). The old service entered an unshaped configuration — the **same font key** with the 3.x files and no OpenType
   layout — on *any* exception, an oversized photo included, so **the next shaped document in any process after such an event came
   out unshaped**, and the next one after that was fine. Applicants' phone photos (2–5 MB) made the event routine. A one-time
   "warm" marker said the cache was ready throughout.
2. **The recruitment PDF's CSS was half ignored.** mPDF silently ignores a descendant selector that reaches a `<div>`/`<img>`
   inside a table cell (`.doc-header .org-name-bn`, `.applicant-block .photo-cell img`): the headings lost their bold, the name
   its 13 pt and **the photo its 80 px box** — it was drawn at natural size, a page-filling square.
3. **A large photo made the PDF a 500.** The photo went into the HTML as base64; over about 700 KB the HTML exceeded
   `pcre.backtrack_limit` (1,000,000) and mPDF threw. That exception was caught by the fallback in (1), which made things worse.

Smaller findings fixed on the way: two different font programs embedded under one name (`MPDFAA+NotoSansBengali-Regular` for both the
Bengali and the Latin face — forbidden by the PDF spec, a viewer that identifies fonts by name can mix them up); `·` and ✓ ✗ drawn from
viewer-supplied core fonts; the receipt's accent rule drawn as an `<hr>` (mPDF ignores its border: grey in the PDF, orange in the browser).

## 4. The font files (verified)

`resources/fonts/FONTS.json` records, per file: release, SHA-256, every table's SHA-256, PostScript name — written by
`node deploy/fonts-manifest.mjs`; `PdfFontFilesTest` and `php artisan pdf:self-check` hold the files to it (tables present — GSUB,
GPOS, GDEF 1.0 with a `bng2` script and lookups for the shaping files —, Regular = weight 400 and Bold = 700, every assigned Bengali
code point and the Latin range covered, not a subset, unique PostScript names).

| File | Release | Role | Notes |
|---|---|---|---|
| `NotoSansBengali-Shaping-Regular.ttf` / `-Bold.ttf` | 2.001 (2017) | Bengali glyphs + shaping | **name table only** differs from upstream: PostScript name `NotoSansBengaliShaping-…` (`deploy/patch-font-names.mjs`; every other table byte-identical — upstream hashes are in the manifest) |
| `NotoSansBengali-Regular.ttf` / `-Bold.ttf` | 3.011 (2025) | Latin / digits / punctuation; source of the print WOFF2 | unmodified |
| `public/brand/fonts/NotoSansBengali-*.woff2` | from 3.011 | browser print view | built by `deploy/build-print-font.mjs` with `keepAllGlyphs`; GSUB/GPOS/GDEF verified intact (same sizes as the TTF), recorded by hash |

Font files are never sent anywhere; all are OFL 1.1 and live in this repository.

## 5. How to check it

| Check | Proves | Doesn't prove |
|---|---|---|
| `php artisan pdf:self-check` (also run by `release-manager.php smoke-test-isolated`, which therefore blocks a release that cannot shape Bengali and warms the font cache) | files are the recorded ones, cache complete, every fixture word drawn as recorded, fonts embedded and uniquely named | that the recorded shaping is *right* |
| `php artisan test tests/Feature/Pdf` | the same + the failure of §3.1 (poisoned cache), damaged/missing cache files, image handling, no unshaped output ever | the same |
| **`node deploy/qa/pdf-shaping-qa.mjs --sets fixture,lang --control`** | **shaping is right**: every word of the fixture (and of every Bengali string the product can print, ~1,200) rasterised and compared with a Chrome/HarfBuzz rendering of the same font; the unshaped control must be flagged | spacing of whole documents |
| look at the pages: `institutional/scripts/pdf-rasterize.mjs` (pdf.js), or PDFium/MuPDF | the document as a human sees it | — |

Run the visual QA before **any** change to the fonts, `PdfRenderer`, `PdfFontCache` or mPDF (needs Chrome and `npm install` in
`admin-erp`). Then, and only then, `php artisan pdf:self-check --write-golden` records the new glyph counts
(`resources/pdf-qa/bengali-shaping-glyphs.json`), `deploy/fonts-manifest.mjs` the new hashes, and `PdfRenderer::CACHE_VERSION` is bumped.

Result when this was written: 247 fixture words and 1,212 product words, regular and bold, **0 differ** from HarfBuzz — apart
from a malformed input, `অা` (a vowel letter followed by a vowel sign), where HarfBuzz inserts a dotted circle and mPDF does not;
the unshaped control: 78 % of the fixture flagged. A PDF viewed through pdf.js, PDFium and MuPDF renders identically.

## 6. Rules for changing things

* A font file changes → visual QA, then manifest + golden + `CACHE_VERSION`. A font that mPDF must read has to be GDEF ≤ 1.0 with classic lookups.
* Never register the same font key twice with different files or options (`PdfFontCache` explains what that does to the next document).
* Never put an image in the HTML as base64; never raise `pcre.backtrack_limit` to make something fit.
* Document CSS: one class per element, no descendant selector to a `<div>`/`<img>` in a table cell, `<div>` with a border instead of `<hr>`,
  tables not flexbox. Look at the rendered page (both languages) after any change.
* A symbol the three fonts lack falls through to nothing, not to a core font: add it to the audit lists in `PdfShapingCheck`.

## 7. Known limits

* In a shaped PDF the **text layer of Bengali words is fragments** (conjuncts have no Unicode value; vowel signs are in visual order):
  copying Bengali out of a PDF gives pieces. Latin text, digits and amounts extract normally. The print view's text is intact.
* A malformed Bengali sequence (a dependent vowel sign without a consonant, e.g. `অা`) has no dotted circle in the PDF; the browser shows one.
* A photo that GD cannot decode, or that would not fit in `memory_limit`, is left out and the document shows its "no photo" placeholder.
* The first document after a deploy builds mPDF's font cache (≈ 3 s locally, under a lock); `smoke-test-isolated` does that before the switch.
