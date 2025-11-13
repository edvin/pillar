<?php

namespace Tests\Feature\Outbox;

use Pillar\Outbox\Partitioner;

it('creates partition labels', function () {
    /** @var Partitioner $partitioner */
    $partitioner = app(Partitioner::class);

    expect($partitioner->labelForIndex(1))->toBe('p01')
        ->and($partitioner->labelForIndex(-1))->toBeNull();
});