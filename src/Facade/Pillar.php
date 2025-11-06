<?php

namespace Pillar\Facade;

use Illuminate\Support\Facades\Facade;
use Pillar\Aggregate\AggregateSession;
use Pillar\Support\PillarManager;

/**
 * Convenience facade for common Pillar operations.
 *
 * @method static AggregateSession session()
 * @method static void dispatch(object $command)
 * @method static mixed ask(object $query)
 *
 * @see PillarManager
 */
class Pillar extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return PillarManager::class;
    }
}