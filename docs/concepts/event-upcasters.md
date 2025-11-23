## 🧬 Event Upcasters

**Upcasters** allow you to evolve event schemas over time while keeping your historical event data valid.  
They transform old event payloads into their latest structure during deserialization — before your aggregates or
projectors ever see them.

:::info Related Reading
- [Events](../concepts/events.md)
- [Versioned Events](../concepts/versioned-events.md)
- [Serialization](../concepts/serialization.md)
- [Context Registries](../concepts/context-registries.md)
  :::

### Example

```php
use Pillar\Event\Upcaster;

final class DocumentCreatedV1ToV2Upcaster implements Upcaster
{
    public static function eventClass(): string
    {
        return DocumentCreated::class;
    }

    public static function fromVersion(): int
    {
        return 1; // upgrades v1 -> v2
    }

    public function upcast(array $payload): array
    {
        // Older events lacked a "created_by" field — set a default.
        $payload['created_by'] ??= 'system';
        return $payload;
    }
}
```

### Registration

Each upcaster is registered in its context's [`ContextRegistry`](../concepts/context-registries.md) using the `EventMapBuilder`:

```php
public function events(): EventMapBuilder
{
    return EventMapBuilder::create()
        ->event(DocumentCreated::class)
            ->alias('document_created')
            ->listeners([DocumentCreatedProjector::class])
            ->upcasters([DocumentCreatedV1ToV2Upcaster::class]);
}
```

### How It Works

- Upcasters are registered per bounded context via each context's `ContextRegistry`, then aggregated into the global
  **UpcasterRegistry** at boot time by the `ContextLoader`.
- When events are loaded from the event store, Pillar compares the stored `event_version` with the current version
  declared on the event class (see [Versioned Events](../concepts/versioned-events.md) below).
- If the stored version is lower, Pillar invokes all upcasters for that event class starting from `fromVersion()`,
  applying them sequentially (v1 → v2 → v3 → …) until the payload matches the current schema.
- Each upcaster declares the event class it handles (`eventClass()`) and the version it upgrades *from* via
  `fromVersion()` (e.g. a `fromVersion()` of 1 handles v1 → v2 transitions).

---

### ⚡ Optimized Serialization

Pillar's default `JsonObjectSerializer` automatically converts objects to and from JSON, using PHP reflection to
reconstruct event and command objects during deserialization.

To ensure high performance, **constructor parameter metadata is cached per class**. This avoids repeated reflection
calls on hot paths, keeping event and command deserialization fast even at larger scales.

When upcasters are involved, the serializer simply receives the **already-upcast payload** and hydrates the latest
version of the event class from it.

You can provide your own serializer by implementing the `ObjectSerializer` interface—for example, to integrate a
binary format or custom encoding strategy.

### ⏱ Event timing & correlation during upcasting

When events are rehydrated (whether live or during replay), Pillar also initializes the [`EventContext`](../concepts/events.md#event-context)
with the original metadata from storage:

- `EventContext::occurredAt()` — returns the UTC timestamp of **when the event actually happened**.
- `EventContext::correlationId()` — returns the logical operation ID spanning all events in the same flow.
- `EventContext::aggregateRootId()` — returns the typed `AggregateRootId` instance (e.g. `CustomerId`, `DocumentId`)
  when the stream can be resolved to a registered aggregate id class, or `null` otherwise.
- `EventContext::isReconstituting()` / `EventContext::isReplaying()` — let you detect replay vs. live handling.

For convenience in handlers and projectors, you can use the `Pillar\Event\UsesEventContext` trait, which exposes:

- `aggregateRootId()` — typed aggregate id from the current `EventContext`.
- `aggregateRootIdAs(string $idClass)` — safely cast the id to a specific `AggregateRootId` subclass.
- `correlationId()` and `occurredAt()` — thin wrappers around the corresponding `EventContext` accessors.

This means that even for **old events that have been upcasted** to a newer schema, your aggregates and projectors can
still:

- see the true historical time the event occurred,
- attach diagnostics or logs to the same correlation ID that was present when the event was first recorded, and
- easily correlate work to the aggregate instance that produced the event, when that information is available.

Upcasting transforms the **shape** of the payload; `EventContext` (and `UsesEventContext`) keep the **when**, **who**,
and **why** intact.

---

### 🧩 Versioned Events

Pillar supports **versioned domain events** to make schema evolution explicit and safe.

Implement `VersionedEvent` on your event and declare its current schema version:

```php
use Pillar\Event\VersionedEvent;

final class DocumentCreated implements VersionedEvent
{
    public static function version(): int
    {
        return 2;
    }

    public function __construct(
        public string $title,
        public string $created_by
    ) {}
}
```

- The event’s version is stored in the event store (`event_version` column) alongside its payload.
- On load, if a stored event has an **older version**, Pillar applies registered upcasters step-by-step until the
  payload reaches the event’s current version.
- You can register **multiple upcasters** for the same event (e.g. v1→v2, v2→v3). They are applied in ascending order of
  `fromVersion()`.

**Tip:** If you refactor an event without changing its shape, you can simply bump the version and register a no-op
upcaster for documentation clarity.

---

### 📚 Related Reading
- [Events](../concepts/events.md)
- [Versioned Events](../concepts/versioned-events.md)
- [Serialization](../concepts/serialization.md)
- [Context Registries](../concepts/context-registries.md)
