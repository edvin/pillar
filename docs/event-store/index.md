# 🗃️ Event Store

Pillar’s event store is a **pluggable abstraction** that supports streaming domain events efficiently using PHP generators.  
The default implementation, `DatabaseEventStore`, persists domain events in a database table — but you can replace it
with any backend (KurrentDB, Kafka, DynamoDB, S3 etc).  
Events are grouped into **streams**; in Pillar, a stream corresponds to a single aggregate root instance (e.g. `order-1234`).

---

## Interface

```php
interface EventStore
{
    /**
     * Appends an event to the stream for a given aggregate root and returns
     * the assigned per-stream version (stream_sequence).
     *
     * If $expectedSequence is provided, the append only succeeds when the current
     * last per-stream version equals the expected value. Otherwise a
     * ConcurrencyException MUST be thrown.
     */
    public function append(AggregateRootId $id, object $event, ?int $expectedSequence = null): int;

    /**
     * Stream events for a single stream (aggregate) within an optional window.
     *
     * Implementations MUST yield events in ascending per-stream sequence order.
     *
     * @return Generator<StoredEvent>
     */
    public function streamFor(AggregateRootId $id, ?EventWindow $window = null): Generator;

    /**
     * Scan events across the whole store in global order.
     *
     * Implementations MUST yield events in ascending global sequence order.
     * Implementations MAY apply additional filtering by $eventType.
     *
     * Only the global bounds of the EventWindow are applied here; per-stream
     * bounds are ignored in this method.
     *
     * @return Generator<StoredEvent>
     */
    public function stream(?EventWindow $window = null, ?string $eventType = null): Generator;

    /**
     * Fetch a single stored event by its global, monotonically increasing sequence
     * number. Returns null if no event exists for the given sequence.
     */
    public function getByGlobalSequence(int $sequence): ?StoredEvent;

    /**
     * Fetch the most recently updated streams (aggregates) from the store.
     *
     * Each returned StoredEvent is the latest (highest per-stream sequence) event
     * for its stream. The list is ordered by the global sequence of those latest
     * events in descending order (most recently updated stream first).
     *
     * @return array<int, StoredEvent>
     */
    public function recent(int $limit): array;
}
```

Instead of returning arrays, `streamFor()` and `stream()` yield `StoredEvent` instances as **generators** — allowing **true streaming** of large event streams with minimal memory use.

Under the default `DatabaseEventStore`, events live in a single **stream-centric** table with columns:

- `sequence` – global, monotonically increasing primary key
- `stream_id` – logical stream name (e.g. `"document-<uuid>"`)
- `stream_sequence` – per‑stream version (1, 2, 3, …) for each `stream_id`
- `event_type`, `event_version`, `event_data`, `occurred_at`, `correlation_id`

Two convenience methods help with diagnostics and dashboards:

- `getByGlobalSequence(int $sequence)` – fetch one event by global sequence
- `recent(int $limit)` – return the latest event per stream, most recent streams first

### Point‑in‑time & bounded reads (EventWindow)

Use `EventWindow` to cap a read at a specific boundary — by per-stream version, by global sequence, or by UTC time. This lets you **inspect history** or rebuild state _as of_ some moment.

```php
use Pillar\Event\EventWindow;

// Up to a specific per-stream version
$win = EventWindow::toStreamSeq(42);
foreach ($eventStore->streamFor($id, $win) as $e) {
    // events with stream_sequence <= 42
}

// Up to a wall‑clock time (UTC)
$win = EventWindow::toDateUtc(new DateTimeImmutable('2025-01-01T00:00:00Z'));
foreach ($eventStore->streamFor($id, $win) as $e) {
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
// Append; returns new per-stream sequence
$seq = $eventStore->append($id, new DocumentCreated($title), $expectedSeq);

// Stream one aggregate’s events (unbounded stream)
foreach ($eventStore->streamFor($id) as $stored) {
    // $stored->event, $stored->streamId, $stored->streamSequence, $stored->occurredAt, ...
}

// Stream events up to a boundary
use Pillar\Event\EventWindow;
$win = EventWindow::toStreamSeq(100); // or ::toDateUtc($ts), ::toGlobalSeq($n)
foreach ($eventStore->streamFor($id, $win) as $stored) {
    // project ‘as of’ this boundary
}

// Stream all events (optionally filter by type)
foreach ($eventStore->stream(window: null, eventType: DocumentRenamed::class) as $stored) {
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

Custom strategies implement a lower-level interface that `DatabaseEventStore` uses internally to power `streamFor()` and `stream()`:

```php
interface EventFetchStrategy
{
    /** @return Generator<StoredEvent> */
    public function streamFor(AggregateRootId $id, ?EventWindow $window = null): Generator;

    /** @return Generator<StoredEvent> */
    public function stream(?EventWindow $window = null, ?string $eventType = null): Generator;
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

> **Database convenience:** `DatabaseEventStore` also exposes:
> - `getByGlobalSequence(int $seq): ?StoredEvent` to look up a single event by global sequence
> - `recent(int $limit): array<StoredEvent>` to list the most recently updated streams

---

## Stream names

Pillar treats each **stream** as the event history for a single aggregate instance.
Rather than passing raw `stream_id` strings around your domain, you work with
strongly-typed `AggregateRootId` value objects.

Stream IDs are derived from these IDs by the `AggregateRegistry`:

- Each `AggregateRootId` class is registered with a short, stable **prefix**  
  (for example, `document` for `DocumentId`).
- The registry turns an ID into a stream name like `document-<raw-id>`.
- That stream name is what ends up in the `stream_id` column in the event store.

Example:

```php
use Pillar\Aggregate\AggregateRegistry;
use Tests\Fixtures\Document\DocumentId;

$id = DocumentId::new();              // e.g. an internal UUID
$streamId = app(AggregateRegistry::class)->toStreamName($id);
// "document-{$id->value()}" → stored in events.stream_id
```

The mapping is reversible as well:

```php
$id = app(AggregateRegistry::class)->idFromStreamName($streamId);
// returns the correct AggregateRootId subtype (e.g. DocumentId)
```

This gives you:
 
- Readable, prefix-tagged stream IDs at the storage/UI level.
- A single `events` table keyed by `stream_id` + `stream_sequence`.
- Strongly-typed IDs inside your domain and application layers.

Most application code never touches `stream_id` directly; you call
`EventStore::append()` / `streamFor()` with an `AggregateRootId` and Pillar
handles the mapping behind the scenes.

---

## Replays

To rebuild read models, replay stored events. Only listeners implementing `Projector` are invoked.

```bash
php artisan pillar:replay-events
php artisan pillar:replay-events [stream_id]
php artisan pillar:replay-events [stream_id] [event_type]
# Filters:
php artisan pillar:replay-events --from-seq=1000 --to-seq=2000
php artisan pillar:replay-events --from-date="2025-01-01" --to-date="2025-01-31"
```

See [/reference/cli-replay](/reference/cli-replay) for all flags and tips.

---

## Notes & tips

- Use **versioned events** + **upcasters** for schema evolution. See [/concepts/versioned-events](/concepts/versioned-events) and [/concepts/event-upcasters](/concepts/event-upcasters).
- For large aggregates, prefer `db_chunked` or `db_streaming` and consider **snapshotting**. See [/concepts/snapshotting](/concepts/snapshotting).
