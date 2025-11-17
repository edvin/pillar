<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <title>Pillar Stream Browser</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark">

    <script>
        (function () {
            try {
                var key = 'pillar-theme';
                var stored = localStorage.getItem(key);
                var prefersDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
                var shouldDark = stored ? (stored === 'dark') : prefersDark;
                var root = document.documentElement;
                if (shouldDark) {
                    root.classList.add('dark');
                } else {
                    root.classList.remove('dark');
                }
            } catch (_) {
            }
        })();
    </script>

    <!-- 2) Tailwind CDN config -->
    <script>
        window.tailwind = window.tailwind || {};
        window.tailwind.config = {darkMode: 'class'};
    </script>
    <script src="https://cdn.tailwindcss.com"></script>

    <meta name="csrf-token" content="{{ csrf_token() }}">
</head>
<body class="min-h-screen bg-gray-50 text-gray-900 dark:bg-slate-950 dark:text-slate-100">
<header class="sticky top-0 z-10 border-b border-gray-200/60 dark:border-slate-800 bg-white/80 dark:bg-slate-900/80 backdrop-blur">
    <div class="max-w-7xl mx-auto px-6 py-3 flex items-center justify-between">
        <a href="{{ route('pillar.ui.index') }}" class="font-semibold text-lg tracking-tight">
            <span class="bg-gradient-to-r from-sky-500 to-indigo-500 bg-clip-text text-transparent">Pillar Stream Browser</span>
        </a>
        <a href="{{ route('pillar.ui.outbox') }}"
           class="inline-flex items-center gap-2 hover:text-sky-600 text-slate-700 dark:text-slate-300 dark:hover:text-sky-400">
            <span aria-hidden="true">📬</span>
            <span>Outbox</span>
        </a>

        <nav class="text-sm flex items-center gap-4">
            <a class="hover:text-sky-600 text-slate-700 dark:text-slate-300 dark:hover:text-sky-400"
               href="{{ route('pillar.ui.index') }}">Dashboard</a>
            <a class="hover:text-sky-600 text-slate-700 dark:text-slate-300 dark:hover:text-sky-400"
               href="https://docs.pillarphp.dev" target="_blank" rel="noreferrer">Docs</a>

            <!-- Theme toggle -->
            <button id="theme-toggle" type="button"
                    class="inline-flex items-center gap-2 rounded-md border border-slate-200/70 dark:border-slate-700 px-3 py-1.5 text-xs font-medium text-slate-700 dark:text-slate-200 hover:bg-slate-100 dark:hover:bg-slate-800">
                <span class="sr-only">Toggle theme</span>
                <span class="block dark:hidden">🌙</span>
                <span class="hidden dark:block">☀️</span>
                <span class="hidden sm:inline">Theme</span>
            </button>
        </nav>
    </div>
</header>

<main class="max-w-7xl mx-auto px-6 py-6">
    @yield('content')
</main>

<footer class="border-t border-gray-200/60 dark:border-slate-800 py-6 text-xs text-slate-500 dark:text-slate-400">
    <div class="max-w-7xl mx-auto px-6 flex items-center justify-between">
        <span>© {{ date('Y') }} Pillar</span>
        <span class="hidden sm:inline">Stream Browser &amp; Tools</span>
    </div>
</footer>

<!-- 3) Theme toggle behavior -->
<script>
    (function () {
        var btn = document.getElementById('theme-toggle');
        if (!btn) return;
        btn.addEventListener('click', function () {
            try {
                var key = 'pillar-theme';
                var root = document.documentElement;
                var nowDark = !root.classList.contains('dark');
                root.classList.toggle('dark', nowDark);
                localStorage.setItem(key, nowDark ? 'dark' : 'light');
            } catch (_) {
            }
        });
    })();
</script>
</body>
</html>