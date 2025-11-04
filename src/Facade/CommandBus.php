<?php

namespace Pillar\Facade;

use Pillar\Bus\CommandBusInterface;
use Illuminate\Support\Facades\Facade;

/**
 * @method static void dispatch(object $command)
 * @method void map(array $array)
 */
class CommandBus extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return CommandBusInterface::class;
    }
}
