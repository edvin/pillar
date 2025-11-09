<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Pillar — Event Streams</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <script src="https://cdn.tailwindcss.com"></script>
    <meta name="csrf-token" content="{{ csrf_token() }}">
</head>
<body class="bg-gray-50 text-gray-900">
<header class="border-b bg-white">
    <div class="max-w-7xl mx-auto px-6 py-3 flex items-center justify-between">
        <a href="{{ route('pillar.ui.index') }}" class="font-semibold">Pillar UI</a>
        <nav class="text-sm space-x-4">
            <a class="text-blue-600" href="{{ route('pillar.ui.index') }}">Dashboard</a>
            <a class="text-gray-600" href="https://docs.pillarphp.dev" target="_blank" rel="noreferrer">Docs</a>
        </nav>
    </div>
</header>

<main>
    @yield('content')
</main>
</body>
</html>