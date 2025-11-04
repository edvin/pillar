<?php

namespace Pillar\Bus;

use Illuminate\Contracts\Bus\Dispatcher;
use Pillar\Event\EventContext;

class LaravelCommandBus implements CommandBusInterface
{
    public function __construct(private Dispatcher $dispatcher)
    {
    }

    public function dispatch(object $command): mixed
    {
        EventContext::initialize();
        return $this->dispatcher->dispatchSync($command);
    }

    public function map(array $map): void
    {
        $this->dispatcher->map($map);
    }
}
