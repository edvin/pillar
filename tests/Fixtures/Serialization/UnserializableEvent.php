<?php

namespace Tests\Fixtures\Serialization;

use Closure;

final class UnserializableEvent
{
    /** @var Closure */
    public $callback;

    public function __construct(Closure $callback)
    {
        $this->callback = $callback;
    }
}