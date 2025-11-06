<?php

namespace Pillar\Facade;

use Pillar\Bus\CommandBusInterface;
use Illuminate\Support\Facades\Facade;

/**
 * @method static void dispatch(object $command)
 * @method static void map(array $array)
 * @see CommandBusInterface
 * @mixin CommandBusInterface
 */
class CommandBus extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return CommandBusInterface::class;
    }
}
