<!DOCTYPE html>
<html lang="bn">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'ত্রুটি') — Provatferi ERP</title>
    <link rel="icon" href="{{ asset('brand/provatferi-icon-light.png') }}" type="image/png" media="(prefers-color-scheme: light)">
    <link rel="icon" href="{{ asset('brand/provatferi-icon-dark.png') }}" type="image/png" media="(prefers-color-scheme: dark)">

    {{--
        Deliberately standalone — no Zircos vendor CSS, no admin session
        assumption, no third-party font request. A 419/500/503 can happen to
        a signed-out visitor or mid-outage, when pulling in the full admin
        bundle — or depending on fonts.googleapis.com resolving — would be
        both wasteful and, for 500/503, possibly part of what's already broken.
    --}}
    <style>
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

        :root {
            --bg: #FFFDF8; --card: #FFFFFF; --border: #E6DFD5; --heading: #201B17;
            --body: #4A4038; --muted: #6B5F53; --accent: #AC350A; --accent-hover: #8F2C08;
        }
        @media (prefers-color-scheme: dark) {
            :root { --bg: #1B1712; --card: #23201A; --border: #3A3226; --heading: #F5EFE6; --body: #D8CFC2; --muted: #A89A85; --accent: #E2703A; --accent-hover: #EF8A57; }
        }
        * { box-sizing: border-box; }
        html, body { margin: 0; height: 100%; }
        body {
            font-family: 'Noto Sans Bengali', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: var(--bg); color: var(--body);
            display: flex; align-items: center; justify-content: center; padding: 24px;
        }

        .error-card { max-width: 420px; width: 100%; text-align: center; }

        {{-- The small icon mark only — not the full text logo. The full
             wordmark already spells out "প্রভাতফেরী..." in the image itself,
             which read as a second, redundant announcement of the org name
             sitting directly above the Bengali heading text. One brand
             signal (this icon), one heading — never both forms at once. --}}
        .error-mark { height: 34px; margin-bottom: 20px; }
        .mark-dark { display: none; }
        @media (prefers-color-scheme: dark) {
            .mark-light { display: none; }
            .mark-dark { display: inline-block; }
        }

        .error-code { font-size: 13px; font-weight: 700; letter-spacing: .1em; color: var(--accent); margin: 0 0 8px; font-variant-numeric: tabular-nums; }
        .error-card h1 { font-size: 21px; font-weight: 700; color: var(--heading); margin: 0 0 10px; line-height: 1.35; }
        .error-card p.message { font-size: 14.5px; line-height: 1.7; color: var(--muted); margin: 0 0 24px; }
        .error-actions { display: flex; flex-wrap: wrap; justify-content: center; gap: 10px; }
        .error-actions a, .error-actions button {
            font-family: inherit; font-size: 14px; font-weight: 600; cursor: pointer;
            padding: 10px 18px; border-radius: 8px; text-decoration: none; line-height: 1.3;
            border: 1px solid var(--border); background: var(--card); color: var(--heading);
        }
        .error-actions .primary { background: var(--accent); border-color: var(--accent); color: #fff; }
        .error-actions .primary:hover { background: var(--accent-hover); }

        @media (max-width: 380px) {
            .error-actions { flex-direction: column; }
            .error-actions a, .error-actions button { width: 100%; }
        }
    </style>
</head>
<body>
    <div class="error-card">
        <img src="{{ asset('brand/provatferi-icon-light.png') }}" alt="" class="error-mark mark-light">
        <img src="{{ asset('brand/provatferi-icon-dark.png') }}" alt="" class="error-mark mark-dark">
        <p class="error-code">@yield('code')</p>
        <h1>@yield('heading')</h1>
        <p class="message">@yield('message')</p>
        <div class="error-actions">
            @yield('actions')
        </div>
    </div>
</body>
</html>
