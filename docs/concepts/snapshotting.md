## 💾 Snapshotting

Snapshotting lets you periodically capture an aggregate’s current state to avoid replaying a long event history on load.

:::info Related Reading
- [Aggregate Roots](../concepts/aggregate-roots.md)
- [Aggregate IDs](../concepts/aggregate-ids.md)
- [Aggregate Sessions](../concepts/aggregate-sessions.md)
- [Event Window](../concepts/event-window.md)
- [Repositories](../concepts/repositories.md)
:::

### Opt-in with `Snapshottable`

Aggregates **opt in** to snapshotting by implementing the `Snapshottable` interface and providing two methods:

```php
interface Snapshottable
{
    /** Return a serializable array representing the current state. */
    public function toSnapshot(): array;

    /** Rebuild an aggregate from a previously stored snapshot. */
    public static function fromSnapshot(array $data): static;
}
```

**Example — aggregate implementing `Snapshottable`:**

```php
use Pillar\Aggregate\EventSourcedAggregateRoot;
use Pillar\Aggregate\RecordedEvents;
use Pillar\Snapshot\Snapshottable;
use Context\Document\Domain\Identifier\DocumentId;

final class Document implements Snapshottable, EventSourcedAggregateRoot
{
    use RecordedEvents;
    
    private DocumentId $id;
    public string $title;

    public function __construct(DocumentId $id, string $title)
    {
        $this->id = $id;
        $this->title = $title;
    }

    public function id(): DocumentId { return $this->id; }

    // Snapshottable
    public function toSnapshot(): array
    {
        return ['id' => (string) $this->id, 'title' => $this->title];
    }

    public static function fromSnapshot(array $data): static
    {
        return new self(DocumentId::from($data['id']), $data['title']);
    }
}
```


> Aggregates that do **not** implement `Snapshottable` are ignored by the snapshot store.


### Configuration

By default, Pillar uses the `DatabaseSnapshotStore`, storing snapshots in a relational database table. You can swap this for the cache-based store if you prefer an in-memory or Redis-backed snapshot layer.

Configure snapshotting in `config/pillar.php`:

```php
'snapshot' => [
    'store' => [
        // 'class' => \Pillar\Snapshot\CacheSnapshotStore::class,
        'class' => \Pillar\Snapshot\DatabaseSnapshotStore::class,
        'options' => [
            'table' => 'snapshots',
        ],
    ],
    'ttl' => null, // Time-to-live in seconds (null = indefinitely)

    // Global default policy
    'policy' => [
        'class' => \Pillar\Snapshot\AlwaysSnapshotPolicy::class,
        'options' => [],
    ],

    'mode' => 'inline', // 'inline' or 'queued'
    'queue' => env('PILLAR_SNAPSHOT_QUEUE', 'default'),
    'connection' => env('PILLAR_SNAPSHOT_QUEUE_CONNECTION', env('QUEUE_CONNECTION', 'database')),

    // Per-aggregate overrides (keyed by aggregate root class)
    'overrides' => [
        // \App\Domain\Foo\FooAggregate::class => [
        //     'class' => \Pillar\Snapshot\CadenceSnapshotPolicy::class,
        //     'options' => ['threshold' => 500, 'offset' => 0],
        // ],
        // \App\Domain\Reports\ReportAggregate::class => [
        //     'class' => \Pillar\Snapshot\OnDemandSnapshotPolicy::class,
        //     'options' => [],
        // ],
    ],
],
```

Pillar resolves a `SnapshotPolicy` by first using the global `snapshot.policy` as the default and then applying any
per-aggregate overrides defined in `snapshot.overrides`. This lets you mix different snapshot behaviors for different
aggregates while keeping a simple global default.

### Built-in policies

