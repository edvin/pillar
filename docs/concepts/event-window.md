---
title: "🪟 EventWindow (point‑in‑time reads)"
outline: deep
---

# 🪟 EventWindow

`EventWindow` lets you describe **bounds** for reading events from a stream or globally:

- where to **start** (strictly *after* a given aggregate version), and/or
- where to **stop** (up to an aggregate version, a global sequence, or a UTC timestamp).

It is used by `EventStore::streamFor($id, ?EventWindow $window = null)`, by
`EventStore::stream(?EventWindow $window = null, ?string $eventType = null)` (for global scans), and by
`EventStoreRepository::find($id, ?EventWindow $window = null)`.

## API

::: info Related Reading
- [Aggregate Sessions](../concepts/aggregate-sessions.md)
- [Repositories](../concepts/repositories.md)
- [Fetch Strategies](../concepts/fetch-strategies.md)
- [Snapshotting](../concepts/snapshotting.md)
- [Events](../concepts/events.md)
:::

```php
final class EventWindow
{
    public readonly int $afterStreamSequence;        // default 0
    public readonly ?int $toStreamSequence;          // null = unbounded
    public readonly ?int $toGlobalSequence;             // null = unbounded
    public readonly ?\DateTimeImmutable $toDateUtc;    // null = unbounded

    // Common constructors
    public static function afterStreamSeq(int $after): self;
    public static function toStreamSeq(int $to): self;
    public static function toGlobalSeq(int $to): self;
    public static function toDateUtc(\DateTimeImmutable $to): self;
    public static function betweenStreamSeq(int $after, int $to): self;
}
```

### Semantics

- **Start**: `afterStreamSequence` is *exclusive* (start **after** this version).
- **Stop**: all upper bounds are *inclusive* (read **up to and including** the bound).  
  Combining multiple `to*` bounds narrows the window (the earliest cutoff wins).

### Interaction with Snapshots and Repositories

When used through `EventStoreRepository::find()`:

- The repository will determine the **effective starting point** by comparing  
  your `afterStreamSequence` with any available snapshot version.
- If a snapshot exists **at or after** your requested starting point, the repository
  will begin replay *after the snapshot*.
- If the snapshot is **older** than the requested starting point, it is ignored
  and reconstruction starts from `afterStreamSequence`.

Because of this, calls like:

```php
$repo->find($id, EventWindow::toStreamSeq(50));
```

may internally convert into:

```php
new EventWindow(
    afterStreamSequence: $snapshotVersion,  // if >= requested window start
    toStreamSequence: 50,
    toGlobalSequence: null,
    toDateUtc: null,
);
```

This behavior is intentional—it ensures that snapshots never cause you to miss events,
while still avoiding unnecessary replay.

### Using EventWindow with global scans

When using `EventWindow` with global event scans (such as `EventStore::stream()`), **only the global bounds**—`toGlobalSequence` and `toDateUtc`—are applied. The per-stream bounds (`afterStreamSequence`, `toStreamSequence`) are ignored in this context. This ensures that global reads operate consistently across all streams. For more on this behavior, see [Fetch Strategies](../concepts/fetch-strategies.md).

## Examples

```php
// Rebuild state as of stream version 25
$loaded = $repo->find($id, EventWindow::toStreamSeq(25));

// Rebuild state as of a point in time (UTC)
$loaded = $repo->find($id, EventWindow::toDateUtc(new DateTimeImmutable('2025-01-01T00:00:00Z')));

// Stream events in pages up to a global checkpoint
foreach ($store->stream(EventWindow::toGlobalSeq($checkpoint)) as $e) {
    // ...
}
```



### 📚 Related Reading

- [Aggregate Sessions](../concepts/aggregate-sessions.md)
- [Repositories](../concepts/repositories.md)
- [Fetch Strategies](../concepts/fetch-strategies.md)
- [Snapshotting](../concepts/snapshotting.md)
- [Events](../concepts/events.md)
