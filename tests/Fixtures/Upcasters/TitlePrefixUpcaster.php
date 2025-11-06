<?php

namespace Tests\Fixtures\Upcasters;


use Pillar\Event\Upcaster;
use Tests\Fixtures\Document\DocumentRenamed;

final class TitlePrefixUpcaster implements Upcaster
{
    public function upcast(array $payload): array
    {
        // add a simple field to simulate a schema bump
        $payload['title_prefixed'] = 'prefix:' . ($payload['title'] ?? '');
        return $payload;
    }

    public static function eventClass(): string
    {
        return DocumentRenamed::class;
    }

    public static function fromVersion(): int
    {
        return 1;
    }
}