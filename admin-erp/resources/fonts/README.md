# Fonts used by the admin's PDF documents and print views

All four files are **Noto Sans Bengali**, released under the SIL Open Font License 1.1
(<https://openfontlicense.org>) by the Noto Project authors — Google (2.001) and notofonts/bengali (3.011).

| File | Release | Used for |
|---|---|---|
| `NotoSansBengali-Regular.ttf`, `NotoSansBengali-Bold.ttf` | 3.011 (2025) | The browser print view (converted to WOFF2 by `deploy/build-print-font.mjs`) and, in the PDF, every **Latin letter and digit** (mPDF's glyph substitution). Carries Latin glyphs. |
| `NotoSansBengali-Shaping-Regular.ttf`, `NotoSansBengali-Shaping-Bold.ttf` | 2.001 (2017) | The **Bengali** in every PDF (`App\Services\RecruitmentPdfService`). No Latin glyphs. |

## Why two releases of one typeface

mPDF 8.3.1 can only typeset Bengali correctly (vowel signs on the right side of their consonants, conjuncts joined) with its
OpenType engine switched on for the font, and that engine cannot read release 3.011: its GDEF 1.2 table is read at the
wrong offset and it rejects the newer lookup formats. Release 2.001 (GDEF 1.0, classic lookups) is read and shaped
correctly, but has no Latin letters — hence the pairing. See the class comment in `RecruitmentPdfService` for the full story and
`PaymentReceiptDocumentTest::test_the_bengali_in_the_pdf_is_shaped_not_merely_drawn` for the guard.

**Changing any of these files means looking at a rendered page again** (`institutional/scripts/pdf-rasterize.mjs`), and bumping
`SHAPING_CACHE_VERSION` in `RecruitmentPdfService` when a Shaping file changes.
