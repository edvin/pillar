<?php

namespace Pillar\Facade;

use Pillar\Bus\QueryBusInterface;
use Illuminate\Support\Facades\Facade;

/**
 * @method static ask(object $param)
 * @method void map(array $array)
 * @see QueryBusInterface
 */
class QueryBus extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return QueryBusInterface::class;
    }
}
