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
    | Example alternative: KafkaEventStore, DynamoDbEventStore, etc.
    |
    | The default fetch strategy is 'db.chunked'.
    |
    */
    'event_store' => [
        'class' => \Pillar\Event\DatabaseEventStore::class,
        'options' => [
            'table' => 'events',
            'default_fetch_strategy' => 'db.chunked',
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
    |       \Context\Document\Domain\Aggregate\Document::class => 'db.streaming',
    |   ],
    |
    | Each available strategy is listed under 'available' and may define
    | its own options, such as chunk size or cursor configuration.
    |
    | Additional backends (e.g. Kafka, S3) can be added in the future.
    |
    */
    'fetch_strategies' => [
        'default' => 'db.chunked',

        'overrides' => [
            // Aggregate-specific overrides go here.
        ],

        'available' => [
            'db.load_all' => [
                'class' => \Pillar\Event\Fetch\Database\DatabaseLoadAllStrategy::class,
                'options' => [],
            ],
            'db.chunked' => [
                'class' => \Pillar\Event\Fetch\Database\DatabaseChunkedFetchStrategy::class,
                'options' => [
                    'chunk_size' => 1000,
                ],
            ],
            'db.streaming' => [
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