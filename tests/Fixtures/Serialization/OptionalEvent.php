<?php

namespace Tests\Fixtures\Serialization;

final class OptionalEvent
{
    public function __construct(
        public ?int $n = null,
        public string $t = 'x',
    ) {}
}