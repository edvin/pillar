@extends('pillar-ui::layout')

@section('content')
    <div class="max-w-6xl mx-auto p-6">
        <h1 class="text-2xl font-semibold mb-4">Pillar — Event Streams</h1>

        <form class="flex gap-2 mb-6" onsubmit="location.href='{{ route('pillar.ui.aggregate.show') }}?id='+encodeURIComponent(this.id.value); return false;">
            <input name="id" type="text" placeholder="Aggregate ID (UUID…)" class="border px-3 py-2 flex-1" />
            <button class="bg-blue-600 text-white px-4 py-2 rounded">Open</button>
        </form>

        <h2 class="font-medium mb-2">Recently touched</h2>
        <ul id="recent" class="divide-y border rounded bg-white"></ul>
    </div>

    <script>
        fetch('{{ route('pillar.ui.api.recent') }}')
            .then(r => r.json())
            .then(rows => {
                const ul = document.getElementById('recent');
                ul.innerHTML = rows.map(r => `
      <li class="p-3 flex justify-between items-center">
        <div>
          <div class="text-sm text-gray-500">#${r.last_seq}</div>
          <div class="font-mono">${r.aggregate_id}</div>
        </div>
        <a class="text-blue-600" href="{{ route('pillar.ui.aggregate.show') }}?id=${encodeURIComponent(r.aggregate_id)}">Open →</a>
      </li>
    `).join('');
            });
    </script>
@endsection