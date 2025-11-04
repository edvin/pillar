<?php

namespace Pillar\Event;

use Pillar\Aggregate\AggregateRootId;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Replays historical domain events for rebuilding projections.
 */
final class EventReplayer
{
    /**
     * @param array<class-string, array<class-string>> $eventListeners
     *        A mapping of event class → [listener classes]
     */
    /**
     * @param array<class-string, array<class-string>> $eventListeners
     *        A mapping of event class → [listener classes]
     */
    public function __construct(
        private readonly EventStore $eventStore,
        private readonly array      $eventListeners
    )
    {
    }

    /**
     * Replay all events or those matching filters.
     *
     * @param AggregateRootId|null $aggregateId Restrict to one aggregate
     * @param string|null $eventType Restrict to one event class
     * @throws Throwable
     */
    public function replay(?AggregateRootId $aggregateId = null, ?string $eventType = null): void
    {
        $events = $this->eventStore->all($aggregateId, $eventType);

        if (empty($events)) {
            throw new RuntimeException('No events found for replay.');
        }

        $this->replayEvents($events);
    }

    /**
     * @param iterable<StoredEvent> $events
     */
    private function replayEvents(iterable $events): void
    {
        foreach ($events as $storedEvent) {
            EventContext::initialize($storedEvent->occuredAt, $storedEvent->correlationId);

            $listeners = $this->eventListeners[$storedEvent->eventType] ?? [];

            foreach ($listeners as $listenerClass) {
                $listener = App::make($listenerClass);
                if (!is_subclass_of($listener, Projector::class)) {
                    continue;
                }
                Log::info("🎬 Replaying $storedEvent->eventType → $listenerClass");
                $listener($storedEvent->event);
            }

            EventContext::clear();
        }
    }

}
