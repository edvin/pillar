@extends('pillar-ui::layout')

@section('content')
    <div class="max-w-6xl mx-auto p-6">
        <a href="{{ route('pillar.ui.index') }}" class="text-sm text-blue-600 hover:underline">&larr; Back to overview</a>
        <h1 class="text-2xl font-semibold mb-4">Aggregate timeline</h1>
        <div class="mb-4">
            <label class="text-sm text-gray-500">Aggregate ID</label>
            <div class="font-mono">{{ $id }}</div>
        </div>

        <div id="timeline" style="height: 320px; border: 1px solid #e5e7eb;"></div>
        <div id="list" class="mt-4 space-y-2"></div>

        <button id="more" class="mt-4 bg-gray-200 px-4 py-2 rounded">Load older</button>
    </div>

    <link rel="stylesheet" href="https://unpkg.com/vis-timeline@7.7.3/dist/vis-timeline-graph2d.min.css">
    <script src="https://unpkg.com/vis-data@7.1.4/peer/umd/vis-data.min.js"></script>
    <script src="https://unpkg.com/vis-timeline@7.7.3/standalone/umd/vis-timeline-graph2d.min.js"></script>

    <script>
        const id = @json($id);
        const aggId = String(id).trim().replace(/[–—]/g, '-');
        let before = Number.MAX_SAFE_INTEGER;
        const limit = {{ (int)config('pillar.ui.page_size', 100) }};
        const items = new vis.DataSet();
        const container = document.getElementById('timeline');
        const timeline = new vis.Timeline(container, items, {
            stack: true, zoomKey: 'ctrlKey',
            tooltip: { followMouse: true }
        });

        function prettyEvent(event) {
            if (event === undefined || event === null) return '';
            try {
                return JSON.stringify(event, null, 2);
            } catch (_) {
                return String(event);
            }
        }

        function load() {
            const url = new URL('{{ route('pillar.ui.api.aggregate.events') }}', location.origin);
            url.searchParams.set('id', aggId);
            url.searchParams.set('before_seq', before);
            url.searchParams.set('limit', limit);

            fetch(url).then(r => r.json()).then(({items: evts, next_before_seq, has_more}) => {
                evts = evts || [];
                evts.forEach(e => {
                    items.add({
                        id: e.sequence,
                        content: `${e.type} <small>#${e.aggregate_sequence}</small>`,
                        start: e.occurred_at,
                        title: `${e.type} v${e.version}`
                    });

                    const p = document.createElement('div');
                    p.className = 'border rounded p-3 bg-white';
                    p.innerHTML = `
  <div class="flex items-baseline justify-between gap-4">
    <div class="text-sm text-gray-500">#${e.sequence} · ${e.occurred_at}</div>
    <code class="text-xs bg-gray-100 px-1 rounded">v${e.version}</code>
  </div>
  <div class="font-mono">${e.type}</div>
  <pre class="mt-2 text-sm overflow-auto">${prettyEvent(e.event)}</pre>`;
                    document.getElementById('list').appendChild(p);
                });
                before = next_before_seq ?? before;
                document.getElementById('more').disabled = !has_more;
            });
        }

        document.getElementById('more').addEventListener('click', load);
        load();
    </script>
@endsection