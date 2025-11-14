<?php
// @codeCoverageIgnoreStart

namespace Pillar\Http\Controllers\UI;

use Illuminate\Container\Attributes\Config;
use Illuminate\Routing\Controller;
use Pillar\Event\EventStore;
use Throwable;

final class DashboardController extends Controller
{
    public function __construct(
        private EventStore $events,
        #[Config('pillar.ui.recent_limit')]
        private int $recentLimit
    )
    {
    }

    public function index()
    {
        return view('pillar-ui::index');
    }

    public function recent()
    {
        // Let the event store optimize retrieval of recent events.
        $rows = $this->events->recent($this->recentLimit);

        if (empty($rows)) {
            return response()->json([]);
        }

        // Map to lightweight payload enriched with (optional) id class and aggregate type
        $out = array_map(function ($e) {
            $aggType = null;

            if (is_string($e->aggregateIdClass)) {
                try {
                    /** @var class-string $idClass */
                    $aggType = $e->aggregateIdClass::aggregateClass();
                } catch (Throwable) {
                    // Best-effort enrichment only; ignore failures
                }
            }

            return [
                'aggregate_id'       => $e->aggregateId,
                'aggregate_id_class' => $e->aggregateIdClass,
                'aggregate_type'     => $aggType,
                'last_seq'           => $e->sequence,
                'aggregate_seq'      => $e->aggregateSequence,
                'last_at'            => $e->occurredAt,
                'event_type'         => $e->eventType,
            ];
        }, $rows);

        return response()->json($out);
    }
}
// @codeCoverageIgnoreEnd
