<?php

use Illuminate\Support\Str;
use Pillar\Event\Stream\DatabaseStreamResolver;
use Tests\Fixtures\Document\Document;
use Tests\Fixtures\Document\DocumentId;

it('resolves to the default table when no overrides and per-aggregate disabled', function () {
    config()->set('pillar.stream_resolver.options.default', 'events');
    config()->set('pillar.stream_resolver.options.per_aggregate_type', []);
    config()->set('pillar.stream_resolver.options.per_aggregate_id', false);
    config()->set('pillar.stream_resolver.options.per_aggregate_id_format', 'default_id');

    app()->forgetInstance(\Pillar\Event\Stream\StreamResolver::class);
    app()->forgetInstance(DatabaseStreamResolver::class);

    // null aggregate → default table
    $resolver = app(DatabaseStreamResolver::class);
    expect($resolver->resolve(null))->toBe('events');

    // any aggregate → still default
    $id = DocumentId::new();
    $resolver = app(DatabaseStreamResolver::class);
    expect($resolver->resolve($id))->toBe('events');
});

it('respects per-type overrides', function () {
    config()->set('pillar.stream_resolver.options.default', 'events');
    config()->set('pillar.stream_resolver.options.per_aggregate_type', [
        Document::class => 'doc_events',
    ]);
    config()->set('pillar.stream_resolver.options.per_aggregate_id', false);

    app()->forgetInstance(\Pillar\Event\Stream\StreamResolver::class);
    app()->forgetInstance(DatabaseStreamResolver::class);

    $resolver = app(DatabaseStreamResolver::class);

    $id = DocumentId::new();
    expect($resolver->resolve($id))->toBe('doc_events');
});

it('builds per-aggregate table names in default_id format', function () {
    config()->set('pillar.stream_resolver.options.default', 'events');
    config()->set('pillar.stream_resolver.options.per_aggregate_type', []);
    config()->set('pillar.stream_resolver.options.per_aggregate_id', true);
    config()->set('pillar.stream_resolver.options.per_aggregate_id_format', 'default_id');

    app()->forgetInstance(\Pillar\Event\Stream\StreamResolver::class);
    app()->forgetInstance(DatabaseStreamResolver::class);

    $resolver = app(DatabaseStreamResolver::class);

    $id = DocumentId::new();

    expect($resolver->resolve($id))->toBe('events_' . $id->value());
});

it('builds per-aggregate table names in type_id format (lowercased type)', function () {
    config()->set('pillar.stream_resolver.options.default', 'events');
    config()->set('pillar.stream_resolver.options.per_aggregate_type', []);
    config()->set('pillar.stream_resolver.options.per_aggregate_id', true);
    config()->set('pillar.stream_resolver.options.per_aggregate_id_format', 'type_id');

    app()->forgetInstance(\Pillar\Event\Stream\StreamResolver::class);
    app()->forgetInstance(DatabaseStreamResolver::class);

    $resolver = app(DatabaseStreamResolver::class);

    $uuid = Str::uuid()->toString();
    $id = DocumentId::from($uuid);

    // class_basename(Document::class) === 'Document' → lowercased 'document'
    expect($resolver->resolve($id))->toBe('document_' . $uuid);
});
