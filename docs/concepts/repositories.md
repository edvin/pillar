## 🧱 Repositories

Repositories coordinate **load / save** for an aggregate root. Pillar resolves which repository to use **dynamically from configuration**, so you can mix event‑sourced and state‑backed aggregates in the same app.

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
$atVersion = $repo->find($id, EventWindow::toAggSeq(5));

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
        public readonly \Pillar\Aggregate\AggregateRoot $aggregate,
        public readonly int $version = 0, // default when no persisted version is known
    ) {}
}

interface AggregateRepository
{
    /**
     * Rehydrate the aggregate; when $window is provided, return state as-of that bound.
     */
    public function find(\Pillar\Aggregate\AggregateRootId $id, ?\Pillar\Event\EventWindow $window = null): ?LoadedAggregate;

    /**
     * Persist changes. Implementations may honor optimistic concurrency via $expectedVersion.
     */
    public function save(\Pillar\Aggregate\AggregateRoot $aggregate, ?int $expectedVersion = null): void;
}
```

---

### Optimistic concurrency

`EventStoreRepository` uses **optimistic locking** when enabled:

```php
// config/pillar.php
'event_store' => [
    'class' => \Pillar\Event\DatabaseEventStore::class,
    'options' => [
        'default_fetch_strategy' => 'db_chunked',
        'optimistic_locking' => true, // when true, passes expected version to EventStore::append()
    ],
],
```

Custom repositories can implement their own version checks or ignore `$expectedVersion`.

---

### Example: simple Eloquent / state‑backed repository

For aggregates you don’t want event‑sourced, create a small repository that maps to your tables. (Full example on **Aggregates**.)

```php
use Pillar\Aggregate\AggregateRoot;
use Pillar\Aggregate\AggregateRootId;
use Pillar\Event\EventWindow;
use Pillar\Repository\AggregateRepository;
use Pillar\Repository\LoadedAggregate;

final class DocumentRepository implements AggregateRepository
{
    public function find(AggregateRootId $id, ?EventWindow $window = null): ?LoadedAggregate
    {
        // State-backed example: ignore $window or implement your own historical lookup.
        $row = DocumentRecord::query()->find((string) $id);
        if (!$row) return null;

        $agg = new \Context\Document\Domain\Aggregate\Document(
            \Context\Document\Domain\Identifier\DocumentId::from($row->id),
            $row->title
        );

        // Persisted version unknown here; set 0 (the session won’t send expectedVersion unless configured)
        return new LoadedAggregate($agg, 0);
    }

    public function save(AggregateRoot $aggregate, ?int $expectedVersion = null): void
    {
        /** @var \Context\Document\Domain\Aggregate\Document $aggregate */
        DocumentRecord::query()->updateOrCreate(
            ['id' => (string) $aggregate->id()],
            ['title' => $aggregate->title()]
        );
    }
}
```

---

### See also

- **Aggregate Roots** — event‑sourced vs state‑backed examples  
- **Event Store** — streaming, fetch strategies, snapshots  
- **Snapshotting** — policies and stores