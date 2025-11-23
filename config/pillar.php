<?php

return [

    /*
    |--------------------------------------------------------------------------
    | 🧩 Context Registries
    |--------------------------------------------------------------------------
    |
    | Each bounded context defines its own registry of commands, queries, upcasters,
    | and event listeners. ContextCore will automatically register them on boot.
    |
    | Example:
    |   \Context\Document\DocumentContextRegistry::class,
    |
    */
    'context_registries' => [
    ],

    /*
    |--------------------------------------------------------------------------
    | 📦 Repositories
    |--------------------------------------------------------------------------
    |
    | Define which repository implementation should be used for each aggregate
    | root. The default repository is event-sourced, but you can override this
    | per aggregate by mapping its class here.
    |
    | Any custom repository must implement:
    | Pillar\Domain\Repository\AggregateRepository
    |
    | Example:
    |   \Context\Document\Domain\Aggregate\Document::class => \App\Repositories\DocumentDatabaseRepository::class,
    |
    */
    'repositories' => [
        'default' => \Pillar\Repository\EventStoreRepository::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | 🗃️ Event Store
    |--------------------------------------------------------------------------
    |
    | The event store is responsible for persisting and retrieving domain events.
    | The default implementation stores events in your database using Eloquent’s
    | query builder, but any EventStore implementation can be swapped in.
    |
    | Example alternative: KurrentDBEventStore, KafkaEventStore, DynamoDbEventStore, etc..
    |
    | Default fetch strategy is configured under 'fetch_strategies.default'.
    |
    */
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
            ]
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | 📣 Event publication policy
    |--------------------------------------------------------------------------
    |
    | Controls how Pillar decides whether a recorded domain event should be
    | published (placed in the transactional outbox) or kept private to the
    | aggregate.
    |
    | Semantics
    | ---------
    | - All events you `record()` on an aggregate are persisted to its stream.
    | - Events that the PublicationPolicy marks as publishable are **also**
    |   enqueued to the outbox in the **same DB transaction** and delivered to
    |   your bus with retries (at-least-once).
    | - Events not marked publishable remain private: persisted for rehydration
    |   only, **not** published to handlers/projections.
    |
    | The default policy publishes events that implement `Pillar\Event\ShouldPublish`.
    | Swap the class to customize the signal (e.g., support a `#[Publish]` attribute
    | or different rules per context).
    |
    */
    'publication_policy' => [
        'class' => \Pillar\Event\DefaultPublicationPolicy::class,
    ],

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

    /*
    |--------------------------------------------------------------------------
    | 🔍 Fetch Strategies
    |--------------------------------------------------------------------------
    |
    | Define different strategies for fetching events from the event store.
    | You can select a strategy globally (via 'default') or override it per
    | aggregate class in the 'overrides' section.
    |
    | Example override:
    |   'overrides' => [
    |       \Context\Document\Domain\Aggregate\Document::class => 'db_streaming',
    |   ],
    |
    | Each available strategy is listed under 'available' and may define
    | its own options, such as chunk size or cursor configuration.
    |
    | Additional backends (e.g. Kafka, S3) can be added in the future.
    |
    */
    'fetch_strategies' => [
        'default' => 'db_chunked',

        'overrides' => [
            // Aggregate-specific overrides go here.
        ],

        'available' => [
            'db_load_all' => [
                'class' => \Pillar\Event\Fetch\Database\DatabaseLoadAllStrategy::class,
                'options' => [],
            ],
            'db_chunked' => [
                'class' => \Pillar\Event\Fetch\Database\DatabaseChunkedFetchStrategy::class,
                'options' => [
                    'chunk_size' => 1000,
                ],
            ],
            'db_streaming' => [
                'class' => \Pillar\Event\Fetch\Database\DatabaseCursorFetchStrategy::class,
                'options' => [],
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | 🧠 Serializer
    |--------------------------------------------------------------------------
    |
    | Base serializer converts domain events to/from a wire string. Pillar will
    | always resolve this class and then (optionally) wrap it with an encryption
    | layer based on the policy below.
    |
    | Notes
    | - Encryption affects the *payload* only; event metadata remains plaintext.
    | - You can swap the base serializer (e.g. MessagePack/Proto) by setting 'class'.
    | - Enabling encryption does not rewrite old rows; encrypted and plaintext
    | events can coexist. Reads are seamless.
    |
    */
    'serializer' => [
        // The base serializer
        // Built-in alternatives:
        // - \Pillar\Serialization\MessagePackObjectSerializer::class
        // - \Pillar\Serialization\JsonObjectSerializer::class
        'class' => \Pillar\Serialization\JsonObjectSerializer::class,

        'encryption' => [
            // Global on/off switch for the encrypting wrapper
            'enabled' => env('PILLAR_PAYLOAD_ENCRYPTION', false),

            // Policy: if no per-event override exists, use this default.
            // true  -> encrypt all events by default
            // false -> encrypt none by default (only those in event_overrides => true)
            'default' => false,

            // Per-event overrides (class-string => bool). Highest precedence.
            // Examples:
            // \Context\Billing\Domain\Event\PaymentFailed::class => true,
            // \Context\Audit\Domain\Event\Redacted::class       => false,
            'event_overrides' => [
                // \Context\Billing\Domain\Event\PaymentFailed::class => true,
            ],

            // Pluggable cipher (implements Pillar\Security\PayloadCipher)
            'cipher' => [
                'class' => Pillar\Security\LaravelPayloadCipher::class,

                // Options for the cipher. For LaravelPayloadCipher:
                // - kid: key identifier label (useful for key rotation markers)
                // - alg: tag string; informational
                // LaravelPayloadCipher uses Laravel’s Encrypter (APP_KEY) under the hood.
                'options' => [
                    'kid' => env('PILLAR_PAYLOAD_KID', 'v1'),
                    'alg' => 'laravel-crypt',
                ],
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | 🚏 Command & Query Buses
    |--------------------------------------------------------------------------
    |
    | These buses route your commands and queries to their registered handlers.
    | You can replace the implementations with your own if you want to integrate
    | a message queue, pipeline, or async dispatching.
    |
    */
    'buses' => [
        'command' => [
            'class' => \Pillar\Bus\LaravelCommandBus::class
        ],
        'query' => [
            'class' => \Pillar\Bus\InMemoryQueryBus::class
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | 🛠️ Make: Scaffolding (pillar:make:context / pillar:make:command / pillar:make:query / pillar:make:event)
    |--------------------------------------------------------------------------
    |
    | Configure where the CLI scaffolding places Commands/Queries and their
    | Handlers, and how it registers them into your Context Registries.
    |
    | Defaults (Laravel-friendly):
    | - We assume each bounded context lives directly under App\<ContextName> in app/.
    |   e.g. App\DocumentHandling, App\Billing, App\Publishing
    |
    | You can tailor placement per ContextRegistry (by FQCN or by its name() string)
    | without introducing another “contexts” concept—use the 'overrides' section.
    |
    | Styles:
    |   - infer       : (future) infer from existing registrations; falls back to mirrored
    |   - mirrored    : Application/{Command,Query} + Application/Handler/{Command,Query}
    |   - split       : Application/{Command,Query} + Application/Handler
    |   - subcontext  : <Subcontext>/Application/{...} (when you pass --subcontext)
    |
    | Example override (by registry FQCN) to use a separate Context\ root:
    |   \App\Contexts\Documents\DocumentsContextRegistry::class => [
    |       'base_path'      => base_path('src/Context'),
    |       'base_namespace' => 'Context',
    |       'style'          => 'mirrored',   // infer|mirrored|split|subcontext
    |       'subcontext'     => null,
    |   ],
    |
    */
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
        |   App\<ContextName>\Application\...
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
        | 🏛️ Domain scaffolding defaults (shared)
        |--------------------------------------------------------------------------
        |
        | Common domain placement used by multiple makers (aggregate, event, etc.).
        | Paths are **relative to the context root** resolved by your
        | ContextRegistry + PlacementResolver (e.g. App\Billing → app/Billing).
        |
        */
        'domain_defaults' => [
            // Base folder (under the context) that represents your domain layer.
            'domain_dir' => 'Domain',
        ],

        /*
        |--------------------------------------------------------------------------
        | 🧱 Aggregate scaffolding defaults
        |--------------------------------------------------------------------------
        |
        | Used by `pillar:make:aggregate` when deciding where to place the new
        | Aggregate class and its Id class within a selected bounded context.
        |
        | All paths below are **relative to the context root** resolved by your
        | ContextRegistry + PlacementResolver (e.g. App\Billing maps to app/Billing).
        |
        | - aggregate_dir : Subfolder for the Aggregate root class.
        | - id_dir        : Subfolder for the Aggregate Id class. Defaults to the
        |                   same folder as the aggregate; change if you prefer
        |                   an explicit "Identity" or "Ids" folder.
        |
        | These defaults can be overridden per invocation using:
        |   --dir="Domain/Core/Aggregate"   and/or   --id-dir="Domain/Core/Identity"
        |
        */
        'aggregate_defaults' => [
            // Where the Aggregate root will be written, relative to the context.
            'aggregate_dir' => 'Domain/Aggregate',

            // Where the Aggregate Id will be written, relative to the context.
            // Tip: set to 'Domain/Identity' if you separate IDs.
            'id_dir' => 'Domain/Aggregate',
        ],

        /*
        |--------------------------------------------------------------------------
        | 📣 Event scaffolding defaults
        |--------------------------------------------------------------------------
        |
        | Used by `pillar:make:event` when deciding where to place new Domain
        | Event classes within a selected bounded context.
        |
        | All paths below are **relative to the context root**. The base
        | domain folder is taken from `domain_defaults.domain_dir` above.
        |
        */
        'event_defaults' => [
            // Where Domain Event classes will be written, relative to the context.
            'event_dir' => 'Domain/Event',
        ],

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
        | \App\Contexts\Documents\DocumentsContextRegistry::class => [
        |     'base_path'      => base_path('src/Context'),
        |     'base_namespace' => 'Context',
        |     'style'          => 'colocate',   // infer|mirrored|split|subcontext|colocate
        |     'subcontext'     => null,
        | ],
        |
        | // Or override by the registry name() string
        | 'Documents' => [
        |     'base_path'      => base_path('app'),       // still under app/
        |     'base_namespace' => 'App',                  // still under App\
        |     'style'          => 'split',
        | ],
        */
        'overrides' => [
            // ...
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | 📊 Pillar UI
    |--------------------------------------------------------------------------
    | Controls the built-in event explorer / timeline UI.
    | Outside the environments listed in `skip_auth_in`, access requires an
    | authenticated user that implements Pillar\Security\PillarUser and returns
    | true from canAccessPillar().
    */
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
        'page_size' => 100,
        'recent_limit' => 20,
    ],

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

    /*
    |--------------------------------------------------------------------------
    | 📊 Metrics
    |--------------------------------------------------------------------------
    |
    | Pillar can emit Prometheus-style metrics for key operations such as
    | commands, queries, event store activity, the transactional outbox,
    | workers, and replays.
    |
    | Metrics are optional and safe to enable in all environments:
    | - driver = 'none'       : metrics are disabled (all calls are no-ops).
    | - driver = 'prometheus' : metrics are emitted using
    |                           promphp/prometheus_client_php.
    |
    | When using the 'prometheus' driver, you must choose a storage backend:
    |
    |   in_memory
    |   ---------
    |   - Stores metrics in PHP memory for the current process only.
    |   - Suitable for tests and single long-running CLI workers.
    |   - Under PHP-FPM, each worker has its own isolated metrics which are
    |     reset when the worker restarts.
    |   - Not recommended for production, because scrapes only see the worker
    |     handling the request.
    |
    |   redis
    |   -----
    |   - Stores metrics in a shared Redis instance.
    |   - Recommended for multi-process production setups (PHP-FPM +
    |     queue workers + outbox workers).
    |   - All processes share the same aggregated metrics view.
    |
    */
    'metrics' => [
        // 'none'       -> metrics disabled (NullMetrics)
        // 'prometheus' -> Prometheus metrics via promphp/prometheus_client_php
        'driver' => env('PILLAR_METRICS_DRIVER', 'none'),

        'prometheus' => [
            // Namespace/prefix applied to all metric names emitted by Pillar
            'namespace' => env('PILLAR_METRICS_NAMESPACE', 'pillar'),

            // Default labels applied to all metrics emitted by Pillar
            'default_labels' => [
                'app' => env('APP_NAME', 'pillar-app'),
                'env' => env('APP_ENV', 'local'),
            ],

            'storage' => [
                // 'in_memory' -> per-process metrics, good for tests / local dev
                // 'redis'     -> shared metrics across processes (recommended for production)
                'driver' => env('PILLAR_METRICS_STORAGE_DRIVER', 'in_memory'),

                'redis' => [
                    'host' => env('PILLAR_METRICS_REDIS_HOST', env('REDIS_HOST', '127.0.0.1')),
                    'port' => env('PILLAR_METRICS_REDIS_PORT', env('REDIS_PORT', 6379)),
                    'timeout' => env('PILLAR_METRICS_REDIS_TIMEOUT', 0.1),
                    'auth' => env('PILLAR_METRICS_REDIS_AUTH', env('REDIS_PASSWORD')),
                    'database' => env('PILLAR_METRICS_REDIS_DB', env('REDIS_DB', 0)),
                ],
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | 🧾 Pillar internal logging
    |--------------------------------------------------------------------------
    |
    | Pillar uses its own PSR-3 compatible logger wrapper (PillarLogger) for all
    | internal log messages (event store, outbox, replay, metrics, etc.). Logs
    | are emitted using a dedicated Laravel logging channel so you can route
    | them separately from your main application logs if desired.
    |
    | enabled : Turn Pillar's internal logging on/off globally.
    | channel : Which Laravel logging channel to send Pillar logs to.
    |           Defaults to LOG_CHANNEL (typically "stack").
    | level   : Minimum log level for Pillar logs. This usually mirrors your
    |           application's LOG_LEVEL, but you can override it for more or
    |           less verbosity just for Pillar.
    */
    'logging' => [
        'enabled' => true,

        // Use a dedicated channel for Pillar logs, or fall back to the main
        // application channel (LOG_CHANNEL). Example: 'single', 'daily', 'stderr'.
        'channel' => env('PILLAR_LOG_CHANNEL', env('LOG_CHANNEL', 'stack')),

        // Minimum log level for Pillar logs. Common values: 'debug', 'info',
        // 'notice', 'warning', 'error'. This is passed through to Laravel's
        // logging stack; Laravel handles the actual filtering by level.
        'level' => env('PILLAR_LOG_LEVEL', env('LOG_LEVEL', 'info')),
    ],

    /*
    |--------------------------------------------------------------------------
    | ❤️ Pillar health check
    |--------------------------------------------------------------------------
    |
    | Lightweight JSON health endpoint for Pillar internals. This is intended for
    | readiness/liveness checks (Kubernetes probes, load balancers, uptime checks).
    |
    | enabled : Master switch. If false, the health route is not registered.
    | path    : Absolute path where the health endpoint is mounted. This should
    |           start with a leading slash. Default: "/pillar/health".
    |
    */
    'health' => [
        'enabled' => env('PILLAR_HEALTH_ENABLED', true),

        // Absolute path for the health endpoint. Example: "/pillar/health".
        // You can change this to anything that fits your app:
        //   PILLAR_HEALTH_PATH=/health/pillar
        'path' => env('PILLAR_HEALTH_PATH', '/pillar/health'),
    ],

];