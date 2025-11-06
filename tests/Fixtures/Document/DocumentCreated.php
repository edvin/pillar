<?php

namespace Tests\Fixtures\Document;

final class DocumentCreated
{
    public function __construct(
        public DocumentId $id,
        public string     $title
    )
    {
    }
}
