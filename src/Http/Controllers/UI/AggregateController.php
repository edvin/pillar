<?php
// @codeCoverageIgnoreStart

namespace Pillar\Http\Controllers\UI;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Pillar\Aggregate\GenericAggregateId;
use Pillar\Event\EventStore;
use Pillar\Event\EventWindow;
use function array_reverse;
use function array_shift;
use function count;

final class AggregateController extends Controller
{
    public function __construct(private readonly EventStore $events)
    {
    }

    public function show(Request $request)
    {
        return view('pillar-ui::aggregate', [
            'id' => (string)$request->query('id', ''),
        ]);
    }

    // JSON: paged backward by global sequence for a single aggregate
    public function events(Request $request)
    {
        $rawId = (string)$request->query('id', '');
        if ($rawId === '') {
            return response()->json(['error' => '"id" is required.'], 422);
        }

        $limit = max(1, min(
            (int)$request->query('limit', (int)config('pillar.ui.page_size', 100)),
            500
        ));

        // We treat "before_seq" as an exclusive upper bound.
        // Since EventWindow::toGlobalSeq(...) is inclusive, subtract 1 when present.
        $before = $request->query('before_seq');
        $window = null;
        if ($before !== null && $before !== '') {
            $b = max(0, (int)$before - 1);
            $window = EventWindow::toGlobalSeq($b);
        }

        $aggregateId = GenericAggregateId::from($rawId);

        // Stream ASC, keep a rolling buffer of the last N, stop after N+1 to know has_more.
        $buffer = [];
        $seen = 0;
        foreach ($this->events->load($aggregateId, $window) as $stored) {
            $seen++;
            $buffer[] = $stored;
            if (count($buffer) > $limit) {
                array_shift($buffer);
            }
            if ($seen > $limit) {
                break; // we have enough to decide has_more
            }
        }

        if ($buffer === []) {
            return response()->json([
                'items' => [],
                'next_before_seq' => null,
                'has_more' => false,
            ]);
        }

        // Descending for the timeline
        $items = [];
        foreach (array_reverse($buffer) as $e) {
            $items[] = [
                'sequence' => $e->sequence,
                'aggregate_sequence' => $e->aggregateSequence,
                'occurred_at' => $e->occurredAt,
                'type' => $e->eventType,
                'event' => $e->event,
                'version' => $e->eventVersion,
            ];
        }

        // For the next page, use the smallest global sequence we returned as the new "before_seq"
        $minSeq = $buffer[0]->sequence;

        return response()->json([
            'items' => $items,
            'next_before_seq' => $minSeq,
            'has_more' => $seen > $limit,
        ]);
    }
}
// @codeCoverageIgnoreEnd
