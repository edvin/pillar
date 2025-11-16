## 🧠 Aggregate Sessions

**AggregateSession** is your command handler’s “unit of work.”

It loads aggregates, tracks any domain events they emit, and commits everything atomically at the end of the command.
You write straightforward domain code; the session coordinates snapshots, streaming reads, and persistence behind the scenes.

### Why sessions matter

When handling a command, you want to focus on **domain behavior**, not persistence mechanics.  
`AggregateSession` wraps the full lifecycle needed to make that happen:

- It guarantees **one consistent view** of each aggregate within a command.
- It performs **all writes atomically**, so either all recorded events are committed or none are.
- It integrates seamlessly with:
  - the [Event Store](/concepts/event-store)
  - [Repositories](/concepts/repositories)
  - [Fetch Strategies](/concepts/fetch-strategies)
  - [Snapshotting](/concepts/snapshotting)
- It ensures concurrency issues surface as **domain‑meaningful exceptions**, not low‑level DB errors.

Think of it as your “command-scoped mini Unit of Work,” purpose‑built for event‑sourced aggregates.

---

### What the session does

- **Loads aggregates** (see [Aggregate Roots](/concepts/aggregate-roots)) via the configured repository & fetch strategy.
- **Tracks changes** by collecting recorded domain events from any aggregates you touch.
- **Debounces repeated loads** of the same aggregate within the same command.
- **Commits atomically** — one transaction that appends events and triggers snapshotting if the policy says so.
- **Surfaces concurrency errors** (if optimistic locking is enabled in config) as clear exceptions on `commit()`.

> Under the hood the session uses the default repository (see
> [Repositories](/concepts/repositories)) which in turn talks to the
> [Event Store](/concepts/event-store) using the configured
> [Fetch Strategies](/concepts/fetch-strategies).

---

### Getting a session

You can obtain an `AggregateSession` in whichever way fits your app. Pillar does **not** force a certain project structure or way of working.

1) **Constructor injection (recommended for handlers)**

```php
use Pillar\Aggregate\AggregateSession;
use Context\Document\Domain\Identifier\DocumentId;

final class RenameDocumentHandler
{
    public function __construct(private AggregateSession $session) {}

    public function __invoke(RenameDocumentCommand $command): void
    {
        $document = $this->session->find(DocumentId::from($command->id));
        $document->rename($command->newTitle);

        $this->session->commit();
    }
}
```

2) **Method injection** (Laravel will resolve it per-invocation)

```php
use Pillar\Aggregate\AggregateSession;

final class RenameDocumentHandler
{
    public function __invoke(RenameDocumentCommand $command, AggregateSession $session): void
    {
        $doc = $session->find(DocumentId::from($command->id));
        $doc->rename($command->newTitle);

        $session->commit();
    }
}
```

3) **Resolve on the fly** (for scripts / ad‑hoc usage)

```php
$session = app(\Pillar\Aggregate\AggregateSession::class);
```

4) **Pillar Facade (optional)**  
If you prefer facades and have the Pillar facade enabled in your app, you can do:

```php
use Pillar; // or use Pillar\Support\Facades\Pillar;

Pillar::session()
    ->find(DocumentId::from($id))
    ->rename('New title');

Pillar::session()->commit();
```

> Sessions are lightweight and scoped to a single command.  
> You should *not* reuse a session across multiple commands or background jobs.

> Choose what fits your style. Constructor injection keeps things explicit and testable.

---

### Typical flow in a handler

```php
// Load → call domain behavior → commit
$invoice = $session->find(InvoiceId::from($cmd->id));
$invoice->addLine($cmd->sku, $cmd->qty);
$invoice->finalize();

$session->commit();
```

This pattern is universal: **load → invoke domain behavior → commit**.

- You may load and modify **multiple aggregates** within the same session.
- Repeated calls to `find()` for the same ID return the **same instance** (identity map).
- If **optimistic locking** is enabled (`event_store.options.optimistic_locking = true`), a concurrent writer will cause a `ConcurrencyException` on commit.

---

### Notes on creation & snapshots

- **Creating new aggregates**: instantiate your aggregate (or use a named constructor), call domain methods to record events, then `commit()`. The session detects new aggregates automatically—no need to register them manually.
- Snapshotting is automatic and policy‑driven. See [Snapshotting](/concepts/snapshotting).

---

### Queries and tools

Aggregate sessions are meant for **commands** (write side). For read side:

- Use your read models directly in query handlers.
- For tooling (timelines, inspectors) you can access the [Event Store](/concepts/event-store) directly and leverage [Fetch Strategies](/concepts/fetch-strategies) and `EventWindow` to stream events “as of” a point in time.

> Sessions are intentionally **write‑side only**. They are not meant for queries, projections, or analytics.

---

### Pillar doesn’t force a structure

Pillar ships sensible defaults (e.g., context registries, a default repository), but **you keep control** over folder layout, handler wiring, and how commands/queries are organized. The session fits into whatever structure you prefer.

---

### See also

- [Aggregate Roots](/concepts/aggregate-roots)
- [Repositories](/concepts/repositories)
- [Event Store](/concepts/event-store)
- [Fetch Strategies](/concepts/fetch-strategies)
- [Snapshotting](/concepts/snapshotting)