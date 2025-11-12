<?php

namespace Pillar\Outbox;

use Illuminate\Container\Attributes\Config;

final class Crc32Partitioner implements Partitioner
{
    public function __construct(
        #[Config('pillar.outbox.partition_count', 64)]
        private int $partitionCount
    )
    {

    }

    public function keyForBucket(string $aggregateId): ?string
    {
        if ($this->partitionCount <= 1) return null;
        $hash = (int)sprintf('%u', crc32($aggregateId));
        $n = $hash % $this->partitionCount;
        return sprintf('p%02d', $n);
    }
}