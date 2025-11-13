<?php

namespace Pillar\Outbox;

use Illuminate\Container\Attributes\Config;

final class Crc32Partitioner implements Partitioner
{
    public function __construct(
        #[Config('pillar.outbox.partition_count', 16)]
        private int    $partitionCount,
        #[Config('pillar.outbox.partitioner.label_format', 'p%02d')]
        private string $labelFormat
    )
    {

    }

    public function partitionKeyForAggregate(string $aggregateId): ?string
    {
        if ($this->partitionCount <= 1) return null;
        $hash = (int)sprintf('%u', crc32($aggregateId));
        $n = $hash % $this->partitionCount;
        return $this->formatIndex($n);
    }

    public function labelForIndex(int $index): ?string
    {
        if ($this->partitionCount <= 1) return null;
        if ($index < 0 || $index >= $this->partitionCount) return null;
        return $this->formatIndex($index);
    }

    private function formatIndex(int $i): string
    {
        return sprintf($this->labelFormat, $i);
    }

}