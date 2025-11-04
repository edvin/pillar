<?php

namespace Pillar\Bus;

interface QueryBusInterface
{
    public function ask(object $query): mixed;

    public function map(array $map): void;
}
