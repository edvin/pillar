<?php

namespace Pillar\Bus;

use Illuminate\Contracts\Bus\Dispatcher;
use Pillar\Logging\PillarLogger;
use Pillar\Metrics\Metrics;
use Pillar\Metrics\Counter;
use Pillar\Metrics\Histogram;
use Pillar\Event\EventContext;
use Throwable;

class LaravelCommandBus implements CommandBusInterface
{
    private Counter $commandsCounter;
    private Counter $commandsFailedCounter;
    private Histogram $commandDurationHistogram;

    public function __construct(
        private readonly PillarLogger $logger,
        private readonly Dispatcher   $dispatcher,
        Metrics                       $metrics
    )
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
        $this->logger->debug('pillar.command.started', [
            'command' => $commandClass,
        ]);
        $start = microtime(true);

        EventContext::initialize();

        try {
            $result = $this->dispatcher->dispatchSync($command);

            $this->commandsCounter->inc(1, [
                'command' => $commandClass,
                'success' => 'true',
            ]);

            $this->logger->debug('pillar.command.completed', [
                'command' => $commandClass,
            ]);

            return $result;
        } catch (Throwable $e) {
            $this->commandsCounter->inc(1, [
                'command' => $commandClass,
                'success' => 'false',
            ]);

            $this->commandsFailedCounter->inc(1, [
                'command' => $commandClass,
            ]);

            $this->logger->error('pillar.command.failed', [
                'command' => $commandClass,
                'exception' => $e,
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
