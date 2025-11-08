
# 🧩 Commands & Queries

Pillar keeps orchestration simple with **two buses**:
- **Command Bus** — mutate state (tell the system to *do* something).
- **Query Bus** — read state (ask the system to *tell* you something).

This page shows how to model commands and queries in a clean, testable way using Pillar.

---

## Concepts

### Commands
- Represent **intent to change** the system.
- Are **imperative**: “RenameDocument”, “PublishInvoice”.
- Are handled by a **single** handler.
- **May return a value** (e.g., a generated ID, a summary DTO, or metadata). Keep return payloads minimal and avoid coupling to read-model shapes.

### Queries
- Represent **requests for information**.
- Are **side‑effect free** (no writes).
- **Return** a value (DTO/array/etc.).
- May be cached and composed freely.

> **Rule of thumb:** If your primary goal is to change state, it’s a **command** (it can still return something). If your primary goal is to get data, it’s a **query**.

---

## Minimal Examples

### Command

```php
final class RenameDocumentCommand
{
    public function __construct(public string $id, public string $newTitle) {}
}

final class RenameDocumentHandler
{
    public function __construct(private \Pillar\Aggregate\AggregateSession $session) {}

    public function __invoke(RenameDocumentCommand $c): void
    {
        $doc = $this->session->find(\Context\Document\Domain\Identifier\DocumentId::from($c->id));
        $doc->rename($c->newTitle);
        $this->session->commit(); // persist + publish events
    }
}
```

### Command that returns a value

```php
final class CreateDocumentCommand
{
    public function __construct(public string $title) {}
}

final class CreateDocumentHandler
{
    public function __construct(private \Pillar\Aggregate\AggregateSession $session) {}

    public function __invoke(CreateDocumentCommand $c): string
    {
        $id = \Context\Document\Domain\Identifier\DocumentId::new();
        $doc = Document::create($id, $c->title);

        $this->session->store($doc);
        $this->session->commit();

        return (string)$id; // small, useful payload (e.g., new ID)
    }
}
```

### Query

```php
final class FindDocumentQuery
{
    public function __construct(public string $id) {}
}

final class FindDocumentHandler
{
    public function __invoke(FindDocumentQuery $q): array
    {
        // Return a read-optimized shape (DTO/array). No side effects.
        return ['id' => $q->id, 'title' => '...'];
    }
}
```

### Facade

```php
use Pillar\Facade\Pillar;

Pillar::dispatch(new RenameDocumentCommand($id, 'New Title'));
$createdId = Pillar::dispatch(new CreateDocumentCommand('First Draft'));
$result = Pillar::ask(new FindDocumentQuery($id));
```

---

## When to Use What

| Scenario | Command | Query |
|---|---|---|
| Rename a document | ✅ |  |
| Generate a PDF file and store it | ✅ |  |
| Check whether a document title exists |  | ✅ |
| Build an autocomplete list |  | ✅ |
| “Create then fetch the full read‑model” | ✅ (may return ID) **then** ✅ query |

> Prefer **small return values** from commands (IDs, lightweight summaries). Use queries for richer read shapes and consumer‑specific projections.

---

## Handler Signatures

A handler is a single‑method invokable class:

```php
/** @psalm-immutable */
final class DoThing { /* public readonly ctor params */ }

final class DoThingHandler
{
    public function __invoke(DoThing $cmd): void { /* ... */ }
}
```

Commands **may** return a value when useful:

```php
final class CreateThing { /* ... */ }

final class CreateThingHandler
{
    public function __invoke(CreateThing $cmd): string
    {
        // create + commit ...
        return 'new-id';
    }
}
```

Queries return a type:

```php
/** @psalm-immutable */
final class GetThing { /* ... */ }

final class GetThingHandler
{
    public function __invoke(GetThing $q): ThingDto { /* ... */ }
}
```

### Why “one message → one handler”?
- Clear ownership and flow.
- Easy to trace in logs.
- Predictable performance & retries.

---

## Aggregate Session (Writes)

Pillar’s `AggregateSession` scopes loading and committing aggregates. Typical flow:

1. Load by ID: `find(DocumentId::from($id))`
2. Call behavior on the aggregate: `$doc->rename(...)`
3. `commit()` to persist and publish domain events.

```php
final class RenameDocumentHandler
{
    public function __construct(private \Pillar\Aggregate\AggregateSession $session) {}

    public function __invoke(RenameDocumentCommand $c): void
    {
        $doc = $this->session->find(\Context\Document\Domain\Identifier\DocumentId::from($c->id));
        $doc->rename($c->newTitle);
        $this->session->commit();
    }
}
```

> **Tip:** keep handlers thin — orchestration only. Domain rules live on the aggregate/entity.

---

## Validation

Prefer validating **closer to the domain**. A pragmatic split:

- **Syntactic validation** (shape/format): before dispatch (controllers/forms).
- **Semantic validation** (business rules): inside the aggregate method. Throw domain exceptions (e.g., `TitleNotUnique`).

```php
final class Document
{
    public function rename(string $newTitle): void
    {
        if ($newTitle === $this->title) {
            return; // idempotent
        }
        if ($newTitle === '') {
            throw new \DomainException('Title cannot be empty.');
        }
        $this->title = $newTitle;
        // record DomainEvent: TitleChanged(...)
    }
}
```

