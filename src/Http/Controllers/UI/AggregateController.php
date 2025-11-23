<?php
// @codeCoverageIgnoreStart

namespace Pillar\Http\Controllers\UI;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use JsonSerializable;
use Pillar\Aggregate\AggregateRootId;
use Pillar\Aggregate\GenericAggregateId;
use Pillar\Aggregate\AggregateRegistry;
use Pillar\Event\EventStore;
use Pillar\Event\EventWindow;
use Pillar\Snapshot\Snapshottable;
use Throwable;
use function array_reverse;
use function array_shift;
use function count;
use DateTimeImmutable;
use DateTimeInterface;
use Pillar\Repository\EventStoreRepository;
use ReflectionClass;

final class AggregateController extends Controller
{
    public function __construct(
        private readonly EventStore           $events,
        private readonly EventStoreRepository $repo,
        private readonly AggregateRegistry    $aggregates,
    )
    {
    }

    /**
     * Resolve the typed AggregateRootId plus ID class and aggregate type from a stream name.
     * Falls back to GenericAggregateId when the registry cannot resolve the prefix.
     *
     * @return array{0: AggregateRootId, 1: ?string, 2: ?string}
     */
    private function resolveIdMeta(string $streamName): array
    {

        try {
            $id = $this->aggregates->idFromStreamName($streamName);
            $idClass = $id::class;
            /** @var class-string $idClass */
            $aggregateType = $idClass::aggregateClass();
            return [$id, $idClass, $aggregateType];
        } catch (Throwable) {
            // Unknown prefix or invalid stream name; fall back to a generic ID wrapper.
            $generic = GenericAggregateId::from($streamName);
            return [$generic, null, null];
        }
    }

    public function show(Request $request)
    {
        $streamName = (string)$request->query('id', '');

        [$_typedId, $idClass, $aggregateType] = $this->resolveIdMeta($streamName);

        return view('pillar-ui::aggregate', [
            'id' => $streamName,
            'aggregate_id_class' => $idClass,
            'aggregate_type' => $aggregateType,
        ]);
    }

    // JSON: paged backward by global sequence for a single stream (aggregate)
    public function events(Request $request)
    {
        $streamName = (string)$request->query('id', '');
        if ($streamName === '') {
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

        try {
            $aggregateId = $this->aggregates->idFromStreamName($streamName);
        } catch (Throwable) {
            // If the stream name is unknown, fall back to a generic ID; this will simply yield no events.
            $aggregateId = GenericAggregateId::from($streamName);
        }

        // Stream ASC, keep a rolling buffer of the last N; use $seen to decide has_more.
        $buffer = [];
        $seen = 0;
        foreach ($this->events->streamFor($aggregateId, $window) as $stored) {
            $seen++;
            $buffer[] = $stored;
            if (count($buffer) > $limit) {
                array_shift($buffer);
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
                'stream_sequence' => $e->streamSequence,
                'occurred_at' => $e->occurredAt,
                'type' => $e->eventType,
                'event' => $e->event,
                'version' => $e->eventVersion,
                'storedVersion' => $e->storedVersion,
                'upcasters' => $e->upcasters,
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

    /**
     * Return the aggregate state as of a given event bound.
     * Accepts one of:
     *   - to_stream_seq  (inclusive per-stream sequence)
     *   - to_global_seq  (inclusive global sequence)
     *   - to_date        (ISO8601 UTC timestamp)
     */
    public function state(Request $request)
    {
        $streamName = (string)$request->query('id', '');
        if ($streamName === '') {
            return response()->json(['error' => '"id" is required.'], 422);
        }

        [$aggregateId, $_idClass, $_aggregateType] = $this->resolveIdMeta($streamName);

        // Build an optional window from query params (pick the first provided)
        $window = null;

        $toStream = $request->query('to_stream_seq');
        $toGlob = $request->query('to_global_seq');
        $toDate = $request->query('to_date');

        if ($toStream !== null && $toStream !== '') {
            $window = EventWindow::toStreamSeq((int)$toStream);
        } elseif ($toGlob !== null && $toGlob !== '') {
            $window = EventWindow::toGlobalSeq((int)$toGlob);
        } elseif ($toDate !== null && $toDate !== '') {
            try {
                $dt = new DateTimeImmutable((string)$toDate);
            } catch (Throwable $e) {
                return response()->json(['error' => 'Invalid to_date; expected ISO8601'], 422);
            }
            $window = EventWindow::toDateUtc($dt);
        }

        $loaded = $this->repo->find($aggregateId, $window);
        if ($loaded === null) {
            return response()->json(['error' => 'Aggregate not found'], 404);
        }

        $aggregate = $loaded->aggregate;
        $state = $this->normalizeAggregate($aggregate);

        return response()->json([
            'id' => $streamName,
            'aggregate_class' => get_class($aggregate),
            'version' => $loaded->version,
            'window' => [
                'to_stream_seq' => isset($toStream) && $toStream !== '' ? (int)$toStream : null,
                'to_global_seq' => isset($toGlob) && $toGlob !== '' ? (int)$toGlob : null,
                'to_date' => isset($dt) ? $dt->format(DateTimeInterface::ATOM) : null,
            ],
            'state' => $state,
        ]);
    }

    /**
     * Best-effort normalization of an aggregate object to an array for inspection.
     * Tries toArray(), then JsonSerializable, then reflection.
     */
    private function normalizeAggregate(object $aggregate): array
    {
        if ($aggregate instanceof Snapshottable) {
            return $aggregate->toSnapshot();
        }

        if ($aggregate instanceof JsonSerializable) {
            $out = $aggregate->jsonSerialize();
            return is_array($out) ? $out : (array)$out;
        }

        return $this->reflectObject($aggregate);
    }

    private function reflectObject(object $obj, int $depth = 0): array
    {
        if ($depth > 3) {
            return ['_truncated' => true];
        }

        $ref = new ReflectionClass($obj);
        $data = ['_class' => $ref->getName()];

        foreach ($ref->getProperties() as $prop) {
            $name = $prop->getName();

            try {
                $value = $prop->getValue($obj);
            } catch (Throwable) {
                $data[$name] = '**inaccessible**';
                continue;
            }

            $data[$name] = $this->normalizeValue($value, $depth + 1);
        }

        return $data;
    }

    private function normalizeValue(mixed $value, int $depth): mixed
    {
        if (is_scalar($value) || $value === null) {
            return $value;
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format(DateTimeInterface::ATOM);
        }

        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                $out[$k] = $this->normalizeValue($v, $depth + 1);
            }
            return $out;
        }

        if (is_object($value)) {
            return $this->reflectObject($value, $depth + 1);
        }

        // fallback string cast (resources, etc.)
        return (string)$value;
    }
}
// @codeCoverageIgnoreEnd
