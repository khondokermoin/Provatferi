<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-bs-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $title ?? 'Sign in' }} — Provatferi ERP</title>
    <meta name="robots" content="noindex, nofollow">
    <link rel="icon" href="{{ asset('brand/provatferi-icon-light.png') }}" type="image/png" media="(prefers-color-scheme: light)">
    <link rel="icon" href="{{ asset('brand/provatferi-icon-dark.png') }}" type="image/png" media="(prefers-color-scheme: dark)">
    <link rel="shortcut icon" href="{{ asset('favicon.ico') }}" type="image/x-icon">

    {{-- Same pre-paint rule as the admin layout: explicit choice, else OS. --}}
    <script>
        (function () {
            // See admin layout: stops Zircos replaying its sessionStorage copy.
            try { sessionStorage.removeItem('__ZIRCOS_CONFIG__'); } catch (e) {}

            var t = null;
            try { t = localStorage.getItem('provatferi-admin-theme'); } catch (e) {}
            if (t !== 'light' && t !== 'dark') {
                t = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
            }
            document.documentElement.setAttribute('data-bs-theme', t);
        })();
    </script>

    <script src="{{ asset('zircos/js/config.js') }}"></script>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Bengali:wght@400;500;600;700&display=swap" rel="stylesheet">

    <link href="{{ asset('zircos/css/vendor.min.css') }}" rel="stylesheet">
    <link href="{{ asset('zircos/css/app.min.css') }}" rel="stylesheet" id="app-style">
    <link href="{{ asset('zircos/css/icons.min.css') }}" rel="stylesheet">
    <link href="{{ asset('zircos/css/provatferi-admin.css') }}" rel="stylesheet">
</head>
<body>
<div class="auth-bg d-flex min-vh-100 justify-content-center align-items-center">
    <div class="row g-0 justify-content-center w-100 m-3">
        <div class="col-xl-4 col-lg-5 col-md-7">
            <div class="card p-4 mb-0">
                <div class="text-center mb-4">
                    {{-- Official logo only — never text, initials or generated artwork. --}}
                    <span class="pf-logo d-inline-block">
                        <span class="pf-logo-for-light">
                            <img src="{{ asset('brand/provatferi-logo-light.png') }}"
                                 alt="প্রভাতফেরী সাহিত্য ও সাংস্কৃতিক কেন্দ্র" style="height:38px">
                        </span>
                        <span class="pf-logo-for-dark">
                            <img src="{{ asset('brand/provatferi-logo-dark.png') }}"
                                 alt="প্রভাতফেরী সাহিত্য ও সাংস্কৃতিক কেন্দ্র" style="height:38px">
                        </span>
                    </span>
                </div>

                <h1 class="fw-semibold mb-2 fs-18 text-center">{{ $heading ?? 'প্রশাসনিক লগইন' }}</h1>
                @isset($subheading)
                    <p class="text-muted text-center mb-4 fs-14">{{ $subheading }}</p>
                @endisset

                {{ $slot }}
            </div>

            <p class="text-center text-muted fs-12 mt-3 mb-0">
                &copy; {{ date('Y') }} প্রভাতফেরী সাহিত্য ও সাংস্কৃতিক কেন্দ্র
            </p>
        </div>
    </div>
</div>

<script src="{{ asset('zircos/js/vendor.min.js') }}"></script>
<script src="{{ asset('zircos/js/app.js') }}"></script>
</body>
</html>
