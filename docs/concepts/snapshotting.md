## 💾 Snapshotting

Snapshotting lets you periodically capture an aggregate’s current state to avoid replaying a long event history on load.

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

> Aggregates that do **not** implement `Snapshottable` are ignored by the snapshot store.

### Configuration

Configure snapshotting in `config/pillar.php`:

```php
'snapshot' => [
    'store' => [
        'class' => \Pillar\Snapshot\CacheSnapshotStore::class,
    ],
    'ttl' => null, // Time-to-live in seconds (null = indefinitely)

    // Delegating policy selects which policy to use per aggregate
    'policy' => [
        'default' => [
            'class' => \Pillar\Snapshot\AlwaysSnapshotPolicy::class,
        ],
        'overrides' => [
            // \App\Aggregates\BigAggregate::class => [
            //     'class' => \Pillar\Snapshot\CadenceSnapshotPolicy::class,
            //     'options' => ['threshold' => 500, 'offset' => 0],
            // ],
            // \App\Aggregates\Report::class => [
            //     'class' => \Pillar\Snapshot\OnDemandSnapshotPolicy::class,
            // ],
        ],
    ],
],
```

Pillar binds `SnapshotPolicy` to a **delegating policy** that reads this config, instantiates the chosen policy, and
applies any per-aggregate overrides.

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

### Manual snapshots (On-Demand)

When using `OnDemandSnapshotPolicy`, Pillar won't auto-snapshot.

**Tip:** You can take a snapshot at any **arbitrary point**, regardless of which `SnapshotPolicy` is configured —
calling `SnapshotStore::save(...)` bypasses the policy (the store will still no‑op for aggregates that don’t implement
`Snapshottable`). If you need the current persisted version, load the aggregate via its repository:

```php
use Pillar\Repository\RepositoryResolver;
use Pillar\Snapshot\SnapshotStore;

$loaded = app(RepositoryResolver::class)->forId($id)->find($id);
if ($loaded) {
    app(SnapshotStore::class)->save($loaded->aggregate, $loaded->version);
}
```

### Storage

The default `CacheSnapshotStore` uses Laravel’s cache. Set `ttl` for automatic expiry (seconds), or leave `null` to keep
snapshots indefinitely. For best performance in production, point your cache to Redis or another fast store.

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