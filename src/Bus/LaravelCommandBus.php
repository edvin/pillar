<?php

namespace Pillar\Bus;

use Illuminate\Contracts\Bus\Dispatcher;
use Pillar\Metrics\Metrics;
use Pillar\Metrics\Counter;
use Pillar\Metrics\Histogram;
use Pillar\Event\EventContext;

class LaravelCommandBus implements CommandBusInterface
{
    private Counter $commandsCounter;
    private Counter $commandsFailedCounter;
    private Histogram $commandDurationHistogram;

    public function __construct(private Dispatcher $dispatcher, Metrics $metrics)
    {
        $this->commandsCounter = $metrics->counter(
            'commands_total',
            ['command', 'success']
        );

        $this->commandsFailedCounter = $metrics->counter(
            'commands_failed_total',
            ['command']
        );

        $this->commandDurationHistogram = $metrics->histogram(
            'command_duration_seconds',
            ['command']
        );
    }

    public function dispatch(object $command): mixed
    {
        $commandClass = $command::class;
        $start = microtime(true);

        EventContext::initialize();

        try {
            $result = $this->dispatcher->dispatchSync($command);

            $this->commandsCounter->inc(1, [
                'command' => $commandClass,
                'success' => 'true',
            ]);

            return $result;
        } catch (\Throwable $e) {
            $this->commandsCounter->inc(1, [
                'command' => $commandClass,
                'success' => 'false',
            ]);

            $this->commandsFailedCounter->inc(1, [
                'command' => $commandClass,
            ]);

            throw $e;
        } finally {
            $duration = microtime(true) - $start;

            $this->commandDurationHistogram->observe($duration, [
                'command' => $commandClass,
            ]);
        }
    }

    public function map(array $map): void
    {
        $this->dispatcher->map($map);
    }
}