---

## Idempotency & Retries

- Make commands safe to **retry**.
- Guard against double‑writes (check existing state, use unique constraints, or use application‑level idempotency keys).
- Handlers should be deterministic with the same inputs.

---

## Transactions

Wrap write handlers in a transaction boundary (library/middleware or framework feature). The general order:

1. Start transaction
2. Load aggregate(s)
3. Execute behavior
4. Commit changes
5. Publish events
6. End transaction

> If you publish integration events, use the **outbox** pattern to guarantee at‑least‑once delivery after the DB commit.

---

## Queries: Shaping the Read Model

- **Never** call `commit()` or modify state.
- Return DTOs that match the consumer’s need (flattened, denormalized).
- Add pagination, filtering, and sorting as first‑class parameters.

```php
final class SearchDocumentsQuery
{
    public function __construct(
        public string $term,
        public int $page = 1,
        public int $perPage = 25,
        public ?string $orderBy = 'title',
        public string $direction = 'asc',
    ) {}
}
```

```php
final class SearchDocumentsHandler
{
    public function __invoke(SearchDocumentsQuery $q): array
    {
        // Use your favorite read model store (SQL/ES/Doc DB). No side effects.
        return [
            'items' => [/* ... */],
            'page' => $q->page,
            'perPage' => $q->perPage,
            'total' => 123,
        ];
    }
}
```

---

## Middleware (Cross‑cutting concerns)

Both buses can run middleware around handlers for concerns like:

- Logging & tracing
- Authorization
- Validation
- Caching (queries only)
- Rate limits / throttling
- Circuit breakers
- Metrics

Example (pseudo‑registration):

```php
$commandBus->pipe(new TransactionMiddleware($connection));
$commandBus->pipe(new LoggingMiddleware($logger));
$queryBus->pipe(new CachingMiddleware($cache, ttl: 60));
$queryBus->pipe(new LoggingMiddleware($logger));
```

---

## Authorization

- **Commands:** assert permissions before the behavior or within it.
- **Queries:** reject early or tailor the projection to the caller.

```php
final class RenameDocumentHandler
{
    public function __construct(
        private \Pillar\Aggregate\AggregateSession $session,
        private Can $can, // your policy/permission service
    ) {}

    public function __invoke(RenameDocumentCommand $c): void
    {
        $this->can->assert('document.rename', $c->id);
        $doc = $this->session->find(DocumentId::from($c->id));
        $doc->rename($c->newTitle);
        $this->session->commit();
    }
}
```

---

## Testing

### Unit test the aggregate behavior

```php
it('renames the document', function () {
    $doc = Document::create(DocumentId::new(), 'Old');
    $doc->rename('New');
    expect($doc->title())->toBe('New');
});
```

### Handler tests

Use fakes for storage/session or an in‑memory session to assert orchestration.

```php
it('dispatches rename and commits', function () {
    $session = new InMemoryAggregateSession(/* ... */);
    $handler = new RenameDocumentHandler($session);

    $handler(new RenameDocumentCommand('doc-1', 'New Title'));

    // assert session stored doc and commit called
});
```

### End‑to‑end (optional)

- Hit the bus through your framework boundary.
- Assert DB + outbox + projections are correct.

---

## Error Handling

- Throw **domain exceptions** for business rule violations.
- Translate to transport‑level errors at the boundary (HTTP 400/403/...).
- Use retries for transient infra errors; not for logical domain errors.

```php
try {
    Pillar::dispatch(new RenameDocumentCommand($id, 'New Title'));
} catch (TitleNotUnique $e) {
    // 422 Unprocessable Entity
}
```

---

## Conventions & Tips

- Command/Query names use **imperatives**: `RenameDocument`, `PublishInvoice`.
- Message classes are **small immutable value objects** (public readonly ctor params).
- Handlers are **stateless**; inject services via constructor.
- Keep command return values **small** (IDs, metadata). Use queries for rich projections.
- Prefer DTOs in queries, not entities from your domain model.
- Keep bus registration and middleware explicit and visible.

---

## Anti‑patterns

- **CQRS by habit**: Don’t split reads/writes if your app is simple. Pillar works fine for CRUD too.
- **God handlers**: Orchestrate only; move rules into aggregates/services.
- **Queries that write**: Breaks caching and surprises callers.

---

## Putting It Together (controller example)

```php
final class DocumentController
{
    public function rename(string $id, Request $r): Response
    {
        Pillar::dispatch(new RenameDocumentCommand($id, $r->string('title')));
        $dto = Pillar::ask(new FindDocumentQuery($id));

        return new JsonResponse($dto, 200);
    }
}
```

---

## FAQ

**Q: Can a command handler dispatch a query?**  
A: Yes, for lookups that don’t belong in the aggregate itself. Keep the handler orchestration‑only.

**Q: Can a query call another query?**  
A: Yes; they’re read‑only and composable. Prefer small building blocks.

**Q: Can a command publish events?**  
A: The **aggregate** records events; the session persists and publishes them on `commit()` (optionally through an outbox).

---

## See also
- Aggregates & Sessions
- Events & Outbox
- Testing guide
- Middleware cookbook
