<?php

namespace Pillar\Repository;

use Pillar\Aggregate\AggregateRoot;
use Pillar\Aggregate\AggregateRootId;

interface AggregateRepository
{
    public function find(AggregateRootId $id): ?AggregateRoot;

    public function save(AggregateRoot $aggregate): void;
}