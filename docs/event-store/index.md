# 🗃️ Event Store

Pillar’s event store is a **pluggable abstraction** that supports streaming domain events efficiently using PHP generators.  
The default implementation, `DatabaseEventStore`, persists domain events in a database table — but you can replace it
with any backend (Kafka, DynamoDB, S3, …).

---

## Interface

```php
interface EventStore
{
    /**
     * Append an event and return the assigned per-aggregate version (aggregate_sequence).
     * If $expectedSequence is provided, the append only succeeds when the current
     * per-aggregate version matches; otherwise a ConcurrencyException is thrown.
     */
    public function append(AggregateRootId $id, object $event, ?int $expectedSequence = null): int;

    /**
     * Load events for a single aggregate, optionally bounded by an EventWindow.
     *
     * @return Generator<StoredEvent>
     */
    public function load(AggregateRootId $id, ?EventWindow $window = null): Generator;

    /** @return Generator<StoredEvent> */
    public function all(?AggregateRootId $aggregateId = null, ?string $eventType = null): Generator;
}
```

Instead of returning arrays, `load()` and `all()` yield `StoredEvent` instances as **generators** — allowing **true streaming** of large event streams with minimal memory use.

### Point‑in‑time & bounded reads (EventWindow)

Use `EventWindow` to cap a read at a specific boundary — by aggregate version, by global sequence, or by UTC time. This lets you **inspect history** or rebuild state _as of_ some moment.

```php
use Pillar\Event\EventWindow;

// Up to a specific per-aggregate version
$win = EventWindow::toAggSeq(42);
foreach ($eventStore->load($id, $win) as $e) {
    // events with aggregate_sequence <= 42
}

// Up to a wall‑clock time (UTC)
$win = EventWindow::toDateUtc(new DateTimeImmutable('2025-01-01T00:00:00Z'));
foreach ($eventStore->load($id, $win) as $e) {
    // events that occurred on/before that timestamp
}

// Combine with snapshots transparently via repositories (see Repositories)
```

**Optimistic concurrency** is handled for you by `AggregateSession`. You can disable it via:

```php
// config/pillar.php
'event_store' => [
    'options' => [
        'optimistic_locking' => true, // set to false to disable
    ],
],
```

When implementing a store, `append()` must throw a `ConcurrencyException` if `$expectedSequence` doesn’t match.

---

## Reading & writing

```php
// Append; returns new per-aggregate sequence
$seq = $eventStore->append($id, new DocumentCreated($title), $expectedSeq);

// Load one aggregate’s events (unbounded stream)
foreach ($eventStore->load($id) as $stored) {
    // $stored->event, $stored->aggregateId, $stored->aggregateSequence, $stored->occurredAt, ...
}

// Load events up to a boundary
use Pillar\Event\EventWindow;
$win = EventWindow::toAggSeq(100); // or ::toDateUtc($ts), ::toGlobalSeq($n)
foreach ($eventStore->load($id, $win) as $stored) {
    // project ‘as of’ this boundary
}

// Load all events (optionally filter)
foreach ($eventStore->all(/* aggregateId */ null, /* eventType */ DocumentRenamed::class) as $stored) {
    // project, analyze, export…
}
```

---

## Fetch strategies

Different domains need different trade-offs between simplicity, memory footprint, and raw throughput.  
Pillar uses **Event Fetch Strategies** to choose *how* events are read.

Built-in strategies:

| Key            | Class                                        | Description                                                                 |
|----------------|----------------------------------------------|-----------------------------------------------------------------------------|
| `db_load_all`  | `DatabaseLoadAllStrategy`                    | Loads all events into memory. Simple; not ideal for very large streams.    |
| `db_chunked`   | `DatabaseChunkedFetchStrategy`               | Loads events in chunks (default 1000). Balanced for most use cases.        |
| `db_streaming` | `DatabaseCursorFetchStrategy`                | Uses a DB cursor to stream without buffering. Best for very large streams. |

Custom strategies implement:

```php
interface EventFetchStrategy
{
    /** @return Generator<StoredEvent> */
    public function load(AggregateRootId $id, ?EventWindow $window = null): Generator;

    /** @return Generator<StoredEvent> */
    public function all(?AggregateRootId $aggregateId = null, ?string $eventType = null): Generator;
}
```

Configure defaults and overrides in `config/pillar.php`:

```php
'fetch_strategies' => [
    'default' => 'db_chunked',

    'overrides' => [
        // \Context\Big\Domain\Aggregate\HugeAggregate::class => 'db_streaming',
    ],

    'available' => [
        'db_load_all' => [
            'class' => \Pillar\Event\Fetch\Database\DatabaseLoadAllStrategy::class,
            'options' => [],
        ],
        'db_chunked' => [
            'class' => \Pillar\Event\Fetch\Database\DatabaseChunkedFetchStrategy::class,
            'options' => ['chunk_size' => 1000],
        ],
        'db_streaming' => [
            'class' => \Pillar\Event\Fetch\Database\DatabaseCursorFetchStrategy::class,
            'options' => [],
        ],
    ],
],
```

> **Database convenience:** `DatabaseEventStore` also exposes `getByGlobalSequence(int $seq): ?StoredEvent` to look up a single event quickly by its global sequence number.

---

## Stream resolver

For advanced setups (multi-tenancy, sharding, per-aggregate tables), a **Stream Resolver** maps an aggregate ID to a logical stream/table.

Default resolver (database):

- Global default (e.g. `events`)
- Per aggregate type mapping
- Optional per aggregate ID stream naming:
  - `default_id` → `{default}_{aggregateId}`
  - `type_id` → `{AggregateBaseName}_{aggregateId}`

```php
'stream_resolver' => [
    'class' => \Pillar\Event\Stream\DatabaseStreamResolver::class,
    'options' => [
        'default' => 'events',
        'per_aggregate_type' => [
            // \Context\Document\Domain\Aggregate\Document::class => 'document_events',
        ],
        'per_aggregate_id' => false,
        'per_aggregate_id_format' => 'type_id', // or 'default_id'
    ],
],
```

Roll your own by implementing:

```php
interface StreamResolver
{
    /** Return the stream name/identifier for a given aggregate root ID (or default if null). */
    public function resolve(?AggregateRootId $id): string;
}
```

---

## Replays

To rebuild read models, replay stored events. Only listeners implementing `Projector` are invoked.

```bash
php artisan pillar:replay-events
php artisan pillar:replay-events {aggregate_id}
php artisan pillar:replay-events {aggregate_id} {event_type}
# Filters:
php artisan pillar:replay-events --from-seq=1000 --to-seq=2000
php artisan pillar:replay-events --from-date="2025-01-01" --to-date="2025-01-31"
```

See [/reference/cli-replay](/reference/cli-replay) for all flags and tips.

---

## Notes & tips

- Use **versioned events** + **upcasters** for schema evolution. See [/concepts/versioned-events](/concepts/versioned-events) and [/concepts/event-upcasters](/concepts/event-upcasters).
- For large aggregates, prefer `db_chunked` or `db_streaming` and consider **snapshotting**. See [/concepts/snapshotting](/concepts/snapshotting).
