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

#### EventContext and metadata

When an event is being **upcast or replayed**, the **payload** that your upcaster or handler receives is the serialized body of the event at a given version. Pillar initializes the [`EventContext`](../concepts/events.md#event-context-timestamps-correlation-ids-replay-flags) from the stored row before upcasting and rehydration, so you can inspect timestamps and correlation IDs without changing the payload itself.

During upcasting or replay you can read:

- `EventContext::occurredAt()` — the original UTC timestamp when the event was recorded
- `EventContext::correlationId()` — the correlation id for the logical operation
- `EventContext::isReconstituting()` / `EventContext::isReplaying()` — to distinguish replay from live handling

In most cases, upcasters should remain **pure payload transforms** (only reshaping the array). When you truly need to
branch on metadata (for example, to handle a one-off legacy period differently), prefer using `EventContext` rather than
baking metadata into the payload itself.

**Tip:** If you refactor an event without changing its shape, you can simply bump the version and register a no-op
upcaster for documentation clarity.

---