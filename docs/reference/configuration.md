## Event Store

Responsible for persisting and reading domain events. The default implementation is database‑backed. **Note:** the
default fetch strategy is not configured here—see **`fetch_strategies.default`** (defaults to `'db_chunked'`).

```php
'event_store' => [
    'class' => Pillar\Event\DatabaseEventStore::class,
    'options' => [
        // Optimistic concurrency control for appends. When true, repositories
        // pass the aggregate's current version as expected_sequence to the EventStore.
        'optimistic_locking' => true,
    ],
],
```

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

Configure where the CLI scaffolding places Commands/Queries and their Handlers, and how it registers them into your
Context Registries.

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

---

## 📊 Pillar UI (Stream Browser)

Controls the built‑in event explorer / timeline UI. Outside the environments in `skip_auth_in`, access requires an
authenticated user implementing `Pillar\Security\PillarUser` and returning `true` from `canAccessPillar()`.

```php
'ui' => [
    /*
    |--------------------------------------------------------------------------
    | Master switch
    |--------------------------------------------------------------------------
    | If false, the UI is not mounted (routes/views aren’t registered).
    */
    'enabled' => env('PILLAR_UI', true),

    /*
    |--------------------------------------------------------------------------
    | 🔓 Skip auth in these environments
    |--------------------------------------------------------------------------
    | Accepts a comma-separated string or an array. In these environments BOTH
    | authentication and PillarUser checks are bypassed (handy for local dev).
    |
    | .env example:
    |   PILLAR_UI_SKIP_AUTH_IN=local,testing
    */
    'skip_auth_in' => env('PILLAR_UI_SKIP_AUTH_IN', 'local'),

    /*
    |--------------------------------------------------------------------------
    | 🛡️ Auth guard used for access checks
    |--------------------------------------------------------------------------
    | Which guard to use to resolve the current user when the UI is protected.
    | Examples: "web" (session), "sanctum", or "api" (token).
    */
    'guard' => env('PILLAR_UI_GUARD', 'web'),

    /*
    |--------------------------------------------------------------------------
    | 🔗 Mount path
    |--------------------------------------------------------------------------
    | Base path where the UI is served. Do NOT include a leading slash.
    | The UI will be reachable at "/{path}" (e.g. "/pillar").
    */
    'path' => env('PILLAR_UI_PATH', 'pillar'),

    /*
    |--------------------------------------------------------------------------
    | 📜 Pagination & lists
    |--------------------------------------------------------------------------
    | page_size:     events per API page (server may cap this)
    | recent_limit:  how many “recent aggregates” to show on the landing page
    */
    'page_size'   => 100,
    'recent_limit'=> 20,
],
```

**Env shortcuts**

- `PILLAR_UI=true|false` – enable/disable mounting the UI
- `PILLAR_UI_SKIP_AUTH_IN=local,testing` – bypass auth/trait checks in these environments
- `PILLAR_UI_GUARD=web|sanctum|api` – guard used when UI is protected
- `PILLAR_UI_PATH=pillar` – base path (UI served at `/{path}`)

---

## 📬 Outbox (Transactional event publishing) {: #outbox }

Persist publishable domain events **in the same DB transaction** and let a background worker deliver them reliably (
at‑least‑once) with retries. Partitioning allows multiple workers to share the load while preserving per‑partition
ordering.

```php
'outbox' => [
    /*
    |--------------------------------------------------------------------------
    | 📬 Transactional Outbox
    |--------------------------------------------------------------------------
    | Persist publishable events and enqueue them in the SAME DB transaction.
    | A background worker claims rows and delivers them with retries
    | (at-least-once). Partitioning lets multiple workers share the load.
    */

    /*
    |--------------------------------------------------------------------------
    | 🧩 Partitioning
    |--------------------------------------------------------------------------
    | How many logical partitions (buckets) the outbox is sharded into.
    | Each partition is processed by at most one worker at a time.
    | Tip: keep this a power of two for easy scaling.
    */
    'partition_count' => 64,

    /*
    |--------------------------------------------------------------------------
    | 👷 Worker runtime
    |--------------------------------------------------------------------------
    | Runtime knobs for the outbox worker loop. Times are seconds unless noted.
    |
    | • leasing         : set to false for single-worker, no partition leasing
    | • lease_ttl       : how long a partition lease is valid
    | • lease_renew     : how often a worker renews its leases
    | • heartbeat_ttl   : how long a worker stays “active” in the registry
    | • batch_size      : events to claim per polling cycle (fairly split per
    |                      owned partition at the call site)
    | • idle_backoff_ms : sleep between polls when nothing was processed (ms)
    | • claim_ttl       : short claim lease per row during processing
    | • retry_backoff   : delay before retrying a failed publish
    |
    |   If you run multiple workers with leasing disabled, they’ll safely avoid dupes, but
    |   you’ll lose per-partition ordering guarantees since workers can interleave claims.
    */
    'worker' => [
        'leasing' => true,
        'lease_ttl' => 15,
        'lease_renew' => 6,
        'heartbeat_ttl' => 20,
        'batch_size' => 100,
        'idle_backoff_ms' => 200,
        'claim_ttl' => 15,
        'retry_backoff' => 60,
    ],

    /*
    |--------------------------------------------------------------------------
    | 🗄️ Table names
    |--------------------------------------------------------------------------
    | Customize table names if you need to. These should match your migrations.
    */
    'tables' => [
        'outbox' => 'outbox',
        'partitions' => 'outbox_partitions',
        'workers' => 'outbox_workers',
    ],

    /*
    |--------------------------------------------------------------------------
    | 🧮 Partitioner strategy
    |--------------------------------------------------------------------------
    | Controls how the outbox `partition_key` is computed for each event.
    |
    | Default: Crc32Partitioner
    | - Deterministically maps an aggregate id to a bucket string "pNN"
    |   where NN ∈ [00 .. partition_count-1].
    | - Reads the bucket count from: pillar.outbox.partition_count
    | - If `partition_count` <= 1, returns null (no partition key).
    |
    | Why partition?
    | - Each partition is processed by at most one worker at a time, giving
    |   ordering guarantees per partition and easy horizontal scale.
    |
    | Interface:
    |   Pillar\Outbox\Partitioner
    |     public function keyFor(string $aggregateId): ?string
    |
    | Swapping strategy:
    | - You can replace the class to route by tenant, context, etc.
    |   Example:
    |     'class' => \App\Outbox\TenantPartitioner::class,
    |
    | Notes:
    | - The default bucket label format is "p%02d". If you change formats,
    |   ensure your worker is configured to claim the same labels.
    | - Changing the partitioner or `partition_count` in production reshuffles
    |   load distribution, but does not affect historical data.
    */
    'partitioner' => [
        'class' => \Pillar\Outbox\Crc32Partitioner::class,
    ],
],
```

**Related concepts**

- Mark events to publish via the **`Pillar\Event\ShouldPublish`** interface.
- Mark events to publish **within the same transaction** via **`Pillar\Event\ShouldPublishInline`** (for synchronous
  projections).
- During **replay**, publishing is suppressed; projectors receive events directly from the store.
