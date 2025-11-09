## 🧠 Aggregate Sessions

**AggregateSession** is your command handler’s “unit of work.”

It loads aggregates, tracks any domain events they emit, and commits everything atomically at the end of the command.
You write straightforward domain code; the session coordinates snapshots, streaming reads, and persistence behind the scenes.

---

### What the session does

- **Loads aggregates** via the configured repository & fetch strategy.
- **Tracks changes** by collecting recorded domain events from any aggregates you touch.
- **Debounces repeated loads** of the same aggregate within the same command.
- **Commits atomically** — one transaction that appends events and triggers snapshotting if the policy says so.
- **Surfaces concurrency errors** (if optimistic locking is enabled in config) as clear exceptions on `commit()`.

> Under the hood the session uses the default repository (see
> [Repositories](/concepts/repositories)) which in turn talks to the
> [Event Store](/event-store/) using the configured
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

- You may load and modify **multiple aggregates** within the same session.
- Repeated calls to `find()` for the same ID return the **same instance** (identity map).
- If **optimistic locking** is enabled (`event_store.options.optimistic_locking = true`), a concurrent writer will cause a `ConcurrencyException` on commit.

---

### Notes on creation & snapshots

- Creating new aggregates is straightforward: construct your aggregate (or call a named constructor), perform behavior, then `commit()`. The repository will append recorded events and snapshot if the policy says so.
- Snapshotting is automatic and policy‑driven. See [Snapshotting](/concepts/snapshotting).

---

### Queries and tools

Aggregate sessions are meant for **commands** (write side). For read side:

- Use your read models directly in query handlers.
- For tooling (timelines, inspectors) you can access the [Event Store](/event-store/) directly and leverage [Fetch Strategies](/concepts/fetch-strategies) and `EventWindow` to stream events “as of” a point in time.

---

### Pillar doesn’t force a structure

Pillar ships sensible defaults (e.g., context registries, a default repository), but **you keep control** over folder layout, handler wiring, and how commands/queries are organized. The session fits into whatever structure you prefer.

---

### See also

- [Aggregate Roots](/concepts/aggregate-roots)
- [Repositories](/concepts/repositories)
- [Event Store](/event-store/)
- [Fetch Strategies](/concepts/fetch-strategies)
- [Snapshotting](/concepts/snapshotting)