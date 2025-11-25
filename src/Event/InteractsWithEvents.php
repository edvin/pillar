<?php
// @codeCoverageIgnoreStart
namespace Pillar\Event;

use Carbon\CarbonImmutable;
use Pillar\Aggregate\AggregateRootId;

trait InteractsWithEvents
{
    protected function aggregateRootId(): ?AggregateRootId
    {
        return EventContext::aggregateRootId();
    }

    protected function aggregateRootIdAs(string $idClass): ?AggregateRootId
    {
        $id = EventContext::aggregateRootId();

        return $id instanceof $idClass ? $id : null;
    }

    protected function correlationId(): ?string
    {
        return EventContext::correlationId();
    }

    protected function occurredAt(): ?CarbonImmutable
    {
        return EventContext::occurredAt();
    }
}
// @codeCoverageIgnoreEnd