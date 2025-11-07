<?php

use Pillar\Event\Fetch\EventFetchStrategyResolver;
use Pillar\Event\Fetch\Database\DatabaseCursorFetchStrategy;
use Pillar\Event\Fetch\StrategyNotFoundException;
use Tests\Fixtures\Document\Document;
use Tests\Fixtures\Document\DocumentId;

it('uses per-aggregate override before default', function () {
    // default is chunked, but Document is overridden to streaming
    config()->set('pillar.fetch_strategies.default', 'db_chunked');
    config()->set('pillar.fetch_strategies.overrides', [
        Document::class => 'db_streaming',
    ]);

    // rebuild resolver with the new config
    app()->forgetInstance(EventFetchStrategyResolver::class);
    $resolver = app(EventFetchStrategyResolver::class);

    $strategy = $resolver->resolve(DocumentId::new());
    expect($strategy)->toBeInstanceOf(DatabaseCursorFetchStrategy::class);
});

it('throws when no default is configured and no per-aggregate override applies', function () {
    // Remove default and clear overrides so resolver has no name to use
    config()->set('pillar.fetch_strategies.default');
    config()->set('pillar.fetch_strategies.overrides', []);

    app()->forgetInstance(EventFetchStrategyResolver::class);
    $resolver = app(EventFetchStrategyResolver::class);

    expect(fn () => $resolver->resolve()) // null $id → no override; default is null
    ->toThrow(StrategyNotFoundException::class);

    // Restore default so other tests are unaffected
    config()->set('pillar.fetch_strategies.default', 'db_chunked');
    app()->forgetInstance(EventFetchStrategyResolver::class);
});