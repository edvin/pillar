# Fetch strategies

Fetch strategies control **how** Pillar streams events off your store (chunked pages, DB cursors, or load‑all). You never call a strategy directly. Instead, **every read path** in Pillar flows through the `EventFetchStrategyResolver`, which selects a strategy based on your configuration (`fetch_strategies.default` with optional per‑aggregate overrides). That means your choice applies uniformly to:

- **AggregateSession / Repository** – When handlers call `$session->find(...)`, the repository loads a snapshot (if any) and then calls `EventStore::load($id, $window)`. The Event Store delegates to the resolver, so your configured strategy executes the read. Any `EventWindow` bounds are forwarded as‑is.
- **EventReplayer (`pillar:replay-events`)** – Replays use `EventStore::all($aggregateId, $window, $eventType)` and therefore stream with the selected strategy.
- **Stream Browser (UI)** – The dashboard and aggregate timeline endpoints call `EventStore::all` / `load`; they automatically inherit your choice.
- **Direct use of `EventStore`** – If you call `load()` or `all()` yourself, the Event Store still routes through the resolver; you never need to instantiate strategies manually.

Choosing a different strategy does **not** change domain behavior—only the mechanics of reading (throughput, memory profile, and query shape). You can switch between them without changing application code.

Pillar supports multiple ways to stream events from the store. You can choose the
global default and override per aggregate.

```php
// config/pillar.php
'fetch_strategies' => [
  'default'   => 'db_chunked',
  'overrides' => [
    // \App\Aggregates\Foo::class => 'db_streaming',
  ],
  'available' => [
    'db_load_all' => [...],
    'db_chunked'  => [...],
    'db_streaming'=> [...],
  ],
],
```

## `db_chunked` (default)

- Paginates with a “keyset” cursor using per-aggregate or global sequence numbers.
- Good balance of throughput and memory usage.
- Tunable `chunk_size`.

## `db_streaming`

- Uses DB cursors for true streaming (driver dependent).
- Minimal memory footprint; great for very long streams and replays.
- Trades some ergonomics for performance characteristics.

## `db_load_all`

- Loads all rows for the requested scope and yields them in memory.
- Simplest, but only suitable for small event sets (tests, demos, tiny aggregates).

### Ordering guarantees

- **Per-aggregate** streams are always yielded in ascending *per-aggregate* sequence.
- **Global** streams are always yielded in ascending *global* sequence.

### Windows

All strategies respect `EventWindow` bounds:

- `afterAggregateSequence` (exclusive)
- `toAggregateSequence` (inclusive)
- `afterGlobalSequence` (exclusive)
- `toGlobalSequence` (inclusive)
- `afterDateUtc` (exclusive)
- `toDateUtc` (inclusive)
