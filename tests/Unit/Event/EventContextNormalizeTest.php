<?php

use Carbon\CarbonImmutable;
use Pillar\Event\EventContext;

it('initialize with CarbonImmutable normalizes via early return', function () {
    // Non-UTC input to ensure normalization happens
    $src = new CarbonImmutable('2025-01-02 03:04:05', 'Europe/Oslo');

    EventContext::initialize($src, 'C-early');
    $got = EventContext::occurredAt();

    expect($got)->toBeInstanceOf(CarbonImmutable::class)
        // Should be the same instant, normalized to UTC
        ->and($got->toIso8601String())->toBe($src->setTimezone('UTC')->toIso8601String())
        // Correlation id should be what we provided
        ->and(EventContext::correlationId())->toBe('C-early');

    EventContext::clear();
});