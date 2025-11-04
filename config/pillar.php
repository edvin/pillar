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
    |   \Context\Document\Domain\Aggregate\Document::class => \App\Infrastructure\Repositories\MyAggregateDatabaseRepository::class,
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
    */
    'event_store' => [
        'class' => \Pillar\Event\DatabaseEventStore::class,
        'options' => [
            'table' => 'events', // The table name used by DatabaseEventStore
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
        'store' => \Pillar\Snapshot\CacheSnapshotStore::class,
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
        'command' => \Pillar\Bus\LaravelCommandBus::class,
        'query' => \Pillar\Bus\InMemoryQueryBus::class,
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