# Publication Policy

Pillar uses a **Publication Policy** to decide which events should be sent to the **async publish pipeline** (the transactional outbox).

This policy does **not** affect:

- which events are persisted (all events are persisted),
- which events are dispatched **inline in the transaction** (that is controlled purely by `ShouldPublishInline`),
- which events are delivered during **replay** (projectors are driven directly by the replayer).

It only answers one question:

> “Should this event be enqueued in the outbox for asynchronous publication?”

---

## Default behavior

By default, Pillar wires a conservative policy:

```php
if (EventContext::isReplaying()) {
    return false; // never publish while replaying
}

return $event instanceof ShouldPublish;
```

This means:

- Only events that implement `ShouldPublish` (or matching attributes) are sent to the **outbox**.
- Local events (no marker interface) are still **persisted** and used to rehydrate aggregates, but are **not visible** to any listeners in the live flow.
- During replay (`EventContext::isReplaying()`), **no events are published** at all; projectors are invoked directly by the replayer.

Inline publication via `ShouldPublishInline` is handled separately inside the `EventStoreRepository` and does **not** consult the `PublicationPolicy`.

---

## Inline vs async publication

Pillar has two distinct publication paths:

### 1. Inline publication (`ShouldPublishInline`)

Handled entirely inside the repository:

- Events that implement `ShouldPublishInline` are dispatched **inside the same database transaction** that persists the aggregate and its events.
- If any inline handler throws, the transaction is rolled back.
- Inline dispatch is suppressed during replay via `EventContext::isReplaying()`.

The `PublicationPolicy` is **not used** for inline events.

### 2. Async publication via outbox (`ShouldPublish` + PublicationPolicy)

- When an event is recorded, Pillar calls `PublicationPolicy::shouldPublish($event)`.
- If the policy returns `true`, a **pointer** to the event (its `global_sequence`) is enqueued in the `outbox` table.
- A background worker rehydrates the event via the `EventStore`, initializes `EventContext` (occurredAt, correlationId, aggregateRootId, replay flags), and dispatches it through the bus.

By default, only `ShouldPublish` events are considered publishable here.

---

## Projectors and the publication policy

Projectors can see events in two ways:

1. **Replay path**
    - The `pillar:replay-events` command reads events from the event store and calls projectors **directly**.
    - `PublicationPolicy` is not involved; only the `Projector` interface and event type matter.
    - This is what you use to rebuild read models from history.

2. **Live path**
    - When aggregates record events, the async path only sees events that the `PublicationPolicy` deems publishable.
    - Projectors are just listeners behind the bus in this path.
    - If an event is not publishable under your policy, projectors will **not** see it live (only during replay).

So, in the **default setup**:

- If you want a projector to follow events live, those events should implement `ShouldPublish` (or you should customize the policy).
- If you only care about replay-driven projections, events do **not** need to be publishable.

---

## Custom policies

You can swap the publication policy by binding your own implementation in `config/pillar.php`:

```php
'publication_policy' => [
    'class' => \App\Infrastructure\MyPublicationPolicy::class,
    'options' => [/* … */],
],
```

Your policy needs to implement the `PublicationPolicy` interface:

```php
use Pillar\Event\PublicationPolicy;
use Pillar\Event\EventContext;

final class MyPublicationPolicy implements PublicationPolicy
{
    public function shouldPublish(object $event): bool
    {
        if (EventContext::isReplaying()) {
            return false;
        }

        // Custom rules here…
    }
}
```

### Example: publish all events (async)

For small/simple systems, you may want every event to go to the outbox:

```php
final class PublishAllEventsPolicy implements PublicationPolicy
{
    public function shouldPublish(object $event): bool
    {
        return !EventContext::isReplaying();
    }
}
```

This makes all events visible to projectors and other listeners in the live flow. Be mindful that this increases bus chatter and handler load.

### Example: projector-aware policy

A more nuanced option is to publish:

- all events that explicitly implement `ShouldPublish`, **and**
- any event class that has a registered `Projector` listener.

Conceptually:

```php
use Pillar\Event\PublicationPolicy;
use Pillar\Event\EventContext;
use Pillar\Event\EventListenerRegistry;

final class ProjectorAwarePublicationPolicy implements PublicationPolicy
{
    public function __construct(
        private EventListenerRegistry $listeners,
    ) {}

    public function shouldPublish(object $event): bool
    {
        if (EventContext::isReplaying()) {
            return false;
        }

        if ($event instanceof ShouldPublish) {
            return true;
        }

        return $this->listeners->hasProjectorFor($event::class);
    }
}
```

This keeps side-effectful listeners explicit (they still require `ShouldPublish`) while ensuring that projectors always follow the live stream for their event types.

---

## Summary

- **All events** are persisted; the publication policy only decides which ones go to the **outbox**.
- **Inline publication** (`ShouldPublishInline`) bypasses the policy and is controlled only by the marker interface and replay flags.
- **Projectors**:
    - Always see events on **replay** (driven directly by the replayer).
    - See events in the **live flow** only if those events are publishable under your `PublicationPolicy`.
- You can customize the policy to fit your system:
    - keep the default (marker-based, explicit),
    - publish all,
    - or use more advanced rules (e.g. projector-aware).

For details on events, projectors, outbox, and context, see:

- [Events](../concepts/events.md)
- [Projectors](../concepts/projectors.md)
- [Outbox](../concepts/outbox.md)
- [Event upcasters](../concepts/event-upcasters.md)
- [Versioned Events](../concepts/versioned-events.md)
