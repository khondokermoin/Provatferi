{{--
    THE TYPOGRAPHY OF EVERY GENERATED DOCUMENT — one definition, included at the top of each document's own <style>
    (payment receipts, recruitment application copies; docs/PDF_BENGALI_STANDARD.md). The same markup is rendered by mPDF for
    the PDF and by the browser for the print view, so this is plain CSS 2: no variables, no flexbox/grid, and — the trap that
    cost the recruitment copy its bold headings and its 80 px photo — no rule that reaches a <div>/<img> inside a table cell
    through a descendant selector (".header .name"): mPDF silently ignores those. Give such elements a class of their own.

    The scale (pt): body 10.5 · table text and long text 10 · small print 8.5–9.5 · section heading 10.5–11 bold · a
    document's own title 14–18 bold. Line height 1.5 everywhere. Bold is the real Bold file, never synthesised.
    Colours: ink #201B17 · muted #6B5F53 · faint #A89F94 · accent #AC350A · rule #E6DFD5 · fill #FAF7F2.

    The family name is the mPDF font key (PdfRenderer): Noto Sans Bengali 2.001 with OpenType shaping for Bengali, 3.011
    for the Latin letters, digits and punctuation the 2.001 file lacks, DejaVu Sans for ✓ ✗. The browser print layout maps
    every element onto the self-hosted WOFF2 of the same typeface (layouts/print.blade.php).
--}}
    body { font-family: {{ \App\Services\Pdf\PdfRenderer::FONT_BENGALI }}, sans-serif; font-size: 10.5pt; color: #201B17; line-height: 1.5; }

    /* Label / value tables: the receipt's "from whom" and "details", the application's contact and attachment blocks. */
    table.rc-fields, table.fields { width: 100%; border-collapse: collapse; }
    table.rc-fields th, table.rc-fields td, table.fields th, table.fields td { border: 1px solid #E6DFD5; padding: 6px 9px; text-align: left; vertical-align: top; font-size: 10pt; }
    table.rc-fields th, table.fields th { width: 34%; background-color: #FAF7F2; font-weight: bold; color: #4A4038; }
