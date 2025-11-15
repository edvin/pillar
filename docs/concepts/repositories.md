## 🧱 Repositories

Repositories coordinate **load / save** of aggregate roots.  
They handle snapshot lookup, event streaming, optimistic locking, and
point‑in‑time rehydration.  
Pillar resolves repositories **dynamically from configuration**, which means you
can freely mix **event‑sourced** and **state‑backed** aggregates in one system.

Most apps rarely touch repositories directly. In handlers you typically work with the [AggregateSession](/concepts/aggregate-sessions), which opens a session, calls the repository’s `find(...)`, tracks recorded events on your aggregate, and invokes `save(...)` on commit. Reach for repositories when you are writing a custom repository or building low‑level tooling.

### How repositories rehydrate aggregates

For event‑sourced aggregates, `EventStoreRepository` orchestrates rehydration:

1) **Check snapshot store** for the aggregate ID.  
2) If found, begin rehydration **after the snapshot’s version**  
   (using the snapshot only if it is compatible with the caller’s `EventWindow`).  
3) **Stream events** from the Event Store — using your configured fetch strategy —  
   applying all events **within the effective `EventWindow`**.  
4) On save, check the **Snapshot Policy** to decide whether to persist a new
   snapshot. A snapshot is only created when rehydrating the **latest** state
   (i.e. when the window has no upper bounds).

This is transparent to your handlers — you just call `find(...)` and `save(...)`.  
State‑backed repositories can ignore windows or implement their own historical lookup.

---

### Default & per‑aggregate overrides

```php
// config/pillar.php
'repositories' => [
    'default' => \Pillar\Repository\EventStoreRepository::class,
    \Context\Document\Domain\Aggregate\Document::class => \App\Infrastructure\Repository\DocumentRepository::class,
],
```

The `default` is `EventStoreRepository` (event‑sourced). Any listed aggregate class is routed to its own repository.

---

### Point‑in‑time reads (EventWindow)

All repositories implement **point‑in‑time** reads through an optional `EventWindow` argument. This lets you rehydrate an aggregate **as it was** at a given aggregate version, global sequence, or timestamp.

```php
use Pillar\Event\EventWindow;
use Pillar\Repository\RepositoryResolver;

/** @var RepositoryResolver $resolver */
$resolver = app(RepositoryResolver::class);
$repo     = $resolver->forId($id);

// Latest
$latest = $repo->find($id);

// As of aggregate version
$atVersion = $repo->find($id, EventWindow::toStreamSeq(5));

// As of global sequence
$atGlobal  = $repo->find($id, EventWindow::toGlobalSeq(12_345));

// As of a UTC timestamp
$atTime    = $repo->find($id, EventWindow::toDateUtc(new DateTimeImmutable('2025-01-01T00:00:00Z')));

// Each returns a LoadedAggregate DTO:
if ($atVersion) {
    $aggregate = $atVersion->aggregate;
    $version   = $atVersion->version; // persisted version at that point
}
```

`EventStoreRepository` applies windows by:
* selecting a compatible snapshot (if any),  
* adjusting the window’s lower bound to that snapshot’s version,  
* streaming only events **inside** the requested bounds.

State‑backed repositories may ignore windows entirely or implement custom
historical lookup.

---

### Contract

```php
final class LoadedAggregate
{
    public function __construct(
        public readonly AggregateRoot $aggregate,
        // The aggregate’s persisted version at the replay boundary:
        //   - equal to the last applied stream_sequence, or
        //   - equal to the snapshot version when no events were applied.
        public readonly int $version = 0,
    ) {}
}

interface AggregateRepository
{
    /**
     * Rehydrate the aggregate; when $window is provided, return state as-of that bound.
     */
    public function find(AggregateRootId $id, ?EventWindow $window = null): ?LoadedAggregate;

    /**
     * Persist changes. Implementations may honor optimistic concurrency via $expectedVersion.
     */
    public function save(AggregateRoot $aggregate, ?int $expectedVersion = null): void;
}
```

---

### Saving an aggregate

```php
// Persist newly recorded events on the aggregate
$repo->save($aggregate, expectedVersion: null);
```

If **optimistic locking** is enabled, the repository will pass the aggregate’s expected version to the event store on append. If a conflicting write is detected, a `ConcurrencyException` will be thrown.

---

### Optimistic concurrency

EventStoreRepository performs **optimistic locking** when enabled:

```php
// config/pillar.php
'event_store' => [
    'class' => \Pillar\Event\DatabaseEventStore::class,
    'options' => [
        'optimistic_locking' => true, // when true, passes expected version to EventStore::append()
    ],
],
```

Custom repositories may implement their own version checks or ignore
`$expectedVersion` entirely.

---

### Example: simple Eloquent / state‑backed repository

For aggregates you don’t want event‑sourced, create a small repository that maps to your tables. (Full example on **Aggregates**.)

```php
final class DocumentRepository implements AggregateRepository
{
    public function find(AggregateRootId $id, ?EventWindow $window = null): ?LoadedAggregate
    {
        // State-backed example: ignore $window or implement your own historical lookup.
        $row = DocumentRecord::query()->find((string) $id);
        if (!$row) return null;

        $agg = new Document(
            DocumentId::from($row->id),
            $row->title
        );

        // Persisted version unknown here; set 0 (the session won’t send expectedVersion unless configured)
        return new LoadedAggregate($agg, 0);
    }

    public function save(AggregateRoot $aggregate, ?int $expectedVersion = null): void
    {
        /** @var Document $aggregate */
        DocumentRecord::query()->updateOrCreate(
            ['id' => (string) $aggregate->id()],
            ['title' => $aggregate->title()]
        );
    }
}
```

---

### See also

- **Aggregate Sessions** — how handlers interact with repositories via a unit‑of‑work wrapper  
- **Aggregate Roots** — event‑sourced vs state‑backed examples  
- **Event Store** — streaming, fetch strategies, snapshots  
- **Snapshotting** — policies and stores