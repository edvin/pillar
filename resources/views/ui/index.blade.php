@extends('pillar-ui::layout')

@section('content')
    <div class="max-w-6xl mx-auto p-6">
        <h1 class="text-2xl font-semibold mb-4">Event Streams</h1>

        <form class="flex gap-2 mb-6" onsubmit="location.href='{{ route('pillar.ui.aggregate.show') }}?id='+encodeURIComponent(this.id.value); return false;">
            <input id="agg-id-input" data-1p-ignore name="id" type="text" placeholder="Aggregate ID Search" autofocus class="w-full flex-1 rounded-lg bg-slate-50 dark:bg-slate-800/60 border border-slate-300/70 dark:border-slate-600/60 px-4 py-2.5 focus:outline-none focus:ring-2 focus:ring-blue-500/50 focus:border-blue-500 placeholder-slate-400 shadow-sm" />
            <button class="inline-flex items-center gap-2 rounded-lg bg-gradient-to-r from-indigo-700 to-blue-800 hover:from-indigo-800 hover:to-blue-900 active:from-indigo-900 active:to-blue-950 text-white px-4 py-2.5 shadow-md focus:outline-none focus:ring-2 focus:ring-indigo-500/60">
              <span>Open</span>
              <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" class="w-4 h-4" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14M13 5l7 7-7 7" />
              </svg>
            </button>
        </form>

        <h2 class="font-medium mb-2">Recently updated aggregates</h2>
        <div class="overflow-hidden rounded-lg border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 shadow-sm">
            <table class="min-w-full text-sm">
                <thead class="bg-slate-50 dark:bg-slate-800/50 text-slate-600 dark:text-slate-300">
                <tr>
                    <th class="pl-4 pr-3 py-2 text-left whitespace-nowrap w-28">Seq</th>
                    <th class="px-4 py-2 text-left whitespace-nowrap">🧱 Aggregate</th>
                    <th class="px-4 py-2 text-left whitespace-nowrap">🧾 Event</th>
                    <th class="px-4 py-2 text-left whitespace-nowrap">🕒 When</th>
                </tr>
                </thead>
                <tbody id="recent-body" class="divide-y divide-slate-200 dark:divide-slate-700"></tbody>
            </table>
        </div>

        <div id="pillar-toast" class="fixed bottom-5 right-5 z-50 hidden"></div>
    </div>

    <script>
        (function () {
            function humanizeEvent(t) {
                if (!t) return '—';
                const short = t.split(/[\\.]/).pop();
                return short.replace(/([a-z])([A-Z])/g, '$1 $2');
            }

            const input = document.getElementById('agg-id-input');
            if (input) { try { input.focus(); } catch(_) {} }

            const tbody = document.getElementById('recent-body');

            fetch('{{ route('pillar.ui.api.recent') }}')
                .then(r => r.json())
                .then(rows => {
                    if (!Array.isArray(rows)) rows = [];

                    if (rows.length === 0) {
                        tbody.innerHTML = `<tr><td colspan="4" class="px-4 py-4 text-slate-500 dark:text-slate-400">No recent aggregates.</td></tr>`;
                        return;
                    }

                    tbody.innerHTML = rows.map(r => {
                        const seq       = r.last_seq ?? r.sequence ?? null;
                        const aggSeq    = r.aggregate_seq ?? r.aggregate_sequence ?? null;
                        const eventType = r.last_event_type ?? r.event_type ?? null;
                        const idClass   = r.aggregate_id_class ?? null;
                        const aggTypeFull = r.aggregate_type ?? (idClass || null);
                        const aggType = aggTypeFull ? aggTypeFull.split('\\').pop() : null;
                        const when      = r.last_at ?? r.occurred_at ?? null;
                        const eventLabelShort = humanizeEvent(eventType);
                        const href = `{{ route('pillar.ui.aggregate.show') }}?id=${encodeURIComponent(r.aggregate_id)}`;

                        return `
<tr class="group cursor-pointer hover:bg-slate-50 dark:hover:bg-slate-800" onclick="location.href='${href}'">
  <td class="pl-4 pr-3 py-3 whitespace-nowrap text-center">
    <div class="flex items-center justify-center gap-1">
      <span class="inline-flex items-center rounded-md px-2 py-0.5 text-xs font-medium bg-blue-50 text-blue-700 dark:bg-blue-900/30 dark:text-blue-200">${seq != null ? '#' + seq : '—'}</span>
      ${aggSeq != null ? `<span class="inline-flex items-center rounded-md px-2 py-0.5 text-xs font-medium bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300">↳ ${aggSeq}</span>` : ''}
    </div>
  </td>
  <td class="px-4 py-3">
    <div class="flex items-start gap-2">
      <span>🧱</span>
      <div class="leading-tight">
        <div class="font-medium text-slate-700 dark:text-slate-200">${aggType ?? 'Unknown'}</div>
        <div class="flex items-center gap-2 font-mono text-xs text-slate-500 dark:text-slate-400">
          <span>${r.aggregate_id}</span>
          <button type="button" class="inline-flex items-center opacity-70 hover:opacity-100" title="Copy aggregate ID" onclick="event.stopPropagation(); copyToClipboard('${r.aggregate_id.replace(/'/g, "&#39;")}', 'Aggregate ID')">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="w-4 h-4">
              <rect x="9" y="7" width="10" height="14" rx="2" ry="2"></rect>
              <rect x="5" y="3" width="10" height="14" rx="2" ry="2"></rect>
            </svg>
          </button>
        </div>
      </div>
    </div>
  </td>
  <td class="px-4 py-3">
    <span class="inline-flex items-center gap-2" title="${eventType ?? ''}">
      <span>🧾</span>
      <span class="font-mono text-slate-700 dark:text-slate-200">${eventLabelShort}</span>
      ${eventType ? `<button type=\"button\" class=\"inline-flex items-center opacity-70 hover:opacity-100\" title=\"Copy event type\" onclick=\"event.stopPropagation(); copyToClipboard('${(eventType || '').replace(/'/g, "&#39;")}', 'Event type')\"><svg xmlns=\"http://www.w3.org/2000/svg\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\" class=\"w-4 h-4\"><rect x=\"9\" y=\"7\" width=\"10\" height=\"14\" rx=\"2\" ry=\"2\"></rect><rect x=\"5\" y=\"3\" width=\"10\" height=\"14\" rx=\"2\" ry=\"2\"></rect></svg></button>` : ''}
    </span>
  </td>
  <td class="px-4 py-3 whitespace-nowrap text-slate-600 dark:text-slate-300">${when ? when : '—'}</td>
</tr>`;
                    }).join('');
                })
                .catch(() => {
                    tbody.innerHTML = `<tr><td colspan="4" class="px-4 py-4 text-slate-500 dark:text-slate-400">No recent aggregates.</td></tr>`;
                });
        })();

        function copyToClipboard(text, label){
          if (!text) return;
          const onDone = () => showToast((label || 'Copied') + ' copied');
          if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(onDone).catch(onDone);
          } else {
            const ta = document.createElement('textarea');
            ta.value = text; document.body.appendChild(ta); ta.select();
            try { document.execCommand('copy'); } catch(_) {}
            document.body.removeChild(ta);
            onDone();
          }
        }

        function showToast(message){
          let container = document.getElementById('pillar-toast');
          if (!container) {
            container = document.createElement('div');
            container.id = 'pillar-toast';
            container.className = 'fixed bottom-5 right-5 z-50';
            document.body.appendChild(container);
          }
          const box = document.createElement('div');
          box.className = 'mb-2 px-3 py-2 rounded-lg bg-slate-900/95 text-white shadow-lg text-sm transition-opacity duration-300 opacity-0 dark:bg-slate-800/95';
          box.textContent = message;
          container.appendChild(box);
          // Fade in
          requestAnimationFrame(() => box.classList.remove('opacity-0'));
          // Fade out & remove
          setTimeout(() => {
            box.classList.add('opacity-0');
            setTimeout(() => { try { container.removeChild(box); } catch(_) {} }, 300);
          }, 1400);
        }
    </script>
@endsection