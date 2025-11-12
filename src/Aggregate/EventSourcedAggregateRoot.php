<?php

namespace Pillar\Aggregate;

interface EventSourcedAggregateRoot extends AggregateRoot
{
    /** @return object[] */
    public function recordedEvents(): array;

    public function clearRecordedEvents(): void;

    public function apply(object $event): void;
}