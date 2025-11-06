<?php

namespace Tests\Support\Context;

use Pillar\Context\ContextRegistry;
use Pillar\Context\EventMapBuilder;
use Tests\Fixtures\Document\DocumentCreated;

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
            ->listeners([]);
    }
}