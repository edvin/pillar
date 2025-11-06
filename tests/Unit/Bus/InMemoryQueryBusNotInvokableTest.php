<?php

use Pillar\Bus\QueryBusInterface;
use Pillar\Facade\Pillar;
use Tests\Fixtures\Bus\NoHandlerQuery;
use Tests\Fixtures\Bus\NotInvokableHandler;
use Tests\Fixtures\Bus\NotInvokableQuery;

it('throws when the mapped query handler is not invokable', function () {
    // Arrange: map a query to a class without __invoke()
    app(QueryBusInterface::class)->map([
        NotInvokableQuery::class => NotInvokableHandler::class,
    ]);

    // Act/Assert: invoking should fail with the specific message
    expect(fn() => Pillar::ask(new NotInvokableQuery('x')))
        ->toThrow(RuntimeException::class, 'is not invokable');
});

it('throws when no handler is registered for a query', function () {
    // Unique query class not mapped anywhere → guarantees the branch is hit.
    expect(fn() => Pillar::ask(new NoHandlerQuery('x')))
        ->toThrow(RuntimeException::class, 'No handler registered for query');
});
