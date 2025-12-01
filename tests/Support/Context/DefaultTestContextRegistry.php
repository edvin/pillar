<?php

namespace Tests\Support\Context;

use Pillar\Context\ContextRegistry;
use Pillar\Context\EventMapBuilder;
use Tests\Fixtures\Document\DocumentCreated;
use Tests\Fixtures\Document\DocumentId;
use Tests\Fixtures\Document\DocumentRenamed;
use Tests\Fixtures\Event\TxProbeCreated;
use Tests\Fixtures\Event\TxProbeRenamed;
use Tests\Fixtures\Projectors\TitleListProjector;
use Tests\Fixtures\TxProbe\TxProbeId;

final class DefaultTestContextRegistry implements ContextRegistry
{
    public function name(): string
    {
        return 'test';
    }

    public function commands(): array
    {
        return [];
    }

    public function queries(): array
    {
        return [];
    }

    public function events(): EventMapBuilder
    {
        return EventMapBuilder::create()
            ->event(DocumentCreated::class)
            ->listeners([TitleListProjector::class])
            ->event(DocumentRenamed::class)
            ->listeners([TitleListProjector::class]);
    }

    public function aggregateRootIds(): array
    {
        return [
            DocumentId::class,
            TxProbeId::class,
        ];
    }
}