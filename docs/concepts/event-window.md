---
title: "🪟 EventWindow (point‑in‑time reads)"
outline: deep
---

# 🪟 EventWindow

`EventWindow` lets you describe **bounds** for reading an aggregate’s events:
- where to **start** (strictly *after* a given aggregate version), and/or
- where to **stop** (up to an aggregate version, a global sequence, or a UTC timestamp).

It is used by `EventStore::load($id, ?EventWindow $window = null)` and by `EventStoreRepository::find($id, ?EventWindow)`.

## API

```php
final class EventWindow
{
    public readonly int $afterAggregateSequence;        // default 0
    public readonly ?int $toAggregateSequence;          // null = unbounded
    public readonly ?int $toGlobalSequence;             // null = unbounded
    public readonly ?\DateTimeImmutable $toDateUtc;    // null = unbounded

    // Common constructors
    public static function afterAggSeq(int $after): self;
    public static function toAggSeq(int $to): self;
    public static function toGlobalSeq(int $to): self;
    public static function toDateUtc(\DateTimeImmutable $to): self;
    public static function betweenAggSeq(int $after, int $to): self;
}
```

### Semantics

- **Start**: `afterAggregateSequence` is *exclusive* (start **after** this version).
- **Stop**: all upper bounds are *inclusive* (read **up to and including** the bound).  
  Combining multiple `to*` bounds narrows the window (the earliest cutoff wins).

## Examples

```php
// Rebuild state as of aggregate version 25
$loaded = $repo->find($id, EventWindow::toAggSeq(25));

// Rebuild state as of a point in time (UTC)
$loaded = $repo->find($id, EventWindow::toDateUtc(new \DateTimeImmutable('2025-01-01T00:00:00Z')));

// Stream events in pages up to a global checkpoint
foreach ($store->load($id, EventWindow::toGlobalSeq($checkpoint)) as $e) {
    // ...
}
```
