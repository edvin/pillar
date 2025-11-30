<?php

namespace Tests\Fixtures\Event;

class TxProbeRenamed
{

    public function __construct(
        public string $title,
    )
    {
    }
}