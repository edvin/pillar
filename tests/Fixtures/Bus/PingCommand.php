<?php

namespace Tests\Fixtures\Bus;

final class PingCommand
{
    public function __construct(public string $message)
    {
    }
}