<?php

namespace Pillar\Bus;

use Illuminate\Contracts\Container\Container;
use Pillar\Metrics\Metrics;
use Pillar\Metrics\Counter;
use Pillar\Metrics\Histogram;
use RuntimeException;


class InMemoryQueryBus implements QueryBusInterface
{
    private Counter $queriesCounter;
    private Counter $queriesFailedCounter;
    private Histogram $queryDurationHistogram;

    private array $handlers = [];

    public function __construct(private Container $container, Metrics $metrics)
    {
        $this->queriesCounter = $metrics->counter(
            'queries_total',
            ['query', 'success']
        );

        $this->queriesFailedCounter = $metrics->counter(
            'queries_failed_total',
            ['query']
        );

        $this->queryDurationHistogram = $metrics->histogram(
            'query_duration_seconds',
            ['query']
        );
    }

    public function ask(object $query): mixed
    {
        $queryClass = $query::class;
        $start = microtime(true);

        try {
            if (!isset($this->handlers[$queryClass])) {
                throw new RuntimeException("No handler registered for query {$queryClass}");
            }

            $handler = $this->container->make($this->handlers[$queryClass]);

            if (!is_callable($handler)) {
                throw new RuntimeException("Handler {$this->handlers[$queryClass]} is not invokable.");
            }

            $result = $handler($query);

            $this->queriesCounter->inc(1, [
                'query'   => $queryClass,
                'success' => 'true',
            ]);

            return $result;
        } catch (\Throwable $e) {
            $this->queriesCounter->inc(1, [
                'query'   => $queryClass,
                'success' => 'false',
            ]);

            $this->queriesFailedCounter->inc(1, [
                'query' => $queryClass,
            ]);

            throw $e;
        } finally {
            $duration = microtime(true) - $start;

            $this->queryDurationHistogram->observe($duration, [
                'query' => $queryClass,
            ]);
        }
    }

    public function map(array $map): void
    {
        $this->handlers = array_merge($this->handlers, $map);
    }
}
