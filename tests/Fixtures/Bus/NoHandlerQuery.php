<?php

namespace Tests\Fixtures\Bus;

final class NoHandlerQuery
{
    public function __construct(public string $payload)
    {
    }
}