## 🆔 Aggregate IDs

Aggregate IDs uniquely identify instances of aggregates within your domain. Pillar uses strongly-typed aggregate ID
classes to ensure type safety and clarity.

### Stream IDs

Every Aggregate ID **is also the stream ID** used by the event store.

Pillar derives the stream name directly from the ID’s string representation:

- `OrderId → "order-<uuid>"`
- `InvoiceId → "invoice-<uuid>"`
- `DocumentId → "document-<uuid>"`

This mapping is **automatic** and handled by `AggregateRegistry`.  
No separate “stream resolver” or configuration is needed.

#### Why this design?

- **Readable event streams** in the database and the UI  
  (`order-753e7c12-…` instead of opaque hashes)
- **Strong typing** inside the domain: you never confuse one aggregate’s ID for another
- **One canonical stream per aggregate instance**
- **Zero config**: renaming the ID class automatically renames the stream prefix
- **Partitioning‑friendly**: PostgreSQL and future sharding work naturally because `stream_id` is stable, deterministic, and prefix‑tagged

The only requirement for a custom ID class is that it extends `AggregateRootId` and implements `aggregateClass()`.

An aggregate ID is typically a value object implementing or extending `AggregateRootId`. These IDs are used to load,
save, and track aggregates within the event store and repositories through the `aggregateClass()` method.

Example of a simple aggregate ID class:

```php
use Pillar\Aggregate\AggregateRootId;

final readonly class DocumentId extends AggregateRootId
{
    public static function aggregateClass()
    {
        return Document::class;
    }
}
```

Aggregate IDs are used throughout Pillar APIs, including:

- Finding aggregates in an `AggregateSession`
- Appending events to the event store
- Checking aggregate existence in repositories

Using strongly-typed IDs helps prevent mixing different aggregate types and improves code readability.

---</file>