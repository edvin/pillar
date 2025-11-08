
# ⚙️ Configuration

All options live in `config/pillar.php`. Below is a consolidated overview that mirrors the current defaults.

---

## Repositories

```php
'repositories' => [
    'default' => Pillar\Repository\EventStoreRepository::class,
    // Example per-aggregate override:
    // App\Domain\Report\Report::class => App\Infrastructure\ReportRepository::class,
],
```

---

## Event Store

```php
'event_store' => [
    'class' => Pillar\Event\DatabaseEventStore::class,
    'options' => [
        'default_fetch_strategy' => 'db_chunked',
        // When true, repositories pass expected_sequence to the EventStore (OCC).
        'optimistic_locking' => true,
    ],
],
```

---

## Stream resolver

```php
'stream_resolver' => [
    'class' => Pillar\Event\Stream\DatabaseStreamResolver::class,
    'options' => [
        // Global fallback stream/table.
        'default' => 'events',

        // Explicit per-type mapping (takes precedence over per_aggregate_id).
        // App\Domain\Document::class => 'document_events',
        'per_aggregate_type' => [],

        // If true (and no per-type mapping applies), generate a unique stream
        // per aggregate instance according to 'per_aggregate_id_format'.
        'per_aggregate_id' => false,

        // When per_aggregate_id is true:
        //  - 'default_id' -> "{default}_{aggregateId}"  e.g. "events_123"
        //  - 'type_id'    -> "{aggregateClassBaseName}_{aggregateId}" e.g. "Document_123"
        'per_aggregate_id_format' => 'default_id',
    ],
],
```

---

## Fetch strategies

```php
'fetch_strategies' => [
    'default'   => 'db_chunked',

    'overrides' => [
        // App\Domain\BigAggregate::class => 'db_streaming',
    ],

    'available' => [
        'db_load_all' => [
            'class'   => Pillar\Event\Fetch\Database\DatabaseLoadAllStrategy::class,
            'options' => [],
        ],
        'db_chunked' => [
            'class'   => Pillar\Event\Fetch\Database\DatabaseChunkedFetchStrategy::class,
            'options' => ['chunk_size' => 1000],
        ],
        'db_streaming' => [
            'class'   => Pillar\Event\Fetch\Database\DatabaseCursorFetchStrategy::class,
            'options' => [],
        ],
    ],
],
```

---

## Snapshotting

```php
'snapshot' => [
    'store' => [
        'class' => Pillar\Snapshot\CacheSnapshotStore::class,
    ],
    'ttl' => null, // seconds (null = indefinitely)

    // Global default policy
    'policy' => [
        'class' => Pillar\Snapshot\AlwaysSnapshotPolicy::class,
        'options' => [],
    ],

    // Per-aggregate overrides (same shape as 'policy')
    'overrides' => [
        // App\Aggregates\BigAggregate::class => [
        //     'class' => Pillar\Snapshot\EveryNEvents::class,
        //     'options' => ['threshold' => 50],
        // ],
    ],
],
```

---

## Serializer (with payload encryption)

```php
'serializer' => [
    // Base serializer (kept even when encryption is enabled)
    'class' => Pillar\Serialization\JsonObjectSerializer::class,

    'encryption' => [
        'enabled' => env('PILLAR_PAYLOAD_ENCRYPTION', false),

        // Policy: if no per-event override exists, use this default.
        // true  -> encrypt all events by default
        // false -> encrypt none by default (only those in event_overrides => true)
        'default' => false,

        // Per-event overrides (class-string => bool). Highest precedence.
        'event_overrides' => [
            // App\Domain\Billing\Event\PaymentFailed::class => true,
        ],

        // Pluggable cipher (implements Pillar\Security\PayloadCipher)
        'cipher' => [
            'class' => Pillar\Security\LaravelPayloadCipher::class,
            'options' => [
                'kid' => env('PILLAR_PAYLOAD_KID', 'v1'),
                'alg' => 'laravel-crypt',
            ],
        ],
    ],
],
```

---

## Buses

```php
'buses' => [
    'command' => ['class' => Pillar\Bus\LaravelCommandBus::class],
    'query'   => ['class' => Pillar\Bus\InMemoryQueryBus::class],
],
```

---

## Context registries

```php
'context_registries' => [
    // App\DocumentHandling\DocumentHandlingContextRegistry::class,
],
```

---

## 🛠️ Make: Scaffolding

Configure where the CLI scaffolding places Commands/Queries and their Handlers, and how it registers them into your Context Registries.

```php
'make' => [

    /*
    |--------------------------------------------------------------------------
    | 📁 Default base path for bounded contexts
    |--------------------------------------------------------------------------
    |
    | Where each bounded context lives on disk by default.
    | With the default settings below, files go under:
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
    | Examples:
    |
    | // Override by ContextRegistry FQCN (recommended)
    | App\Contexts\Documents\DocumentsContextRegistry::class => [
    |     'base_path'      => base_path('src/Context'),
    |     'base_namespace' => 'Context',
    |     'style'          => 'colocate',   // infer|mirrored|split|subcontext|colocate
    |     'subcontext'     => null,
    | ],
    |
    | // Or override by the registry name() string
    | 'Documents' => [
    |     'base_path'      => base_path('app'),
    |     'base_namespace' => 'App',
    |     'style'          => 'split',
    | ],
    */
    'overrides' => [
        // ...
    ],
];
```

