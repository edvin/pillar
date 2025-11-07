<?php

/** @noinspection PhpClassNamingConventionInspection */
/** @noinspection PhpIllegalPsrClassPathInspection */

use Pillar\Context\EventMapBuilder;

class _L1 {}
class _L2 {}
class _U1 {}
class _U2 {}

it('normalizes single listener string to an array', function () {
    $map = EventMapBuilder::create()
        ->event('E1')
        ->listeners(_L1::class)
        ->getListeners();

    expect($map)->toHaveKey('E1')
        ->and($map['E1'])->toBe([_L1::class]);
});

it('keeps array of listeners as-is', function () {
    $map = EventMapBuilder::create()
        ->event('E2')
        ->listeners([_L1::class, _L2::class])
        ->getListeners();

    expect($map['E2'])->toBe([_L1::class, _L2::class]);
});

it('normalizes single upcaster string to an array', function () {
    $map = EventMapBuilder::create()
        ->event('E3')
        ->upcasters(_U1::class)
        ->getUpcasters();

    expect($map)->toHaveKey('E3')
        ->and($map['E3'])->toBe([_U1::class]);
});

it('keeps array of upcasters as-is', function () {
    $map = EventMapBuilder::create()
        ->event('E4')
        ->upcasters([_U1::class, _U2::class])
        ->getUpcasters();

    expect($map['E4'])->toBe([_U1::class, _U2::class]);
});

it('builds alias map correctly', function () {
    $aliases = EventMapBuilder::create()
        ->event('My\\Domain\\EventCreated')
        ->alias('event.created')
        ->getAliases();

    expect($aliases)->toBe([
        'event.created' => 'My\\Domain\\EventCreated',
    ]);
});