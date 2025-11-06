<?php

namespace Tests\Fixtures\Document;

use Pillar\Aggregate\AggregateRootId;

final readonly class DocumentId extends AggregateRootId
{
    public static function aggregateClass(): string
    {
        return Document::class;
    }
}
