<?php

namespace Pillar\Outbox;

interface Partitioner
{
    public function keyForBucket(string $aggregateId): ?string;
}
