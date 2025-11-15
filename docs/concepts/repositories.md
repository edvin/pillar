## 🧱 Repositories

Repositories coordinate **load / save** for an aggregate root — handling snapshot loading and event streaming to rehydrate state. Pillar resolves which repository to use **dynamically from configuration**, so you can mix event‑sourced and state‑backed aggregates in the same app.

Most apps rarely touch repositories directly. In handlers you typically work with the [AggregateSession](/concepts/aggregate-sessions), which opens a session, calls the repository’s `find(...)`, tracks recorded events on your aggregate, and invokes `save(...)` on commit. Reach for repositories when you are writing a custom repository or building low‑level tooling.

### How repositories rehydrate aggregates

For event‑sourced aggregates, `EventStoreRepository` does the lifting:

1) **Check snapshot store** for the aggregate ID.  
2) **If a snapshot exists**, start **after** the snapshot’s version; otherwise construct a fresh aggregate instance.  
3) **Stream events** from the Event Store using your configured **fetch strategy**, optionally bounded by an `EventWindow` (e.g., as‑of a version / global sequence / UTC time), and apply them to the aggregate.  
4) On save, consult the **Snapshot Policy** to decide whether to persist a new snapshot.

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

> `EventStoreRepository` applies windows by replaying events up to the bound (honoring snapshots automatically).  
> State‑backed repositories may ignore the window or apply their own logic (document in your repo).

---

### Contract

```php
final class LoadedAggregate
{
    public function __construct(
        public readonly AggregateRoot $aggregate,
        public readonly int $version = 0, // default when no persisted version is known
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

`EventStoreRepository` uses **optimistic locking** when enabled:

```php
// config/pillar.php
'event_store' => [
    'class' => \Pillar\Event\DatabaseEventStore::class,
    'options' => [
        'optimistic_locking' => true, // when true, passes expected version to EventStore::append()
    ],
],
```

Custom repositories can implement their own version checks or ignore `$expectedVersion`.

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