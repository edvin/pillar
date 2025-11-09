<?php
// @codeCoverageIgnoreStart

namespace Pillar\Http\Controllers\UI;

use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;

final class DashboardController extends Controller
{
    public function index()
    {
        return view('pillar-ui::index');
    }

    // Fast “recent aggregates” for DatabaseEventStore using the default table.
    public function recent()
    {
        $limit = (int)config('pillar.ui.recent_limit', 20);
        $rows = DB::table('events')
            ->selectRaw('aggregate_id, MAX(sequence) as last_seq, MAX(occurred_at) as last_at')
            ->groupBy('aggregate_id')
            ->orderByDesc('last_seq')
            ->limit($limit)
            ->get();

        return response()->json($rows);
    }
}
// @codeCoverageIgnoreEnd
