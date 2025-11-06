<?php
// @codeCoverageIgnoreStart
namespace Pillar\Facade;

use Illuminate\Support\Facades\Facade;
use Pillar\Bus\QueryBusInterface;

/**
 * @method static mixed ask(object $query)
 * @method static void map(array $map)
 * @mixin QueryBusInterface
 * @see QueryBusInterface
 */
class QueryBus extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return QueryBusInterface::class;
    }
}
// @codeCoverageIgnoreEnd