<?php

declare(strict_types=1);

namespace Tests\Fixtures\Encryption;

final class DummyEvent
{
    public function __construct(
        public string $id,
        public string $title,
    ) {}
}
