## Event Store

Responsible for persisting and reading domain events. The default implementation is
a stream-centric, database-backed store. The **default fetch strategy** is configured
under `fetch_strategies.default` (defaults to `db_chunked`).

```php
'event_store' => [
    'class' => \Pillar\Event\DatabaseEventStore::class,
    'options' => [
        // Optimistic concurrency control for appends. When true, repositories
        // will pass the aggregate's current version as expected_sequence to the
        // EventStore. When false, no expected check is performed.
        'optimistic_locking' => true,

        'tables' => [
            // Primary event stream table.
            //
            // Expected columns (for the default DatabaseEventStore):
            //   - sequence         BIGINT PK, global, monotonically increasing
            //   - stream_id        string, logical stream name (e.g. "document-<uuid>")
            //   - stream_sequence  BIGINT, per-stream version (1,2,3,...) for each stream_id
            //   - event_type       string, FQCN or alias
            //   - event_version    int, schema version for upcasters
            //   - event_data       text/json/blob payload (serializer-controlled)
            //   - occurred_at      datetime (UTC recommended)
            //   - correlation_id   nullable string for tracing
            //
            // You can rename the table here if you customise the migration, e.g.:
            //   'events' => 'pillar_events',
            'events' => 'events',
        ],
    ],
],
```

---

## Event publication policy

Controls which recorded domain events are **published** (sent to the outbox and
event buses) versus kept private to the aggregate.

Semantics:

- All events you `record()` on an aggregate are **persisted** to its stream.
- Events that the publication policy marks as *publishable* are **also** enqueued
  to the transactional outbox in the same DB transaction and delivered with
  retries (at-least-once).
- Events not marked publishable remain private: they are stored for rehydration
  only and are **not** published to handlers / projections.

By default, Pillar publishes events that implement `Pillar\Event\ShouldPublish`.

```php
'publication_policy' => [
    'class' => \Pillar\Event\DefaultPublicationPolicy::class,
],
```

---

## Repositories

Repositories control how aggregates are loaded and saved.  
By default, all aggregates use the event‑sourced repository.

```php
'repositories' => [
    // Default repository used for any aggregate not explicitly overridden.
    'default' => \Pillar\Repository\EventStoreRepository::class,

    // Override per aggregate if needed:
    // App\Domain\Report\Report::class => App\Infrastructure\ReportRepository::class,
],
```

Notes:

- All repositories must implement `Pillar\Repository\AggregateRepository`.
- The default `EventStoreRepository` now uses **stream‑centric reads**, accepts
  `EventWindow` constraints, and supports **optimistic locking** when enabled in
  `event_store.options.optimistic_locking`.
- Snapshotting is applied automatically based on your configured `snapshot.policy`.

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
/*
|--------------------------------------------------------------------------
| 💾 Snapshots
|--------------------------------------------------------------------------
|
| Snapshots are used to rehydrate aggregates quickly without replaying
| the full event stream. By default, snapshots are cached using Laravel’s
| cache system, but you can replace this with a database or file-backed
| implementation if desired.
|
*/
'snapshot' => [
    'store' => [
        //'class' => \Pillar\Snapshot\CacheSnapshotStore::class,
        'class' => \Pillar\Snapshot\DatabaseSnapshotStore::class,
        'options' => [
            'table' => 'snapshots',
        ]
    ],

    /*
    |--------------------------------------------------------------------------
    | 🚚 Snapshot dispatch mode
    |--------------------------------------------------------------------------
    |
    | Controls how Pillar persists snapshots once the surrounding DB
    | transaction has committed:
    |
    |   'inline' → Persist snapshots in the same PHP process after commit.
    |   'queued' → Dispatch a lightweight job; a queue worker persists
    |              the snapshot out-of-band.
    |
    | In both cases, snapshotting is **never** part of the main write
    | transaction – failures here do not roll back your events.
    |
    */
    'mode' => 'inline', // 'inline' or 'queued'

    /*
    |--------------------------------------------------------------------------
    | 📥 Queue routing (queued mode)
    |--------------------------------------------------------------------------
    |
    | These settings are only used when 'mode' is set to 'queued'.
    |
    |   PILLAR_SNAPSHOT_QUEUE_CONNECTION
    |       → Which Laravel queue connection to use.
    |         Defaults to your global QUEUE_CONNECTION.
    |
    |   PILLAR_SNAPSHOT_QUEUE
    |       → Queue name where snapshot jobs are pushed.
    |
    | Use this to isolate snapshot traffic onto a dedicated queue/connection
    | if you want to keep it away from latency-sensitive work.
    |
    */
    'queue' => env('PILLAR_SNAPSHOT_QUEUE', 'default'),
    'connection' => env(
        'PILLAR_SNAPSHOT_QUEUE_CONNECTION',
        env('QUEUE_CONNECTION', 'database'),
    ),

    'ttl' => null, // Time-to-live in seconds (null = indefinitely)

    // Global default policy
    'policy' => [
        'class' => \Pillar\Snapshot\AlwaysSnapshotPolicy::class,
        'options' => [],
    ],

    // Per-aggregate overrides
    'overrides' => [
        // \App\Domain\Foo\FooAggregate::class => [
        //     'class' => \Pillar\Snapshot\EveryNEvents::class,
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

## 📬 Outbox (Transactional event publishing) {#outbox}

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
    | If you change this value, please run `php artisan pillar:outbox:partitions:sync`
    */
    'partition_count' => 16,

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
        'idle_backoff_ms' => 1000,
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
    | - The default bucket label format is "p%02d".
    | - Changing the partitioner or `partition_count` in production reshuffles
    |   load distribution, but does not affect historical data.
    */
    'partitioner' => [
        'class' => \Pillar\Outbox\Crc32Partitioner::class,
        // Label format: %02d = 2-digit bucket number, 00-99
        'label_format' => 'p%02d'
    ],
],
```

**Related concepts**

- Mark events to publish via the **`Pillar\Event\ShouldPublish`** interface.
- Mark events to publish **within the same transaction** via **`Pillar\Event\ShouldPublishInline`** (for synchronous
  projections).
- During **replay**, publishing is suppressed; projectors receive events directly from the store.
