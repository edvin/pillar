<?php

namespace Pillar\Aggregate;

interface EventSourcedAggregateRoot extends AggregateRoot
{
    /** @return object[] */
    public function recordedEvents(): array;

    /** @return object[] */
    public function releaseEvents(): array;

    public function apply(object $event): void;
}