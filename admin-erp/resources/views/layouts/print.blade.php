<!DOCTYPE html>
<html lang="bn">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $title ?? 'আবেদনপত্র' }} — Provatferi ERP</title>
    <link rel="icon" href="{{ asset('brand/provatferi-icon-light.png') }}" type="image/png">

    <style>
        /* Self-hosted — no fonts.googleapis.com/fonts.gstatic.com request.
           Same TTF source as mPDF's own font (resources/fonts/), converted to
           WOFF2 by deploy/build-print-font.mjs, so the print preview and the
           PDF are set in the literal same typeface, not look-alikes. */
        @font-face {
            font-family: 'Noto Sans Bengali';
            font-style: normal; font-weight: 400;
            src: url('{{ asset('brand/fonts/NotoSansBengali-Regular.woff2') }}') format('woff2');
            font-display: swap;
        }
        @font-face {
            font-family: 'Noto Sans Bengali';
            font-style: normal; font-weight: 700;
            src: url('{{ asset('brand/fonts/NotoSansBengali-Bold.woff2') }}') format('woff2');
            font-display: swap;
        }

        @page { size: A4; margin: 15mm; }
        * { box-sizing: border-box; }
        html, body { margin: 0; padding: 0; background: #F4F1EC; }
        body { font-family: 'Noto Sans Bengali', sans-serif; color: #201B17; }

        .print-toolbar {
            position: sticky; top: 0; z-index: 10;
            display: flex; justify-content: flex-end; gap: 8px;
            padding: 10px 16px; background: #FFFFFF; border-bottom: 1px solid #E6DFD5;
        }
        .print-toolbar button, .print-toolbar a {
            font-family: 'Noto Sans Bengali', sans-serif; font-size: 13px; cursor: pointer;
            padding: 7px 14px; border-radius: 6px; border: 1px solid #D8CFC2;
            background: #FFFFFF; color: #201B17; text-decoration: none; line-height: 1.3;
        }
        .print-toolbar button.primary { background: #AC350A; border-color: #AC350A; color: #fff; }

        .print-page {
            max-width: 210mm; margin: 20px auto 40px; background: #FFFFFF;
            padding: 15mm; box-shadow: 0 1px 4px rgba(32,27,23,0.12);
        }

        /* notosansbengali is what document.blade.php's own inline <style>
           targets — mapped here to the self-hosted @font-face of the same
           family, so print preview and the mPDF-rendered PDF are set in the
           literal same font file's glyphs, not merely similar-looking ones. */
        .print-page, .print-page * { font-family: 'Noto Sans Bengali', sans-serif; }

        @media print {
            .print-toolbar { display: none; }
            html, body { background: #FFFFFF; }
            .print-page { box-shadow: none; margin: 0; padding: 0; max-width: none; }
        }
    </style>
</head>
<body>
    <div class="print-toolbar no-print">
        {{-- This standalone layout never loads the admin panel's icon font —
             plain text only, not an icon glyph that would silently render blank. --}}
        <button type="button" class="primary" onclick="window.print()">প্রিন্ট করুন</button>
        <button type="button" onclick="window.close()">বন্ধ করুন</button>
    </div>

    <div class="print-page">
        @yield('content')
    </div>
</body>
</html>
