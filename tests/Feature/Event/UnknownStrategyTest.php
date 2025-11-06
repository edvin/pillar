<?php

namespace Tests\Feature\Event;

use Pillar\Event\Fetch\EventFetchStrategyResolver;
use Pillar\Event\Fetch\StrategyNotFoundException;
use Tests\Fixtures\Document\Document;
use Tests\Fixtures\Document\DocumentId;

it('throws StrategyNotFoundException for unknown default strategy', function () {
    // Bad key on the *correct* path this resolver reads
    config()->set('pillar.fetch_strategies.default', 'nope_strategy');

    // Ensure we construct a *fresh* resolver with that config
    app()->forgetInstance(EventFetchStrategyResolver::class);

    // Trigger resolution (calls instantiate() under the hood)
    expect(fn() => app(EventFetchStrategyResolver::class)->resolve())
        ->toThrow(StrategyNotFoundException::class);
});

it('throws when per-aggregate override refers to unknown strategy', function () {
    // Keep a valid default, but break the per-aggregate override
    config()->set('pillar.fetch_strategies.default', 'db_load_all');
    config()->set('pillar.fetch_strategies.overrides', [
        Document::class => 'nope_strategy',
    ]);

    app()->forgetInstance(EventFetchStrategyResolver::class);

    expect(fn() => app(EventFetchStrategyResolver::class)->resolve(DocumentId::new()))
        ->toThrow(StrategyNotFoundException::class);
});