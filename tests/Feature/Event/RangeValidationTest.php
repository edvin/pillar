<?php

use Pillar\Event\EventReplayer;

it('rejects fromSequence greater than toSequence', function () {
    /** @var EventReplayer $replayer */
    $replayer = app(EventReplayer::class);

    expect(fn () => $replayer->replay(
        fromSequence: 10,
        toSequence: 5
    ))->toThrow(InvalidArgumentException::class);
});

it('rejects fromDate later than toDate', function () {
    /** @var EventReplayer $replayer */
    $replayer = app(EventReplayer::class);

    expect(fn () => $replayer->replay(
        fromDate: '2025-02-01T10:00:00Z',
        toDate: '2025-01-01T10:00:00Z'
    ))->toThrow(InvalidArgumentException::class);
});