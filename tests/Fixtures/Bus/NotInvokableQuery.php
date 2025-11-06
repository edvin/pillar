<?php

namespace Tests\Fixtures\Bus;
final class NotInvokableQuery
{
    public function __construct(public string $payload)
    {
    }
}
