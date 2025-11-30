<?php

namespace Tests\Fixtures\Document;

use Pillar\Event\ShouldPublish;

class DocumentRenamed implements ShouldPublish
{
    public function __construct(
        public DocumentId $id,
        public string     $title
    )
    {
    }
}