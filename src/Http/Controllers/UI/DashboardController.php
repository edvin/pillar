<?php
// @codeCoverageIgnoreStart

namespace Pillar\Http\Controllers\UI;

use Illuminate\Container\Attributes\Config;
use Illuminate\Routing\Controller;
use Pillar\Event\EventStore;
use Pillar\Aggregate\AggregateRegistry;
use Throwable;

final class DashboardController extends Controller
{
    public function __construct(
        private EventStore        $events,
        private AggregateRegistry $aggregates,
        #[Config('pillar.ui.recent_limit')]
        private int               $recentLimit,
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
            $idClass = null;

            try {
                $id = $this->aggregates->idFromStreamName($e->streamId);
                $idClass = $id::class;

                /** @var class-string $idClass */
                $aggType = $idClass::aggregateClass();
            } catch (Throwable) {
                // Best-effort enrichment only; ignore failures resolving registry or aggregate class.
            }

            return [
                'aggregate_id'       => $e->streamId,
                'aggregate_id_class' => $idClass,
                'aggregate_type'     => $aggType,
                'last_seq'           => $e->sequence,
                'aggregate_seq'      => $e->streamSequence,
                'last_at'            => $e->occurredAt,
                'event_type'         => $e->eventType,
            ];
        }, $rows);

        return response()->json($out);
    }
}
// @codeCoverageIgnoreEnd
