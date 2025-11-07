## 🧱 Repositories

Repositories are resolved dynamically via the configuration.

The default repository type is the `EventStoreRepository`, but you can override this per aggregate class:

```php
'repositories' => [
    'default' => Pillar\Repository\EventStoreRepository::class,
    Context\DocumentHandling\Domain\Aggregate\Document::class => Context\DocumentHandling\Infrastructure\Repository\DocumentRepository::class,
],
```

This makes it trivial to store some aggregates in a database and others via event sourcing.

To implement custom persistence for your aggregate, implement the `AggregateRepository` interface and register it here.
The repository returns a `LoadedAggregate` DTO containing the aggregate and metadata so the session can enforce
optimistic concurrency without extra queries.

```php
final class LoadedAggregate
{
    public function __construct(
        public readonly AggregateRoot $aggregate,
        public readonly int $version,
    ) {}
}

interface AggregateRepository
{
    public function find(AggregateRootId $id): ?LoadedAggregate;

    public function save(AggregateRoot $aggregate, ?int $expectedVersion = null): void;
}
```

---