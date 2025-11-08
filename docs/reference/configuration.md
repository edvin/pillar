# ⚙️ Configuration

All options live in `config/pillar.php`. Below is a consolidated overview.

## Repositories

```php
'repositories' => [
    'default' => Pillar\\Repository\\EventStoreRepository::class,
    // Example override:
    // App\\Domain\\Report\\Report::class => App\\Infrastructure\\ReportRepository::class,
],
```

## Event Store

```php
'event_store' => [
    'class' => Pillar\\Event\\DatabaseEventStore::class,
    'options' => [
        'optimistic_locking' => true,
    ],
],
```

## Fetch strategies

```php
'fetch_strategies' => [
    'default' => 'db_chunked',
    'overrides' => [
        // App\\Domain\\BigAggregate::class => 'db_streaming',
    ],
    'available' => [
        'db_load_all' => [
            'class' => Pillar\\Event\\Fetch\\Database\\DatabaseLoadAllStrategy::class,
            'options' => [],
        ],
        'db_chunked' => [
            'class' => Pillar\\Event\\Fetch\\Database\\DatabaseChunkedFetchStrategy::class,
            'options' => ['chunk_size' => 1000],
        ],
        'db_streaming' => [
            'class' => Pillar\\Event\\Fetch\\Database\\DatabaseCursorFetchStrategy::class,
            'options' => [],
        ],
    ],
],
```

## Stream resolver

```php
'stream_resolver' => [
    'class' => Pillar\\Event\\Stream\\DatabaseStreamResolver::class,
    'options' => [
        'default' => 'events',
        'per_aggregate_type' => [
            // App\\Domain\\Document::class => 'document_events',
        ],
        'per_aggregate_id' => true,
        'per_aggregate_id_format' => 'type_id', // or 'default_id'
    ],
],
```

## Snapshotting

```php
'snapshot' => [
    'store' => ['class' => Pillar\\Snapshot\\CacheSnapshotStore::class],
    'ttl' => null, // seconds; null = keep indefinitely
    'policy' => [
        'default' => ['class' => Pillar\\Snapshot\\AlwaysSnapshotPolicy::class],
        'overrides' => [
            // App\\Aggregates\\BigAggregate::class => [
            //   'class' => Pillar\\Snapshot\\CadenceSnapshotPolicy::class,
            //   'options' => ['threshold' => 500, 'offset' => 0],
            // ],
        ],
    ],
],
```

## Serializer

```php
'serializer' => [
    'class' => Pillar\\Serialization\\JsonObjectSerializer::class,
    'options' => [],
],
```

## Context registries

```php
'context_registries' => [
    // App\\Context\\DocumentContextRegistry::class,
    // App\\Context\\UserContextRegistry::class,
],
```

> Tip: when you add or rename config keys, update this page to keep docs in sync.
