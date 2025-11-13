@extends('pillar-ui::layout')

@section('content')
    <div class="max-w-6xl mx-auto p-6">
        <a href="{{ route('pillar.ui.index') }}" class="text-sm text-blue-600 hover:underline">&larr; Back to overview</a>
        <h1 class="text-3xl font-semibold mb-6">Outbox &amp; Worker Status</h1>

        {{-- Top KPIs --}}
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
            <div class="rounded-lg border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 p-4">
                <div class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400 mb-1">Active workers</div>
                <div id="kpi-workers" class="text-2xl font-semibold">—</div>
            </div>
            <div class="rounded-lg border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 p-4">
                <div class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400 mb-1">Pending backlog</div>
                <div id="kpi-backlog" class="text-2xl font-semibold">—</div>
            </div>
            <div class="rounded-lg border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 p-4">
                <div class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400 mb-1">Published (last hour)</div>
                <div id="kpi-pub1h" class="text-2xl font-semibold">—</div>
            </div>
        </div>

        {{-- Throughput (full width) --}}
        <div class="mb-6">
            <div class="rounded-lg border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 p-4">
                <div class="flex items-center justify-between">
                    <div class="text-sm font-medium">Throughput (last 60 minutes)</div>
                    <div class="text-xs text-slate-500 dark:text-slate-400">per minute</div>
                </div>
                <svg id="sparkline" class="mt-3 w-full h-48"></svg>
            </div>
        </div>

        {{-- Workers (moved before partitions) --}}
        <div class="rounded-lg border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 overflow-hidden mb-6">
            <div class="px-4 py-3 bg-slate-50 dark:bg-slate-800/50 text-sm font-medium">Workers</div>
            <table class="min-w-full text-sm">
                <thead class="bg-slate-50 dark:bg-slate-800/50 text-slate-600 dark:text-slate-300">
                <tr>
                    <th class="pl-4 pr-3 py-2 text-left">ID</th>
                    <th class="px-3 py-2 text-left">Host</th>
                    <th class="px-3 py-2 text-left">PID</th>
                    <th class="px-3 py-2 text-left">Started</th>
                    <th class="px-3 py-2 text-left">TTL</th>
                    <th class="px-3 pr-4 py-2 text-left">Status</th>
                </tr>
                </thead>
                <tbody id="workers-body" class="divide-y divide-slate-200 dark:divide-slate-700"></tbody>
            </table>
        </div>

        {{-- Partitions (own full-width line) --}}
        <div class="rounded-lg border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 overflow-hidden mb-6">
            <div class="px-4 py-3 bg-slate-50 dark:bg-slate-800/50 text-sm font-medium">Partitions</div>
            <div id="partitions-grid" class="p-3 grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 gap-2"></div>
        </div>

        {{-- Messages --}}
        <div class="rounded-lg border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 overflow-hidden mb-6">
            <div class="px-4 py-3 bg-slate-50 dark:bg-slate-800/50 flex items-center justify-between">
                <div class="text-sm font-medium">Outbox Messages</div>
                <div class="flex items-center gap-2 text-sm">
                    <label class="text-slate-600 dark:text-slate-300">View:</label>
                    <select id="msg-status" class="bg-transparent border border-slate-200 dark:border-slate-700 rounded-md px-2 py-1 text-sm">
                        <option value="pending">Pending</option>
                        <option value="published">Published</option>
                        <option value="all">All</option>
                    </select>
                </div>
            </div>
            <table class="min-w-full text-sm">
                <thead class="bg-slate-50 dark:bg-slate-800/50 text-slate-600 dark:text-slate-300">
                <tr>
                    <th class="pl-4 pr-3 py-2 text-left">Seq</th>
                    <th class="px-3 py-2 text-left">Event</th>
                    <th class="px-3 py-2 text-left">Partition</th>
                    <th class="px-3 py-2 text-left">Available</th>
                    <th class="px-3 py-2 text-left">Published</th>
                    <th class="px-3 py-2 text-left">Attempts</th>
                    <th class="px-3 pr-4 py-2 text-left">Last error</th>
                </tr>
                </thead>
                <tbody id="msgs-body" class="divide-y divide-slate-200 dark:divide-slate-700"></tbody>
            </table>
        </div>
    </div>

    <script>
        (function(){
            let __sparkLast = [];
            const fmt = (s)=> s ?? '—';
            const rel = (sec) => sec == null
                ? '—'
                : (sec <= 0 ? 'exp.' : (Math.ceil(Math.max(0, Number(sec))) + 's'));
            const fmtIsoSeconds = (iso) => {
                if (!iso) return '—';
                try {
                    const d = new Date(String(iso));
                    if (isNaN(d.getTime())) {
                        // Fallback: strip fractional seconds if present
                        return String(iso).replace(/\.\d+Z$/, 'Z');
                    }
                    // Use UTC ISO, trimmed to seconds, prettified
                    const s = d.toISOString().replace(/\.\d+Z$/, 'Z');
                    return s.replace('T', ' ').replace('Z', ' UTC');
                } catch {
                    return String(iso);
                }
            };
            const pad2 = (n) => String(n).padStart(2, '0');
            const fmtIsoMinutes = (iso) => {
                if (!iso) return '—';
                try {
                    const d = new Date(String(iso));
                    if (isNaN(d.getTime())) {
                        // Fallback: best-effort trimming of seconds/offsets
                        return String(iso)
                            .replace('T', ' ')
                            .replace(/:\d{2}(Z|[+-]\d{2}:\d{2})$/, ' UTC')
                            .replace(/\.\d+ UTC$/, ' UTC');
                    }
                    return `${d.getUTCFullYear()}-${pad2(d.getUTCMonth()+1)}-${pad2(d.getUTCDate())} ${pad2(d.getUTCHours())}:${pad2(d.getUTCMinutes())} UTC`;
                } catch {
                    return String(iso);
                }
            };
            const humanizeEvent = (type) => {
                if (!type) return '—';
                let short = String(type).split(/[\\.]/).pop();
                return short.replace(/([a-z])([A-Z])/g, '$1 $2');
            };

            function drawSparkline(svgId, values){
                const svg = document.getElementById(svgId);
                if (!svg) return;

                __sparkLast = Array.isArray(values) ? values : [];

                // Make the drawing space match the actual on-screen size
                const w = Math.max(600, svg.clientWidth || 600);
                const h = Math.max(160, svg.clientHeight || 160);

                // Tighter horizontal padding so the line reaches closer to the edges
                const padX = Math.max(16, Math.min(40, Math.round(w * 0.03)));
                const padY = 24;

                svg.setAttribute('viewBox', `0 0 ${w} ${h}`);
                svg.innerHTML = '';

                // Helper and minute grid (60 ticks; stronger at 15 and 30)
                const mk = (name) => document.createElementNS('http://www.w3.org/2000/svg', name);
                function drawMinuteTicks(){
                    const slots = 60; // 60 minutes
                    if (slots < 2) return;
                    const slotStep = (w - padX * 2) / (slots - 1);
                    for (let i = 0; i < slots; i++) {
                        const X = padX + i * slotStep;
                        const tick = mk('line');
                        tick.setAttribute('x1', X);
                        tick.setAttribute('x2', X);
                        tick.setAttribute('y1', padY / 2);
                        tick.setAttribute('y2', h - padY);
                        let opacity = 0.10, sw = 1;
                        if (i % 30 === 0) { opacity = 0.35; sw = 2; }
                        else if (i % 15 === 0) { opacity = 0.20; sw = 1.5; }
                        tick.setAttribute('stroke', 'currentColor');
                        tick.setAttribute('stroke-opacity', String(opacity));
                        tick.setAttribute('stroke-width', String(sw));
                        tick.setAttribute('vector-effect', 'non-scaling-stroke');
                        svg.appendChild(tick);
                    }
                }
                function drawMinuteLabels(){
                    // Labels every 10 minutes: -60m, -50m, ..., -10m, now (start at 10 to avoid -60m overlap)
                    const total = 60;
                    const span = (w - padX * 2);
                    for (let m = 10; m <= total; m += 10) {
                        const x = padX + (m / total) * span;
                        const t = mk('text');
                        t.setAttribute('x', x);
                        t.setAttribute('y', h - padY + 16);
                        t.setAttribute('text-anchor', 'middle');
                        t.setAttribute('font-size', '10');
                        t.setAttribute('fill', 'currentColor');
                        t.setAttribute('fill-opacity', '0.6');
                        t.textContent = (m === total) ? 'now' : `-${total - m}m`;
                        svg.appendChild(t);
                    }
                }

                if (!__sparkLast || __sparkLast.length === 0) {
                    // Draw axes only
                    drawMinuteTicks();
                    const axisX = mk('line');
                    axisX.setAttribute('x1', padX);
                    axisX.setAttribute('y1', h - padY);
                    axisX.setAttribute('x2', w - padX);
                    axisX.setAttribute('y2', h - padY);
                    axisX.setAttribute('stroke', 'currentColor');
                    axisX.setAttribute('stroke-opacity', '0.25');
                    axisX.setAttribute('vector-effect', 'non-scaling-stroke');
                    svg.appendChild(axisX);

                    const axisY = mk('line');
                    axisY.setAttribute('x1', padX);
                    axisY.setAttribute('y1', padY / 2);
                    axisY.setAttribute('x2', padX);
                    axisY.setAttribute('y2', h - padY);
                    axisY.setAttribute('stroke', 'currentColor');
                    axisY.setAttribute('stroke-opacity', '0.25');
                    axisY.setAttribute('vector-effect', 'non-scaling-stroke');
                    svg.appendChild(axisY);

                    const t0 = mk('text');
                    t0.setAttribute('x', padX - 6);
                    t0.setAttribute('y', h - padY + 12);
                    t0.setAttribute('text-anchor', 'end');
                    t0.setAttribute('font-size', '10');
                    t0.setAttribute('fill', 'currentColor');
                    t0.setAttribute('fill-opacity', '0.6');
                    t0.textContent = '0';
                    svg.appendChild(t0);
                    drawMinuteLabels();
                    return;
                }

                const max = Math.max(1, Math.max(...__sparkLast));
                const count = Math.max(1, __sparkLast.length);
                const step = (w - padX * 2) / (count - 1);
                const y = (v) => h - padY - (v / max) * (h - padY * 2);

                drawMinuteTicks();

                // Axes
                const axisX = mk('line');
                axisX.setAttribute('x1', padX);
                axisX.setAttribute('y1', h - padY);
                axisX.setAttribute('x2', w - padX);
                axisX.setAttribute('y2', h - padY);
                axisX.setAttribute('stroke', 'currentColor');
                axisX.setAttribute('stroke-opacity', '0.25');
                axisX.setAttribute('vector-effect', 'non-scaling-stroke');
                svg.appendChild(axisX);

                const axisY = mk('line');
                axisY.setAttribute('x1', padX);
                axisY.setAttribute('y1', padY / 2);
                axisY.setAttribute('x2', padX);
                axisY.setAttribute('y2', h - padY);
                axisY.setAttribute('stroke', 'currentColor');
                axisY.setAttribute('stroke-opacity', '0.25');
                axisY.setAttribute('vector-effect', 'non-scaling-stroke');
                svg.appendChild(axisY);

                // Line
                let d = '';
                __sparkLast.forEach((v, i) => {
                    const X = padX + i * step;
                    const Y = y(v);
                    d += (i === 0 ? 'M' : 'L') + X + ' ' + Y + ' ';
                });
                const path = mk('path');
                path.setAttribute('d', d);
                path.setAttribute('fill', 'none');
                path.setAttribute('stroke', 'currentColor');
                path.setAttribute('stroke-width', '2');
                path.setAttribute('stroke-linecap', 'round');
                path.setAttribute('vector-effect', 'non-scaling-stroke');
                svg.appendChild(path);

                // Labels
                const t0 = mk('text');
                t0.setAttribute('x', padX - 6);
                t0.setAttribute('y', h - padY + 12);
                t0.setAttribute('text-anchor', 'end');
                t0.setAttribute('font-size', '10');
                t0.setAttribute('fill', 'currentColor');
                t0.setAttribute('fill-opacity', '0.6');
                t0.textContent = '0';
                svg.appendChild(t0);

                const tMax = mk('text');
                tMax.setAttribute('x', padX - 6);
                tMax.setAttribute('y', y(max));
                tMax.setAttribute('dominant-baseline', 'middle');
                tMax.setAttribute('text-anchor', 'end');
                tMax.setAttribute('font-size', '10');
                tMax.setAttribute('fill', 'currentColor');
                tMax.setAttribute('fill-opacity', '0.6');
                tMax.textContent = String(Math.round(max));
                svg.appendChild(tMax);
                drawMinuteLabels();
            }

            async function loadWorkers(){
                const res = await fetch('{{ route('pillar.ui.api.outbox.workers') }}');
                const data = await res.json();
                document.getElementById('kpi-workers').textContent = (data.active_ids||[]).length;

                const tbody = document.getElementById('workers-body');
                tbody.innerHTML='';
                (data.items||[]).forEach(w=>{
                    const tr=document.createElement('tr');
                    tr.innerHTML = `
        <td class="pl-4 pr-3 py-2 font-mono">${fmt(w.id)}</td>
        <td class="px-3 py-2">${fmt(w.hostname)}</td>
        <td class="px-3 py-2">${fmt(w.pid)}</td>
        <td class="px-3 py-2">${fmtIsoSeconds(w.started_at)}</td>
        <td class="px-3 py-2">${rel(w.ttl_sec)}</td>
        <td class="px-3 pr-4 py-2">
          <span class="inline-flex items-center rounded-md px-2 py-0.5 text-xs font-medium ${w.status==='active'?'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-200':'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300'}">
            ${w.status}
          </span>
        </td>`;
                    tbody.appendChild(tr);
                });
            }

            async function loadPartitions(){
                const res = await fetch('{{ route('pillar.ui.api.outbox.partitions') }}');
                const data = await res.json();
                const grid = document.getElementById('partitions-grid');
                grid.innerHTML = '';
                (data.items||[]).forEach(p=>{
                    const div = document.createElement('div');
                    div.className='rounded-md border border-slate-200 dark:border-slate-700 p-2';
                    div.innerHTML = `
        <div class="flex items-center justify-between">
          <div class="font-mono text-xs">${fmt(p.partition_key)}</div>
          <div class="text-xs ${p.owned?'text-green-600 dark:text-green-300':'text-slate-400'}">${p.owned?'owned':'free'}</div>
        </div>
        <div class="mt-1 text-xs text-slate-600 dark:text-slate-300 truncate" title="${fmt(p.lease_owner)}">${fmt(p.lease_owner)}</div>
        <div class="text-[11px] text-slate-500 dark:text-slate-400">ttl ${rel(p.ttl_sec)}</div>`;
                    grid.appendChild(div);
                });
            }

            async function loadMetrics(){
                const res = await fetch('{{ route('pillar.ui.api.outbox.metrics') }}');
                const data = await res.json();
                document.getElementById('kpi-backlog').textContent = data.backlog ?? '—';
                const total = (data.published_1h||[]).reduce((a,b)=>a+b,0);
                document.getElementById('kpi-pub1h').textContent = total;
                drawSparkline('sparkline', data.published_1h||[]);
            }

            async function loadMessages(){
                const status = document.getElementById('msg-status').value;
                const url = new URL('{{ route('pillar.ui.api.outbox.messages') }}', location.origin);
                url.searchParams.set('status', status);
                url.searchParams.set('limit', 150);
                const res = await fetch(url);
                const data = await res.json();
                const tbody = document.getElementById('msgs-body');
                tbody.innerHTML = '';
                (data.items||[]).forEach(m=>{
                    const tr=document.createElement('tr');
                    tr.innerHTML = `
      <td class="pl-4 pr-3 py-2">
        <span class="inline-flex items-center rounded-md px-2 py-0.5 text-xs font-medium bg-blue-50 text-blue-700 dark:bg-blue-900/30 dark:text-blue-200">#${m.seq}</span>
      </td>
      <td class="px-3 py-2 font-mono text-slate-700 dark:text-slate-200">${humanizeEvent(m.event)}</td>
      <td class="px-3 py-2 font-mono">${fmt(m.partition)}</td>
      <td class="px-3 py-2">${fmtIsoMinutes(m.available_at)}</td>
      <td class="px-3 py-2">${fmtIsoMinutes(m.published_at)}</td>
      <td class="px-3 py-2">${m.attempts}</td>
      <td class="px-3 pr-4 py-2 text-slate-600 dark:text-slate-300 truncate" title="${fmt(m.last_error)}">${fmt(m.last_error)}</td>`;
                    tbody.appendChild(tr);
                });
            }

            // initial + polling
            function tick(){
                loadWorkers();
                loadPartitions();
                loadMetrics();
                loadMessages();
            }
            tick();
            setInterval(tick, 5000);
            document.getElementById('msg-status').addEventListener('change', loadMessages);
            window.addEventListener('resize', () => drawSparkline('sparkline', __sparkLast));
        })();
    </script>
@endsection