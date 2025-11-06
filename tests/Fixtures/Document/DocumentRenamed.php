<?php

namespace Tests\Fixtures\Document;

class DocumentRenamed
{
    public function __construct(
        public DocumentId $id,
        public string     $title
    )
    {
    }
}