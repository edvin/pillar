<?php

namespace Tests\Fixtures\Serialization;

final class RecursiveEvent
{
    /** @param array<string,mixed> $meta */
    public function __construct(public array $meta)
    {
    }
}