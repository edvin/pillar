<?php

use Illuminate\Bus\Dispatcher;
use Pillar\Bus\LaravelCommandBus;
use Pillar\Metrics\Metrics;
use Pillar\Metrics\NullMetrics;

class FailingCommand
{
}

it('increments failure metrics and rethrows when the dispatcher throws', function () {
    // Bind NullMetrics so metric calls are no-ops
    app()->instance(Metrics::class, new NullMetrics());

    // Dispatcher mock that always throws
    $dispatcher = Mockery::mock(Dispatcher::class);
    $dispatcher->shouldReceive('dispatchSync')
        ->once()
        ->with(Mockery::type(FailingCommand::class))
        ->andThrow(new RuntimeException('boom'));

    app()->instance(Dispatcher::class, $dispatcher);

    $bus = app(LaravelCommandBus::class);

    $command = new FailingCommand();

    expect(fn () => $bus->dispatch($command))
        ->toThrow(RuntimeException::class);
});