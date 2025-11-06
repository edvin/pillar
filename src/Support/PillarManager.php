<?php

namespace Pillar\Support;

use Pillar\Aggregate\AggregateSession;
use Pillar\Facade\CommandBus;
use Pillar\Facade\QueryBus;

final class PillarManager
{
    public function session(): AggregateSession
    {
        return app(AggregateSession::class);
    }

    public function dispatch(object $command): void
    {
        CommandBus::dispatch($command);
    }

    public function ask(object $query): mixed
    {
        return QueryBus::ask($query);
    }

}