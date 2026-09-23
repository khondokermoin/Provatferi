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
        assumption. A 419/500/503 can happen to a signed-out visitor or
        mid-outage, when pulling in the full admin bundle would be both
        wasteful and, for 500/503, possibly part of what's broken.
    --}}
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Bengali:wght@400;500;600;700&display=swap" rel="stylesheet">

    <style>
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
        .error-card { max-width: 460px; text-align: center; }
        .error-card img { height: 44px; margin-bottom: 22px; }
        .logo-dark { display: none; }
        @media (prefers-color-scheme: dark) {
            .logo-light { display: none; }
            .logo-dark { display: inline-block; }
        }
        .error-code { font-size: 14px; font-weight: 700; letter-spacing: .08em; color: var(--accent); margin: 0 0 10px; }
        .error-card h1 { font-size: 22px; color: var(--heading); margin: 0 0 12px; }
        .error-card p.message { font-size: 15px; line-height: 1.75; color: var(--muted); margin: 0 0 26px; }
        .error-actions { display: flex; flex-wrap: wrap; justify-content: center; gap: 10px; }
        .error-actions a, .error-actions button {
            font-family: inherit; font-size: 14px; font-weight: 600; cursor: pointer;
            padding: 11px 20px; border-radius: 8px; text-decoration: none; line-height: 1.3;
            border: 1px solid var(--border); background: var(--card); color: var(--heading);
        }
        .error-actions .primary { background: var(--accent); border-color: var(--accent); color: #fff; }
        .error-actions .primary:hover { background: var(--accent-hover); }
    </style>
</head>
<body>
    <div class="error-card">
        <img src="{{ asset('brand/provatferi-logo-light.png') }}" alt="Provatferi" class="logo-light" style="display: block; margin-inline: auto;">
        <img src="{{ asset('brand/provatferi-logo-dark.png') }}" alt="Provatferi" class="logo-dark" style="margin-inline: auto;">
        <p class="error-code">@yield('code')</p>
        <h1>@yield('heading')</h1>
        <p class="message">@yield('message')</p>
        <div class="error-actions">
            @yield('actions')
        </div>
    </div>
</body>
</html>
