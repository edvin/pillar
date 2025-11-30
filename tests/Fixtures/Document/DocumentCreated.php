<?php

namespace Tests\Fixtures\Document;

use Pillar\Event\ShouldPublish;

final class DocumentCreated implements ShouldPublish
{
    public function __construct(
        public DocumentId $id,
        public string     $title
    )
    {
    }
}
