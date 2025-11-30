<?php

namespace Tests\Fixtures\TxProbe;

use Pillar\Aggregate\AggregateRootId;

final readonly class TxProbeId extends AggregateRootId
{
    public static function aggregateClass(): string
    {
        return TxProbe::class;
    }
}
