<?php

namespace Pillar\Bus;

interface CommandBusInterface
{
    public function dispatch(object $command): mixed;

    public function map(array $map): void;
}
