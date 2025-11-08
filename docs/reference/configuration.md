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

## 🛠️ Make: Scaffolding

Configure where the CLI scaffolding places Commands/Queries and their Handlers, and how it registers them into your Context Registries.

```php
/*
|--------------------------------------------------------------------------
| 🛠️ Make: Scaffolding (pillar:make:command / pillar:make:query / pillar:make:context)
|--------------------------------------------------------------------------
|
| Configure where the CLI scaffolding places Commands/Queries and their
| Handlers, and how it registers them into your Context Registries.
|
| - 'contexts_base_path' and 'contexts_base_namespace' are the defaults for
|   all bounded contexts (derived from ContextRegistry::name()).
| - 'overrides' lets you tailor placement per ContextRegistry (by FQCN or by
|   its name() string) without introducing another “contexts” concept.
|
| Styles (PathStyle):
|   - 'colocate'   : Handler sits next to its Command/Query (default)
|   - 'mirrored'   : Application/Handler/{Command,Query}
|   - 'split'      : Application/Handler
|   - 'subcontext' : <Subcontext>/Application/{...} (when --subcontext is used)
|   - 'infer'      : (future) infer from existing registrations; falls back to 'colocate'
|
*/
'make' => [

    /*
    |--------------------------------------------------------------------------
    | 📁 Default base path for bounded contexts
    |--------------------------------------------------------------------------
    |
    | Where each bounded context lives on disk by default.
    | The final path becomes:
    |   contexts_base_path . '/' . ContextRegistry::name()
    |
    | With the default, that yields:
    |   app/<ContextName>/Application/...
    |
    */
    'contexts_base_path' => base_path('app'),

    /*
    |--------------------------------------------------------------------------
    | 🧭 Default base namespace for bounded contexts
    |--------------------------------------------------------------------------
    |
    | The root PHP namespace for contexts.
    | The final namespace becomes:
    |   contexts_base_namespace . '\\' . ContextRegistry::name()
    |
    | With the default, that yields:
    |   App\\<ContextName>\\Application\\...
    |
    */
    'contexts_base_namespace' => 'App',

    /*
    |--------------------------------------------------------------------------
    | 🗂️ Default placement style for generated files
    |--------------------------------------------------------------------------
    |
    | Controls where Handlers are placed relative to their Commands/Queries.
    | Accepts one of the PathStyle enum values (as strings):
    |   - 'colocate'   : Handler sits next to its Command/Query
    |   - 'mirrored'   : Application/Handler/{Command,Query}
    |   - 'split'      : Application/Handler
    |   - 'subcontext' : <Subcontext>/Application/{...} (when --subcontext is used)
    |   - 'infer'      : (future) infer from existing registrations; falls back to 'colocate'
    |
    | Tip: keep these as strings in config; code resolves with:
    |   PathStyle::tryFrom(config('pillar.make.default_style') ?? 'colocate')
    |
    */
    'default_style' => 'colocate',

    /*
    |--------------------------------------------------------------------------
    | 🎛️ Per-registry overrides
    |--------------------------------------------------------------------------
    |
    | Fine-tune placement per specific ContextRegistry (FQCN preferred) or by
    | the registry’s human name() string. Keys below are optional; anything
    | omitted falls back to the defaults above.
    |
    */
    'overrides' => [
        // \App\DocumentHandling\DocumentContextRegistry::class => [
        //     'base_path'      => base_path('app/DocumentHandling'),
        //     'base_namespace' => 'App\\DocumentHandling',
        //     'style'          => 'colocate',   // infer|mirrored|split|subcontext|colocate
        //     'subcontext'     => null,
    ],
]
```
