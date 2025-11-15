<?php

declare(strict_types=1);

use Pillar\Aggregate\AggregateRegistry;

it('rejects registering a non-AggregateRootId class', function () {
    $registry = new AggregateRegistry();

    expect(fn() => $registry->register(stdClass::class))
        ->toThrow(InvalidArgumentException::class, 'stdClass is not an AggregateRootId');
});

it('throws when resolving a stream name with unknown prefix', function () {
    $registry = new AggregateRegistry();

    // no registrations; any prefix should be unknown
    expect(fn() => $registry->idFromStreamName('foo-1234'))
        ->toThrow(RuntimeException::class, 'Unknown aggregate prefix: foo');
});