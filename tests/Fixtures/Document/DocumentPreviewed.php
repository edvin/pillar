<?php

namespace Tests\Fixtures\Document;

use Pillar\Event\EphemeralEvent;

final class DocumentPreviewed implements EphemeralEvent
{
    public function __construct(
        public readonly string $title
    )
    {
    }
}