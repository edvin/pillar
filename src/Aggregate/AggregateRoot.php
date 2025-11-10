<?php

namespace Pillar\Aggregate;

interface AggregateRoot
{
    public function id(): AggregateRootId;
}
