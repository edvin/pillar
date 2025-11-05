<?php

return [

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
    | The default fetch strategy is 'db_chunked'.
    |
    */
    'event_store' => [
        'class' => \Pillar\Event\DatabaseEventStore::class,
        'options' => [
            'default_fetch_strategy' => 'db_chunked',
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
    ],

    /*
    |--------------------------------------------------------------------------
    | 🧠 Serializer
    |--------------------------------------------------------------------------
    |
    | Handles conversion of domain events to and from storable payloads.
    | The default JSON serializer is simple and human-readable. You can
    | replace it with a binary serializer like MessagePack or Protobuf.
    |
    */
    'serializer' => [
        'class' => \Pillar\Serialization\JsonObjectSerializer::class,
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

];