<?php

namespace Tests\Fixtures\Event;

use Tests\Fixtures\TxProbe\TxProbeId;

class TxProbeCreated
{

    public function __construct(
        public TxProbeId $id,
    )
    {
    }
}