<?php

namespace Tests\Fixtures\Bus;

final class EchoQuery
{
    public function __construct(public string $input)
    {
    }
}