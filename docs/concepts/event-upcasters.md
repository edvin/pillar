## 🧬 Event Upcasters

**Upcasters** allow you to evolve event schemas over time while keeping your historical event data valid.  
They transform old event payloads into their latest structure during deserialization — before your aggregates or
projectors ever see them.

This makes it safe to refactor your events or add new fields without rewriting your event store.

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

Each upcaster is registered in its `ContextRegistry` using the `EventMapBuilder`:

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

- Upcasters are registered globally in the **UpcasterRegistry** during application boot via the `ContextLoader`.
- When events are loaded from the event store, Pillar checks the stored event version and the current version declared
  by the event (see “Versioned Events” below).
- If the stored version is lower, Pillar applies upcasters sequentially (v1 → v2 → v3 → …) until the payload matches the
  current version.
- Each upcaster declares the event class it handles and the version it upgrades from via `fromVersion()`.

---

### ✅ Benefits

- Seamless **schema evolution** for persisted events
- Fully **backward compatible** without modifying existing data
- **Composable transformations** — multiple upcasters can chain together
- Zero impact on aggregate or projector code

---

### ⚡ Optimized Serialization

Pillar’s default `JsonObjectSerializer` automatically converts objects to and from JSON,
using PHP reflection to reconstruct event and command objects during deserialization.

To ensure high performance, **constructor parameter metadata is cached per class**.
This avoids repeated reflection calls on hot paths, making event and command deserialization
fast even at large scales.

You can provide your own serializer by implementing the `ObjectSerializer` interface —
for example, to integrate a binary format or custom encoding strategy.

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