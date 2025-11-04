<?php

namespace Pillar\Bus;

use Illuminate\Contracts\Container\Container;
use RuntimeException;


class InMemoryQueryBus implements QueryBusInterface
{
    private array $handlers = [];

    public function __construct(private Container $container)
    {
    }

    public function ask(object $query): mixed
    {
        $queryClass = get_class($query);

        if (!isset($this->handlers[$queryClass])) {
            throw new RuntimeException("No handler registered for query {$queryClass}");
        }

        $handler = $this->container->make($this->handlers[$queryClass]);

        if (!is_callable($handler)) {
            throw new RuntimeException("Handler {$this->handlers[$queryClass]} is not invokable.");
        }

        return $handler($query);
    }

    public function map(array $map): void
    {
        $this->handlers = array_merge($this->handlers, $map);
    }
}
