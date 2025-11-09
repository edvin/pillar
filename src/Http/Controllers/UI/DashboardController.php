<?php
// @codeCoverageIgnoreStart

namespace Pillar\Http\Controllers\UI;

use Illuminate\Routing\Controller;
use Pillar\Event\EventStore;
use Throwable;

final class DashboardController extends Controller
{
    public function __construct(private EventStore $events)
    {
    }

    public function index()
    {
        return view('pillar-ui::index');
    }

    public function recent()
    {
        $limit  = (int) config('pillar.ui.recent_limit', 20);

        // Track latest event per aggregate id while streaming globally.
        $latest = [];

        foreach ($this->events->all(null, null, null) as $evt) {
            $latest[$evt->aggregateId] = $evt; // overwrite so we keep the latest seen
        }

        if (empty($latest)) {
            return response()->json([]);
        }

        // Sort by global sequence desc and clamp to desired size
        $rows = array_values($latest);
        usort($rows, fn($a, $b) => $b->sequence <=> $a->sequence);
        $rows = array_slice($rows, 0, $limit);

        // Map to lightweight payload enriched with (optional) id class and aggregate type
        $out = array_map(function ($e) {
            // Prefer the ID class provided by the store; if missing, ask the store to resolve
            $idClass = $e->aggregateIdClass ?? $this->events->resolveAggregateIdClass($e->aggregateId);
            $aggType = null;

            if (is_string($idClass)) {
                try {
                    /** @var class-string $idClass */
                    $aggType = $idClass::aggregateClass();
                } catch (Throwable) {
                    // Best-effort enrichment only; ignore failures
                }
            }

            return [
                'aggregate_id'       => $e->aggregateId,
                'aggregate_id_class' => $idClass,
                'aggregate_type'     => $aggType,
                'last_seq'           => $e->sequence,            // global sequence of last event
                'aggregate_seq'      => $e->aggregateSequence,   // per-aggregate sequence of last event
                'last_at'            => $e->occurredAt,
                'event_type'         => $e->eventType,
            ];
        }, $rows);

        return response()->json($out);
    }
}
// @codeCoverageIgnoreEnd
