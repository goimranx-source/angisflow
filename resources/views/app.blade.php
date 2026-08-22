<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#0d1b2a">
    <meta name="robots" content="noindex, nofollow">

    <title>{{ config('prism.brand.name') }}</title>

    <link rel="icon" href="/favicon.ico" type="image/x-icon">
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">

    {{--
        The sidebar's width, applied before a single byte of CSS is parsed.

        Read it in React instead and the rail paints open, the bundle loads, and
        the rail snaps shut — a visible jump on every cold load for every user
        who collapsed it. The cookie is deliberately unencrypted (see
        bootstrap/app.php) precisely so it can be read here, where there is no
        application code yet to decrypt anything. localStorage cannot help: it
        is not readable until scripts run, which is the moment that is already
        too late.
    --}}
    <script>
        (function () {
            try {
                if (document.cookie.indexOf('prism_rail=collapsed') !== -1) {
                    document.documentElement.classList.add('rail-collapsed');
                }
                if (document.cookie.indexOf('prism_theme=dark') !== -1) {
                    document.documentElement.setAttribute('data-theme', 'dark');
                }
            } catch (e) { /* cookies off simply means the open rail, light theme */ }
        })();
    </script>

    {{--
        Who is signed in, whose books, and the menu — delivered with the
        document rather than fetched after it.

        This is the round trip that most single-page applications pay on every
        cold load and cannot parallelise away, because it only starts once their
        JavaScript is already running. See SpaController for the sequence.

        @json() escapes for a <script> context, so a business named
        </script> cannot break out of it.
    --}}
    <script id="prism-boot" type="application/json">@json($boot)</script>

    {{--
        Preloaded, not merely linked. modulepreload starts the bundle download
        in parallel with the stylesheet instead of after the parser reaches it,
        which on a slow connection is most of the difference in time-to-first-
        paint.
    --}}
    @viteReactRefresh
    @vite(['resources/css/app.css', 'resources/js/app.tsx'])
    
    {{-- Inline preloader styles to show immediately before React loads --}}
    <style>
        #prism-preloader {
            position: fixed;
            inset: 0;
            z-index: 9999;
            display: flex;
            align-items: center;
            justify-content: center;
            background: white;
        }
        #prism-preloader .spinner-container {
            position: relative;
            width: 160px;
            height: 160px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        #prism-preloader .spinner-bg {
            position: absolute;
            width: 160px;
            height: 160px;
            border-radius: 50%;
            border: 4px solid rgba(0, 212, 232, 0.1);
        }
        #prism-preloader .spinner {
            position: absolute;
            width: 160px;
            height: 160px;
            border-radius: 50%;
            border: 4px solid transparent;
            border-top-color: #00d4e8;
            border-right-color: #00d4e8;
            animation: spin 1s linear infinite;
        }
        #prism-preloader .logo {
            position: relative;
            z-index: 10;
            width: 80px;
            height: 80px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
    </style>
</head>
<body class="h-full">
    {{-- Preloader shown immediately before React loads --}}
    <div id="prism-preloader">
        <div class="spinner-container">
            <div class="spinner-bg"></div>
            <div class="spinner"></div>
            <div class="logo">
                <img src="/img/angisflow-favicon.png" alt="Angisflow" style="width: 80px; height: 80px; object-fit: contain;" />
            </div>
        </div>
    </div>

    {{-- Two pixels at the top of the window, driven by the router. A full-page
         spinner on every click announces "this is loading", which is the exact
         impression this build exists to avoid. --}}
    <div id="nav-progress" aria-hidden="true"><span></span></div>

    <div id="app"></div>

    <noscript>
        <div style="padding:2rem;font-family:system-ui;max-width:34rem;margin:0 auto">
            <h1>JavaScript is switched off</h1>
            <p>Angisflow needs it to run. Turn it on for this site and reload.</p>
        </div>
    </noscript>
</body>
</html>
