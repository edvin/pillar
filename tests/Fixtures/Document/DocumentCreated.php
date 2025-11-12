<?php

namespace Tests\Fixtures\Document;

use Pillar\Event\ShouldPublishInline;

final class DocumentCreated implements ShouldPublishInline
{
    public function __construct(
        public DocumentId $id,
        public string     $title
    )
    {
    }
}
