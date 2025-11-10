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
    | Example alternative: KafkaEventStore, DynamoDbEventStore, KurrentDBEventStore, etc..
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
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | 🧭 Stream Resolver
    |--------------------------------------------------------------------------
    |
    | Controls how the stream/table name is chosen for an aggregate's events.
    |
    | Default behavior (DatabaseStreamResolver):
    |   • All events go to the global "events" table/stream.
    |
    | Resolution order when resolving a stream:
    |   1) If the Aggregate ID is null  → use 'default'.
    |   2) If the aggregate class has an explicit mapping in 'per_aggregate_type'
    |      → use that mapping.
    |   3) If 'per_aggregate_id' is true → build a per-instance stream name using
    |      'per_aggregate_id_format'.
    |   4) Otherwise → fall back to 'default'.
    |
    | Notes:
    |   • 'per_aggregate_id_format' is only consulted when 'per_aggregate_id' is true.
    |   • In 'type_id' format, the "type" part is the PHP class base name
    |     (e.g. Document → "Document_123"). If you later enable lowercasing in the
    |     resolver, this would become "document_123".
    |   • If you use a database-backed store, make sure the corresponding tables
    |     exist for any custom names you declare here.
    |
    | To provide a different naming strategy, implement
    | Pillar\Event\Stream\StreamResolver and swap the 'class' below.
    */
    'stream_resolver' => [
        'class' => \Pillar\Event\Stream\DatabaseStreamResolver::class,
        'options' => [
            // Global fallback stream/table. Used when the Aggregate ID is null
            // or when no other rule matches.
            'default' => 'events',

            // Explicit per-type mapping (takes precedence over per_aggregate_id).
            // Example:
            //   \Context\Document\Domain\Aggregate\Document::class => 'document_events',
            'per_aggregate_type' => [
                // Aggregate-specific mappings go here.
            ],

            // If true (and no per-type mapping applies), generate a unique stream
            // per aggregate instance according to 'per_aggregate_id_format'.
            'per_aggregate_id' => false,

            // Format used when 'per_aggregate_id' is true. Allowed values:
            //   - 'default_id' → "{default}_{aggregateId}"  e.g. "events_123"
            //   - 'type_id'    → "{aggregateClassBaseName}_{aggregateId}" e.g. "document_123"

            // Aggregate IDs are inserted verbatim; if your backend has naming restrictions,
            // ensure your IDs (and chosen format) produce valid names.
            'per_aggregate_id_format' => 'default_id',
        ],
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
            'class' => \Pillar\Snapshot\CacheSnapshotStore::class,
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
    | 🛠️ Make: Scaffolding (pillar:make:command / pillar:make:query)
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

];