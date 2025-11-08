<?php

declare(strict_types=1);

namespace Tests\Fixtures\Encryption;

final class OtherEvent
{
    public function __construct(
        public string $id,
        public string $title,
    ) {}
}
