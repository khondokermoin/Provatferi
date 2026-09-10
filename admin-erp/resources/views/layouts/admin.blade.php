<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}"
      data-bs-theme="light"
      data-menu-color="light"
      data-topbar-color="light"
      data-layout-mode="fluid"
      data-sidenav-size="default">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $title ?? 'Dashboard' }} — Provatferi ERP</title>
    <meta name="description" content="Provatferi Literary and Cultural Center — administration panel.">
    {{-- Matches the institutional site's icon setup: OS-level prefers-color-scheme
         switches the mark, independent of this panel's own light/dark toggle
         (which the OS can't see ahead of first paint). --}}
    <link rel="icon" href="{{ asset('brand/provatferi-icon-light.png') }}" type="image/png" media="(prefers-color-scheme: light)">
    <link rel="icon" href="{{ asset('brand/provatferi-icon-dark.png') }}" type="image/png" media="(prefers-color-scheme: dark)">
    <link rel="shortcut icon" href="{{ asset('favicon.ico') }}" type="image/x-icon">
    <link rel="apple-touch-icon" href="{{ asset('brand/provatferi-icon-light.png') }}">

    {{--
        Pre-paint theme: runs before config.js and before first paint, so there
        is no flash of the wrong theme. Explicit localStorage choice wins;
        otherwise follow the OS. Mirrors public/js/provatferi-theme.js.
    --}}
    <script>
        (function () {
            // Zircos app.js writes __ZIRCOS_CONFIG__ to sessionStorage on every
            // load, and config.js replays it — which would override both our
            // stored choice and the OS preference. Clearing it here (before
            // config.js runs) makes localStorage the single source of truth
            // without modifying any vendor file.
            try { sessionStorage.removeItem('__ZIRCOS_CONFIG__'); } catch (e) {}

            var t = null;
            try { t = localStorage.getItem('provatferi-admin-theme'); } catch (e) {}
            if (t !== 'light' && t !== 'dark') {
                t = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
            }
            var r = document.documentElement;
            r.setAttribute('data-bs-theme', t);
            r.setAttribute('data-menu-color', t === 'dark' ? 'dark' : 'light');
            r.setAttribute('data-topbar-color', t === 'dark' ? 'dark' : 'light');
        })();
    </script>

    {{-- Zircos theme bootstrap: reads the attributes we just set. --}}
    <script src="{{ asset('zircos/js/config.js') }}"></script>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Bengali:wght@400;500;600;700&display=swap" rel="stylesheet">

    <link href="{{ asset('zircos/css/vendor.min.css') }}" rel="stylesheet">
    <link href="{{ asset('zircos/css/app.min.css') }}" rel="stylesheet" id="app-style">
    <link href="{{ asset('zircos/css/icons.min.css') }}" rel="stylesheet">
    {{-- Brand layer last so it wins over the template. --}}
    <link href="{{ asset('zircos/css/provatferi-admin.css') }}" rel="stylesheet">
    @stack('styles')
</head>
<body>
<div class="wrapper">
    <x-admin.sidebar />
    <x-admin.header />

    <div class="page-content">
        <div class="page-container">
            <x-admin.page-title :title="$title ?? 'Dashboard'" :breadcrumbs="$breadcrumbs ?? []">
                @hasSection('page-actions')
                    @yield('page-actions')
                @endif
            </x-admin.page-title>

            <x-admin.flash-message />

            @yield('content')
        </div>

        <footer class="footer">
            <div class="page-container">
                <div class="row">
                    <div class="col-12 text-center text-muted">
                        &copy; {{ date('Y') }} প্রভাতফেরী সাহিত্য ও সাংস্কৃতিক কেন্দ্র — প্রশাসনিক প্যানেল
                    </div>
                </div>
            </div>
        </footer>
    </div>
</div>

<script src="{{ asset('zircos/js/vendor.min.js') }}"></script>
<script src="{{ asset('zircos/js/app.js') }}"></script>
{{-- After Zircos, so our control owns the theme state. --}}
<script src="{{ asset('js/provatferi-theme.js') }}"></script>
@stack('scripts')
</body>
</html>
