# Events in Pillar

---

## TL;DR

- **All events you record are persisted** in the aggregate’s event stream (for rehydration and replay).
- **Local events** (no marker interface) are **not published** to the outside world; they are private to the aggregate
  and only used to mutate its state.
- **`ShouldPublish`** marks an event for **asynchronous publication** via the **Transactional Outbox** (reliable,
  at‑least‑once delivery).
- **`ShouldPublishInline`** marks an event to be **published inline** inside the **same DB transaction** (great for
  synchronous projectors). If a handler throws, the transaction rolls back.
- During **replay**, publication is suppressed; projectors are driven **directly** by the replayer.

---

## Recording and applying

Aggregates typically use `record()` to mutate state:

```php
$this->record(new TitleChanged('New title'));
```

`record()` immediately calls `applyTitleChanged()` on the aggregate (if present) to update in‑memory state, and—unless
we are reconstituting—queues the event for persistence at commit.

> Rehydration uses the event history and calls `apply*` methods again, but **does not** re‑record the events.

---

## Event types

### 1) Local events (default)

Any event **without** a marker interface is considered *local*:

- Persisted to the event stream.
- **Not** published to external handlers.
- Used for aggregate internal state transitions.

```php
final class TitleChanged
{
    public function __construct(public string $title) {}
}
```

### 2) Asynchronously published: `ShouldPublish`

Implement the marker interface to have the event enqueued into the **Transactional Outbox** in the **same DB transaction
**. A worker delivers it to your bus with retries.

```php
use Pillar\Event\ShouldPublish;

final class DocumentCreated implements ShouldPublish
{
    public function __construct(public string $id, public string $title) {}
}
```

See: **Outbox** page for delivery guarantees, partitioning, retries, etc.

### 3) Inline (in‑transaction) publication: `ShouldPublishInline`

For projections that must be updated **before commit** (and therefore participate in the same transaction), implement
`ShouldPublishInline`:

```php
use Pillar\Event\ShouldPublishInline;

final class TitleProjected implements ShouldPublishInline
{
    public function __construct(public string $id, public string $title) {}
}
```

- The event is persisted, then dispatched to inline handlers **inside the transaction**.
- If a handler throws, the entire transaction rolls back.
- Keep handlers fast and deterministic (no remote I/O).

> Inline vs async are usually mutually exclusive. If you implement both, ensure handlers are idempotent and that you
> actually need both behaviors.

---

## Publication policy

Under the hood, a `PublicationPolicy` decides whether an event should be published. The default policy treats *
*`ShouldPublish`** (and any configured attribute) as a publish signal and suppresses publication during **replay**:

```php
if (EventContext::isReplaying()) {
    return false; // never publish while replaying
}
return $event instanceof ShouldPublish;
```

You can bind your own policy in the service container (see `pillar.publication_policy.class` in config).

---

## Replay semantics

When replaying, the framework sets `EventContext::initialize(..., replaying: true)`. Effects:

- `PublicationPolicy` returns **false** → **no async publication**.
- Inline publishing sites check `EventContext::isReplaying()` and **do nothing**.
- Your **projectors** receive events directly from the replayer.

This keeps replay pure and avoids side effects while still driving read models.

---


## Versioning, aliases, upcasting

- **Versioning**: implement a `VersionedEvent` interface (if present in your app) so each stored row carries an
  `event_version`. Upcasters can adapt old events on read.
- **Aliases**: `EventAliasRegistry` can map short names to FQCNs in storage.
- **StoredEvent**: when fetching by global sequence, you get a `StoredEvent` wrapper with metadata (sequence, aggregate
  id, occurred at, correlation id, etc.).

## Event context (timestamps, correlation IDs, replay flags)

Every event recorded or replayed in Pillar runs under an **EventContext**, which provides:

- **occurredAt()** → the UTC timestamp when the event *actually* happened  
  (during replay this is restored from event metadata).
- **correlationId()** → a per-operation UUID used for tracing and log correlation.
- **isReconstituting()** → true while rebuilding an aggregate from history.
- **isReplaying()** → true while driving projectors in a replay (suppresses publication).

EventContext is automatically set when:
- A command begins (fresh correlation ID, fresh timestamp)
- An event is appended (context timestamp is stored in the row)
- An event is read back during replay (context timestamp is restored)

Example:

```php
EventContext::occurredAt();     // CarbonImmutable timestamp
EventContext::correlationId();  // UUID string
```

Because **occurredAt** survives replay, projectors can use actual historical timestamps—even long after the event occurred.

---

## Best practices

- **Idempotent handlers**: Outbox is at‑least‑once; make handlers idempotent.
- **Keep events small**: Include identifiers and facts, not derived data.
- **No I/O in inline handlers**: treat them like DB triggers; keep them local & fast.
- **Projectors on replay**: Design projectors to consume events from both live flow and replay without branching logic.

---

## Related docs

- **Transactional Outbox** → [Outbox](/concepts/outbox)
- **Outbox Worker (CLI)** → [Outbox Worker](/concepts/outbox-worker)
