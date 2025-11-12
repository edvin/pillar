<?php

namespace Tests\Fixtures\Document;

use Pillar\Event\ShouldPublishInline;

class DocumentRenamed implements ShouldPublishInline
{
    public function __construct(
        public DocumentId $id,
        public string     $title
    )
    {
    }
}