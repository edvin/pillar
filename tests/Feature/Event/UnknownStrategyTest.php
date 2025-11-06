<?php

namespace Tests\Feature\Event;

use Pillar\Event\Fetch\EventFetchStrategyResolver;
use Pillar\Event\Fetch\StrategyNotFoundException;

it('throws StrategyNotFoundException for unknown default strategy', function () {
    // Bad key on the *correct* path this resolver reads
    config()->set('pillar.fetch_strategies.default', 'nope_strategy');

    // Ensure we construct a *fresh* resolver with that config
    app()->forgetInstance(EventFetchStrategyResolver::class);

    // Trigger resolution (calls instantiate() under the hood)
    expect(fn() => app(EventFetchStrategyResolver::class)->resolve())
        ->toThrow(StrategyNotFoundException::class);
});