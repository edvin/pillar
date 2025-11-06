<?php

namespace Tests\Fixtures\Serialization;

final class PingEvent
{
    public function __construct(
        public readonly string $id,
        public readonly string $title,
        /** @var array<string,int> */
        public readonly array  $meta = [],
    )
    {
    }
}