<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        {{-- Apply the saved theme before first paint. Dark is the default (Plan §6). --}}
        <script>
            (function () {
                try {
                    var appearance = localStorage.getItem('appearance') || 'dark';
                    var dark = appearance === 'dark' || (appearance === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches);
                    document.documentElement.classList.toggle('dark', dark);
                    document.documentElement.classList.toggle('reduce-motion', localStorage.getItem('reduce-motion') === 'true');
                } catch (e) {}
            })();
        </script>

        <title inertia>{{ config('app.name', 'EAJ AI Dev Studio') }}</title>
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">

        @routes
        @viteReactRefresh
        @vite(['resources/js/app.tsx', "resources/js/pages/{$page['component']}.tsx"])
        @inertiaHead
    </head>
    <body>
        {{-- Branded splash (Plan §7.7): only visible if boot takes > 300ms; removed when React mounts. --}}
        <div id="eaj-splash" aria-hidden="true">
            <svg viewBox="0 0 64 64" width="72" height="72">
                <path class="splash-e" d="M7 14H15V50H7Z M15 14H31L27 21H15Z M15 28.5H27L24 35.5H15Z M15 43H27L31 50H15Z" />
                <path class="splash-a" d="M42 14H49L40 50H33Z M42 14H49L58 50H51Z M41 37H50L51.5 42H39.5Z" />
            </svg>
            <div class="splash-wordmark"><b>EAJ</b> AI Dev Studio</div>
        </div>

        @inertia
    </body>
</html>
