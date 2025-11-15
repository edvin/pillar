# Fetch strategies

Fetch strategies control **how** Pillar streams events off your store (chunked pages, DB cursors, or load‑all). You never call a strategy directly. Instead, **every read path** in Pillar flows through the `EventFetchStrategyResolver`, which selects a strategy based on your configuration (`fetch_strategies.default` with optional per‑aggregate overrides). That means your choice applies uniformly to:

- **AggregateSession / Repository** – When handlers call `$session->find(...)`, the repository loads a snapshot (if any) and then calls `EventStore::streamFor($id, $window)`. The Event Store delegates to the resolver, so your configured strategy executes the read. Any `EventWindow` bounds are forwarded as‑is.
- **EventReplayer (`pillar:replay-events`)** – Replays use `EventStore::streamFor($id, $window)` for a single stream, or `EventStore::stream($window, $eventType)` for global replays, and therefore run through the selected strategy.
- **Stream Browser (UI)** – The dashboard and stream timeline endpoints call `EventStore::recent()`, `EventStore::streamFor()` and `EventStore::stream()`; they automatically inherit your choice.
- **Direct use of `EventStore`** – If you call `streamFor()` or `stream()` yourself, the Event Store still routes through the resolver; you never need to instantiate strategies manually.

Choosing a different strategy does **not** change domain behavior—only the mechanics of reading (throughput, memory profile, and query shape). You can switch between them without changing application code.

Pillar supports multiple ways to stream events from the store. You can choose the
global default and override per aggregate.

```php
// config/pillar.php
'fetch_strategies' => [
  'default'   => 'db_chunked',
  'overrides' => [
    // \App\Aggregates\Invoice::class => 'db_streaming',
  ],
  'available' => [
    'db_load_all' => [...],
    'db_chunked'  => [...],
    'db_streaming'=> [...],
  ],
],
```

Pillar also supports PostgreSQL native partitioning out of the box. Partitioning transparently applies to all fetch strategies — chunked, streaming, or load-all — because strategies operate on the logical events table name (`pillar.event_store.options.tables.events`). If the underlying table is partitioned, Postgres will prune partitions automatically based on your `EventWindow` bounds (dates or sequences), improving replay and query performance without requiring any changes in application code.

## `db_chunked` (default)

- Paginates with a “keyset” cursor using per-aggregate or global sequence numbers.
- Good balance of throughput and memory usage.
- Tunable `chunk_size`.
- This strategy is optimized for PostgreSQL and MySQL and is safe to use with partitioned tables.

## `db_streaming`

- Uses DB cursors for true streaming (driver dependent).
- Minimal memory footprint; ideal for very long streams and global replays where backpressure matters.
- Trades some ergonomics for performance characteristics.

## `db_load_all`

- Loads all rows for the requested scope and yields them in memory.
- Simplest, but only suitable for small event sets (tests, demos, tiny aggregates).

### Ordering guarantees

- **Per-stream** reads (`streamFor($id, ...)`) are always yielded in ascending *per-stream* sequence.
- **Global** reads (`stream(...)`) are always yielded in ascending *global* sequence.

### Windows

All strategies respect `EventWindow` bounds:

- `afterStreamSequence` (exclusive)
- `toStreamSequence` (inclusive)
- `afterGlobalSequence` (exclusive)
- `toGlobalSequence` (inclusive)
- `afterDateUtc` (exclusive)
- `toDateUtc` (inclusive)

When used with `EventStore::streamFor()`, both per-stream and global bounds are applied.
When used with `EventStore::stream()`, per-stream bounds are ignored (there is no single stream), and only global bounds (`afterGlobalSequence`, `toGlobalSequence`, `afterDateUtc`, `toDateUtc`) apply.
