<?php

namespace Tests\Fixtures\Serialization;

final class BadEvent
{
    // Int strictly required — we'll feed a string to trigger a type error during hydrate
    public function __construct(public int $n) {}
}