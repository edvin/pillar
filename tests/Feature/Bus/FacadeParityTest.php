<?php

use Pillar\Bus\CommandBusInterface;
use Pillar\Bus\QueryBusInterface;
use Pillar\Facade\CommandBus;
use Pillar\Facade\Pillar;
use Pillar\Facade\QueryBus;
use Tests\Fixtures\Bus\EchoHandler;
use Tests\Fixtures\Bus\EchoQuery;
use Tests\Fixtures\Bus\PingCommand;
use Tests\Fixtures\Bus\PingHandler;

beforeEach(function () {
    // Reset side effects
    PingHandler::reset();

    // Map our test fixtures onto the buses (no ContextRegistry needed)
    app(CommandBusInterface::class)->map([
        PingCommand::class => PingHandler::class,
    ]);

    app(QueryBusInterface::class)->map([
        EchoQuery::class => EchoHandler::class,
    ]);
});

it('Pillar::dispatch forwards to the CommandBus handler (parity with CommandBus facade)', function () {
    // through Pillar facade
    Pillar::dispatch(new PingCommand('from-pillar'));

    // through CommandBus facade
    CommandBus::dispatch(new PingCommand('from-command-bus'));

    expect(PingHandler::$seen)->toBe(['from-pillar', 'from-command-bus']);
});

it('Pillar::ask forwards to the QueryBus handler (parity with QueryBus facade)', function () {
    $viaPillar = Pillar::ask(new EchoQuery('one'));
    $viaQueryBus = QueryBus::ask(new EchoQuery('two'));

    expect($viaPillar)->toBe('echo:one')
        ->and($viaQueryBus)->toBe('echo:two');
});