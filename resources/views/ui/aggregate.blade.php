@extends('pillar-ui::layout')

@section('content')
    <div class="max-w-6xl mx-auto p-6">
        <a href="{{ route('pillar.ui.index') }}" class="text-sm text-blue-600 hover:underline">&larr; Back to
            overview</a>
        <h1 class="text-3xl font-semibold mb-6">Aggregate timeline</h1>

        @php
            $aggTypeFull  = $aggregate_type ?? null; // optional: controller may set this
            $aggTypeShort = $aggTypeFull ? class_basename($aggTypeFull) : null;
        @endphp
        <div class="mb-4 flex items-start gap-3">
            <span class="pt-0.5">🧱</span>
            <div class="leading-tight">
                <div class="font-medium text-slate-700 dark:text-slate-200">
                    {{ $aggTypeShort ?? 'Unknown' }}
                    @if($aggTypeFull)
                        <span class="ml-2 text-xs text-slate-400 dark:text-slate-500" title="{{ $aggTypeFull }}">({{ $aggTypeFull }})</span>
                    @endif
                </div>
                <div class="flex items-center gap-2 font-mono text-xs text-slate-600 dark:text-slate-300">
                    <span id="aggregate-id">{{ $id }}</span>
                    <button type="button" class="inline-flex items-center opacity-70 hover:opacity-100"
                            title="Copy aggregate ID"
                            onclick="event.stopPropagation(); copyToClipboard('{{ $id }}', 'Aggregate ID')">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                             stroke-width="2" class="w-4 h-4">
                            <rect x="9" y="7" width="10" height="14" rx="2" ry="2"></rect>
                            <rect x="5" y="3" width="10" height="14" rx="2" ry="2"></rect>
                        </svg>
                    </button>
                </div>
            </div>
        </div>

        <div class="overflow-hidden rounded-lg border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 shadow-sm">
            <table class="min-w-full text-sm">
                <thead class="bg-slate-50 dark:bg-slate-800/50 text-slate-600 dark:text-slate-300">
                <tr>
                    <th class="pl-4 pr-3 py-2 text-left whitespace-nowrap w-28">Seq</th>
                    <th class="px-4 py-2 text-left whitespace-nowrap">🧾 Event</th>
                    <th class="px-4 py-2 text-left whitespace-nowrap">🕒 When</th>
                    <th class="px-4 py-2 text-left whitespace-nowrap w-32">Actions</th>
                </tr>
                </thead>
                <tbody id="event-rows" class="divide-y divide-slate-200 dark:divide-slate-700"></tbody>
            </table>
        </div>

        <button id="load-older"
                class="mt-4 inline-flex items-center gap-2 rounded-lg bg-gradient-to-r from-indigo-700 to-blue-800 hover:from-indigo-800 hover:to-blue-900 active:from-indigo-900 active:to-blue-950 text-white px-4 py-2.5 shadow-md focus:outline-none focus:ring-2 focus:ring-indigo-500/60 disabled:opacity-50 disabled:cursor-not-allowed transition"
                type="button">Load older
        </button>
    </div>

    <!-- Modal backdrop -->
    <div id="modal-backdrop"
         class="fixed inset-0 bg-black bg-opacity-50 hidden flex items-start justify-center pt-24 md:pt-32 z-50">
        <!-- Modal container -->
        <div class="bg-white dark:bg-gray-900 rounded-lg max-w-3xl w-full max-h-[80vh] overflow-auto p-6 shadow-lg relative">
            <!-- Top-right controls -->
            <div class="absolute top-3 right-3 flex items-center gap-2">
                <button id="modal-close" aria-label="Close modal"
                        class="text-gray-600 dark:text-gray-300 hover:text-gray-900 dark:hover:text-white text-2xl font-bold leading-none">
                    &times;
                </button>
            </div>

            <h2 class="text-xl font-semibold mb-4 flex items-center gap-2">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="w-5 h-5">
                    <circle cx="12" cy="12" r="9"></circle>
                    <path d="M12 7v5l3 3"></path>
                </svg>
                <span>Time travel state</span>
                <span class="ml-3 text-sm font-normal text-slate-500 dark:text-slate-300">AS OF <span id="modal-when">—</span></span>
            </h2>

            <!-- Aggregate header (mirrors base view) -->
            <div class="mb-4 flex items-start gap-3">
                <span class="pt-0.5">🧱</span>
                <div class="leading-tight">
                    <div class="font-medium text-slate-700 dark:text-slate-200">
                        <span id="modal-agg-type">—</span>
                        <span id="modal-agg-type-full" class="ml-2 text-xs text-slate-400 dark:text-slate-500" title=""></span>
                    </div>
                    <div class="flex items-center gap-2 font-mono text-xs text-slate-600 dark:text-slate-300">
                        <span id="modal-agg-id">—</span>
                        <button type="button" class="inline-flex items-center opacity-70 hover:opacity-100"
                                title="Copy aggregate ID"
                                onclick="event.stopPropagation(); copyToClipboard(document.getElementById('modal-agg-id').textContent, 'Aggregate ID')">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="w-4 h-4">
                                <rect x="9" y="7" width="10" height="14" rx="2" ry="2"></rect>
                                <rect x="5" y="3" width="10" height="14" rx="2" ry="2"></rect>
                            </svg>
                        </button>
                    </div>
                </div>
            </div>

            <!-- Filtered JSON body -->
            <div>
                <div class="flex items-center justify-between mb-2">
                    <div id="modal-payload-title" class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Aggregate state at the time of event</div>
                    <button id="modal-copy-json" type="button"
                            class="inline-flex items-center gap-2 rounded-md px-2.5 py-1 text-xs bg-slate-200/70 hover:bg-slate-300 dark:bg-slate-700/70 dark:hover:bg-slate-600 text-slate-700 dark:text-slate-200"
                            title="Copy JSON to clipboard">
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <rect x="9" y="7" width="10" height="14" rx="2" ry="2"></rect>
                            <rect x="5" y="3" width="10" height="14" rx="2" ry="2"></rect>
                        </svg>
                        <span>Copy JSON</span>
                    </button>
                </div>
                <pre id="modal-json" class="font-mono text-sm leading-relaxed text-slate-800 dark:text-slate-200 whitespace-pre-wrap max-h-[60vh] overflow-auto bg-white/50 dark:bg-slate-900/40 rounded-md p-3 border border-slate-200 dark:border-slate-700">Loading…</pre>
            </div>
        </div>
    </div>

    <script>
        (() => {
            function showToast(message) {
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
                requestAnimationFrame(() => box.classList.remove('opacity-0'));
                setTimeout(() => {
                    box.classList.add('opacity-0');
                    setTimeout(() => {
                        try {
                            container.removeChild(box);
                        } catch (_) {
                        }
                    }, 300);
                }, 1400);
            }

            function copyToClipboard(text, label) {
                if (!text) return;
                const onDone = () => showToast((label || 'Copied') + ' copied');
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(text).then(onDone).catch(onDone);
                } else {
                    const ta = document.createElement('textarea');
                    ta.value = text;
                    document.body.appendChild(ta);
                    ta.select();
                    try {
                        document.execCommand('copy');
                    } catch (_) {
                    }
                    document.body.removeChild(ta);
                    onDone();
                }
            }

            const aggId = String(@json($id)).trim();
            const limit = {{ (int)config('pillar.ui.page_size', 100) }};
            let before = Number.MAX_SAFE_INTEGER;
            const rowsContainer = document.getElementById('event-rows');
            const loadOlderBtn = document.getElementById('load-older');

            // Modal elements
            const modalBackdrop = document.getElementById('modal-backdrop');
            const modalContent = document.getElementById('modal-content');
            const modalClose = document.getElementById('modal-close');

            // Utility: humanize event short name
            function humanizeEvent(type) {
                // Extract class short name after last backslash or dot
                let shortName = type.split(/[\\.]/).pop();
                // Insert spaces before capital letters (except first)
                return shortName.replace(/([a-z])([A-Z])/g, '$1 $2');
            }

            // Toggle JSON preview row below the clicked row (meta-aware)
            function toggleJsonRow(triggerRow, meta) {
                const nextRow = triggerRow.nextElementSibling;
                const isOpen = nextRow && nextRow.classList.contains('json-preview-row');
                if (isOpen) {
                    nextRow.remove();
                    return;
                }

                // Helpers
                function esc(s) {
                  const map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' };
                  return String(s).replace(/[&<>"']/g, (m) => map[m]);
                }
                const storedV = meta?.storedVersion ?? null;
                const newV    = meta?.version ?? null;
                const upcasters = Array.isArray(meta?.upcasters) ? meta.upcasters : [];

                // Build badges
                const versionBadge = (storedV && newV && storedV !== newV)
                    ? `<span class="inline-flex items-center rounded-md px-2 py-0.5 text-xs font-medium bg-blue-50 text-blue-700 dark:bg-blue-900/30 dark:text-blue-200" title="Stored v${esc(storedV)} → Upcast v${esc(newV)}">v${esc(storedV)} → v${esc(newV)}</span>`
                    : (newV ? `<span class="inline-flex items-center rounded-md px-2 py-0.5 text-xs font-medium bg-blue-50 text-blue-700 dark:bg-blue-900/30 dark:text-blue-200" title="Event version">v${esc(newV)}</span>` : '');

                const upcastersBadges = upcasters.length
                    ? `<span class="text-xs text-slate-500 dark:text-slate-400 mr-1">Upcasters:</span>` +
                      upcasters.map(u => `<span class="inline-flex items-center rounded-md px-2 py-0.5 text-xs font-medium bg-violet-100 text-violet-700 dark:bg-violet-900/30 dark:text-violet-200" title="${esc(u)}">${esc(shortClassName(u))}</span>`).join(' ')
                    : '';

                // Pretty JSON once so both the view and copy use the same text
                let pretty = '';
                try {
                    pretty = JSON.stringify(meta?.event ?? {}, null, 2);
                } catch {
                    pretty = String(meta?.event ?? '');
                }

                const tr = document.createElement('tr');
                tr.className = 'json-preview-row bg-slate-50 dark:bg-slate-800';
                const td = document.createElement('td');
                td.colSpan = 4;
                td.className = 'px-4 py-3 border-t border-slate-200 dark:border-slate-700';

                td.innerHTML = `
        <div class="flex items-center justify-between mb-2">
          <div>
            <div class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Event Payload</div>
            <div class="mt-1 flex flex-wrap items-center gap-1">
              ${versionBadge}
              ${upcastersBadges}
            </div>
          </div>
          <button type="button"
            class="inline-flex items-center gap-2 rounded-md px-2.5 py-1 text-xs bg-slate-200/70 hover:bg-slate-300 dark:bg-slate-700/70 dark:hover:bg-slate-600 text-slate-700 dark:text-slate-200"
            title="Copy JSON to clipboard">
            <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <rect x="9" y="7" width="10" height="14" rx="2" ry="2"></rect>
              <rect x="5" y="3" width="10" height="14" rx="2" ry="2"></rect>
            </svg>
            <span>Copy JSON</span>
          </button>
        </div>
        <pre class="font-mono text-xs leading-relaxed text-slate-800 dark:text-slate-200 whitespace-pre-wrap max-h-64 overflow-auto bg-white/50 dark:bg-slate-900/40 rounded-md p-3 border border-slate-200 dark:border-slate-700"></pre>
      `;

                const pre = td.querySelector('pre');
                pre.textContent = pretty;

                // Wire copy button (don’t collapse the row)
                const copyBtn = td.querySelector('button');
                copyBtn.addEventListener('click', (e) => {
                    e.stopPropagation();
                    copyToClipboard(pretty, 'JSON');
                });

                tr.appendChild(td);
                triggerRow.after(tr);
            }

            // Create a single event row with its interactive elements
            function createEventRow(event) {
                const tr = document.createElement('tr');
                tr.className = 'group cursor-pointer hover:bg-slate-50 dark:hover:bg-slate-800';

                // Seq column (centered badges)
                const seqTd = document.createElement('td');
                seqTd.className = 'pl-4 pr-3 py-3 whitespace-nowrap text-center';
                seqTd.innerHTML = `
                  <div class="flex items-center justify-center gap-1">
                    <span class="inline-flex items-center rounded-md px-2 py-0.5 text-xs font-medium bg-blue-50 text-blue-700 dark:bg-blue-900/30 dark:text-blue-200">${event.sequence != null ? '#' + event.sequence : '—'}</span>
                    ${event.stream_sequence != null ? `<span class="inline-flex items-center rounded-md px-2 py-0.5 text-xs font-medium bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300">↳ ${event.stream_sequence}</span>` : ''}
                  </div>`;
                tr.appendChild(seqTd);

                // Event column (emoji + short name + copy full type)
                const eventTd = document.createElement('td');
                eventTd.className = 'px-4 py-3';
                const shortName = humanizeEvent(event.type);
                eventTd.innerHTML = `
                  <span class="inline-flex items-center gap-2" title="${event.type}">
                    <span>🧾</span>
                    <span class="font-mono text-slate-700 dark:text-slate-200">${shortName}</span>
                    <button type="button" class="inline-flex items-center opacity-70 hover:opacity-100" title="Copy event type">
                      <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="w-4 h-4"><rect x="9" y="7" width="10" height="14" rx="2" ry="2"></rect><rect x="5" y="3" width="10" height="14" rx="2" ry="2"></rect></svg>
                    </button>
                  </span>`;
                tr.appendChild(eventTd);

                // Wire copy button
                const copyBtn = eventTd.querySelector('button');
                copyBtn.addEventListener('click', e => {
                    e.stopPropagation();
                    copyToClipboard(event.type, 'Event type');
                });

                // When column
                const whenTd = document.createElement('td');
                whenTd.className = 'px-4 py-3 whitespace-nowrap text-slate-600 dark:text-slate-300';
                whenTd.textContent = event.occurred_at || '—';
                tr.appendChild(whenTd);

                // Actions column (Travel)
                const actionsTd = document.createElement('td');
                actionsTd.className = 'px-4 py-3 text-center';
                const travelBtn = document.createElement('button');
                travelBtn.type = 'button';
                travelBtn.className = 'inline-flex items-center gap-2 rounded-lg bg-gradient-to-r from-indigo-700 to-blue-800 hover:from-indigo-800 hover:to-blue-900 active:from-indigo-900 active:to-blue-950 text-white px-3 py-1.5 shadow focus:outline-none focus:ring-2 focus:ring-indigo-500/60 whitespace-nowrap';
                travelBtn.innerHTML = `
                  <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="w-4 h-4">
                    <circle cx="12" cy="12" r="9"></circle>
                    <path d="M12 7v5l3 3"></path>
                  </svg>
                  <span>Time&nbsp;Travel</span>
                `;
                travelBtn.title = `Time travel to state after event #${event.stream_sequence}`;
                actionsTd.appendChild(travelBtn);
                tr.appendChild(actionsTd);

                travelBtn.addEventListener('click', e => {
                    e.stopPropagation();
                    openModal(event.stream_sequence, event.occurred_at || '', event.type);
                });

                // Toggle JSON preview on row click
                tr.addEventListener('click', e => {
                    if (e.target.closest('button')) return;
                    toggleJsonRow(tr, {
                        event: event.event,
                        storedVersion: event.storedVersion ?? event.version ?? null,
                        version: event.version ?? null,
                        upcasters: event.upcasters ?? []
                    });
                });

                return tr;
            }

            // Load events from API and append rows
            function loadEvents() {
                loadOlderBtn.disabled = true;
                const url = new URL('{{ route('pillar.ui.api.aggregate.events') }}', location.origin);
                url.searchParams.set('id', aggId);
                url.searchParams.set('before_seq', before);
                url.searchParams.set('limit', limit);

                fetch(url)
                    .then(res => {
                        if (!res.ok) throw new Error('Failed to load events');
                        return res.json();
                    })
                    .then(data => {
                        const events = data.items || [];
                        if (events.length === 0) {
                            loadOlderBtn.disabled = true;
                            return;
                        }
                        events.forEach(event => {
                            const row = createEventRow(event);
                            rowsContainer.appendChild(row);
                        });
                        before = data.next_before_seq ?? before;
                        loadOlderBtn.disabled = !data.has_more;
                    })
                    .catch(() => {
                        loadOlderBtn.disabled = false;
                    });
            }

            // Utility: get short class name from FQCN
            function shortClassName(fqcn) {
                if (!fqcn) return '';
                const parts = String(fqcn).split('\\');
                return parts[parts.length - 1] || '';
            }

            // Open modal and fetch state at aggregate sequence (enriched version)
            function openModal(toAggSeq, whenStr, eventType) {
                const modalAggType = document.getElementById('modal-agg-type');
                const modalAggTypeFull = document.getElementById('modal-agg-type-full');
                const modalAggId = document.getElementById('modal-agg-id');
                const modalWhen = document.getElementById('modal-when');
                const modalJsonPre = document.getElementById('modal-json');
                const modalCopy = document.getElementById('modal-copy-json');
                const modalPayloadTitle = document.getElementById('modal-payload-title');

                // Prime header immediately
                modalAggId.textContent = aggId || '—';
                modalAggId.title = aggId || '';
                modalWhen.textContent = whenStr || '—';
                modalAggType.textContent = '—';
                modalAggTypeFull.textContent = '';
                modalAggTypeFull.title = '';
                modalJsonPre.textContent = 'Loading…';
                // Set payload title
                const evtShort = eventType ? humanizeEvent(eventType) : null;
                modalPayloadTitle.textContent = evtShort
                    ? `Aggregate state at the time of the ${evtShort} event`
                    : 'Aggregate state at the time of event';

                modalBackdrop.classList.remove('hidden');

                const url = new URL('{{ route('pillar.ui.api.aggregate.state') }}', location.origin);
                url.searchParams.set('id', aggId);
                url.searchParams.set('to_agg_seq', toAggSeq);

                fetch(url)
                    .then(res => {
                        if (!res.ok) throw new Error('Failed to load state');
                        return res.json();
                    })
                    .then(payload => {
                        // Type header
                        const full = payload.aggregate_class || '';
                        modalAggType.textContent = shortClassName(full) || 'Unknown';
                        modalAggTypeFull.textContent = full ? `(${full})` : '';
                        modalAggTypeFull.title = full || '';

                        // Filter state for display: hide _class, id, reconstituting
                        const state = payload.state || {};
                        let pretty = '';
                        try {
                            const filtered = { ...state };
                            delete filtered._class;
                            delete filtered.id;
                            delete filtered.reconstituting;
                            pretty = JSON.stringify(filtered, null, 2);
                        } catch {
                            pretty = String(state);
                        }
                        modalJsonPre.textContent = pretty;

                        // Copy filtered JSON
                        modalCopy.onclick = (e) => {
                            e.preventDefault();
                            copyToClipboard(pretty, 'JSON');
                        };
                    })
                    .catch(() => {
                        modalJsonPre.textContent = 'Sorry, unable to load state at this time.';
                    });
            }

            // Close modal
            modalClose.addEventListener('click', () => {
                modalBackdrop.classList.add('hidden');
                if (modalContent) { modalContent.textContent = ''; }
            });
            modalBackdrop.addEventListener('click', e => {
                if (e.target === modalBackdrop) {
                    modalBackdrop.classList.add('hidden');
                    if (modalContent) { modalContent.textContent = ''; }
                }
            });

            // Load initial events and bind button
            loadOlderBtn.addEventListener('click', loadEvents);
            loadEvents();
        })();
    </script>
    <div id="pillar-toast" class="fixed bottom-5 right-5 z-50 hidden"></div>
@endsection