| Policy        | Class                    | Behavior                                                                 | Options                                                   |
|---------------|--------------------------|--------------------------------------------------------------------------|-----------------------------------------------------------|
| **Always**    | `AlwaysSnapshotPolicy`   | Snapshot automatically whenever the commit persisted one or more events. | _None_                                                    |
| **Cadence**   | `CadenceSnapshotPolicy`  | Snapshot on a cadence: when `(newSeq - offset) % threshold === 0`.       | `threshold` (int, default 100), `offset` (int, default 0) |
| **On-Demand** | `OnDemandSnapshotPolicy` | Never auto-snapshot; call the snapshot store yourself when you decide.   | _None_                                                    |

**Parameters passed to policies**

- `$newSeq` — last persisted aggregate version *after* the commit
- `$prevSeq` — aggregate version at load time (0 if new)
- `$delta` — number of events persisted in this commit (`$newSeq - $prevSeq`)

### Snapshots and read-side usage

Snapshotting is what makes it practical to use aggregates for **small, focused read flows** as well as writes.

- With a policy like `AlwaysSnapshotPolicy`, rehydration usually becomes “load latest snapshot + a short tail of events”.
- This keeps command handlers fast even when a stream has many historical events.
- For admin screens or one-off tools, it can be perfectly fine to read via aggregates instead of dedicated projections.

For more on how this fits into the bigger picture, see [CQRS](../concepts/cqrs.md).

### Manual snapshots (On-Demand)

When using `OnDemandSnapshotPolicy`, Pillar won't auto-snapshot.

**Note:** SnapshotStore::save() takes an aggregate ID, sequence number, and a payload (the aggregate's snapshot memento), rather than the aggregate instance itself.

**Tip:** You can take a snapshot at any **arbitrary point**, regardless of which `SnapshotPolicy` is configured —
calling `SnapshotStore::save(...)` bypasses the policy (the store will still no‑op for aggregates that don’t implement
`Snapshottable`). If you need the current persisted version, load the aggregate via its repository:

```php
use Pillar\Repository\RepositoryResolver;
use Pillar\Snapshot\SnapshotStore;

$loaded = app(RepositoryResolver::class)->forId($id)->find($id);
if ($loaded) {
    app(SnapshotStore::class)->save(
        $id,
        $loaded->version,
        $loaded->aggregate->toSnapshot(),
    );
}
```


### Storage

The default `DatabaseSnapshotStore` persists snapshots in the database table configured under `snapshot.store.options.table` (`snapshots` by default). This makes snapshotting durable across restarts and cache flushes.

Alternatively, you can switch to `CacheSnapshotStore`, which uses Laravel’s cache layer (e.g. Redis, database, or array cache) for snapshot storage. This is useful when you want ultra-fast, ephemeral snapshots that are backed by whatever cache store you configure in Laravel.

### Custom dynamic policy

You can implement domain-specific logic by writing your own policy:

```php
use Pillar\Snapshot\SnapshotPolicy;
use Pillar\Aggregate\AggregateRoot;

final class BigAggregatePolicy implements SnapshotPolicy
{
    public function __construct(private int $maxDelta = 250) {}

    public function shouldSnapshot(AggregateRoot $aggregate, int $newSeq, int $prevSeq, int $delta): bool
    {
        // Example: snapshot big aggregates frequently, small ones rarely
        if ($aggregate instanceof \App\Aggregates\BigAggregate) {
            return $delta > 0 && $delta >= $this->maxDelta;
        }

        // Fallback cadence every 100 events
        return $delta > 0 && ($newSeq % 100) === 0;
    }
}
```

Register it as the default or as an override in `snapshot.policy`.

---

### 📚 Related Reading
- [Aggregate Roots](../concepts/aggregate-roots.md)
- [Aggregate IDs](../concepts/aggregate-ids.md)
- [Aggregate Sessions](../concepts/aggregate-sessions.md)
- [Event Window](../concepts/event-window.md)
- [Repositories](../concepts/repositories.md)
- [CQRS](../concepts/cqrs.md)