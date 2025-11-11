# CQRS

**Command Query Responsibility Segregation (CQRS)** is a simple idea: _writes_ (commands) and _reads_ (queries) take different paths. In Pillar, your **write side** is an event‑sourced aggregate; your **read side** is one or more fast, purpose‑built projections that you query directly.

Why this helps:

- **Speed & scale** — Reads hit denormalized tables or views designed for the UI/API.
- **Autonomy** — Read models evolve independently from your aggregate’s internal shape.
- **Simple SQL** — Queries become straightforward SELECTs, with proper indexes.
- **Safe writes** — Aggregates stay focused on invariants and domain logic.

---

## The read side: Projectors → Read models

A **Projector** listens to stored events and updates a read model (usually normal tables in your app DB). Projectors must be:

- **Idempotent** — running them again produces the same result.
- **Side‑effect free** — they write only to read models (no external calls).
- **Deterministic** — derived only from the event payload + metadata.

You can rebuild read models at any time with:

```bash
php artisan pillar:replay-events
```

See **[CLI: Replay events](/reference/cli-replay)** for scoping, dry‑run, and windowing via global sequence or UTC time.

For background, see **[Projectors](/concepts/projectors)**.

---

## The write side: Aggregates stay pure

Your aggregates accept commands, enforce invariants, and produce events. You almost never query aggregates directly for read endpoints in production. Instead, the UI/API reads from the projection tables your projectors maintain.

Under the hood, when you _do_ rehydrate aggregates (e.g. in a command handler), Pillar streams events using your configured **[Fetch Strategies](/concepts/fetch-strategies)**.

---

## Queries: DTOs + Handlers

A **Query** is a small immutable DTO describing what you want. A **Query Handler** executes the SQL (or Eloquent) against your read model and returns data (DTO/array).

**Query DTO**

```php
namespace App\Billing\Application\Query;

final class GetInvoiceSummary
{
    public function __construct(
        public readonly string $invoiceId,
    ) {}
}
```

**Handler**

```php
namespace App\Billing\Application\Handler\Query;

use App\Billing\Application\Query\GetInvoiceSummary;
use Illuminate\Support\Facades\DB;

final class GetInvoiceSummaryHandler
{
    public function __invoke(GetInvoiceSummary $q): array
    {
        // Read models are just normal tables optimized for queries
        $row = DB::table('invoice_summaries')
            ->where('invoice_id', $q->invoiceId)
            ->first();

        if (!$row) {
            return ['found' => false];
        }

        return [
            'found'        => true,
            'invoice_id'   => $row->invoice_id,
            'customer'     => $row->customer_name,
            'total'        => $row->total,
            'status'       => $row->status,
            'issued_at'    => $row->issued_at,
            'last_updated' => $row->updated_at,
        ];
    }
}
```

> Register your handler in your **Context Registry** so Pillar can wire it (see your context registry docs / examples).

---

## Dispatching queries

You can **inject** the `QueryBusInterface` or use the **Pillar facade**.

**Via injected bus**

```php
use Pillar\Bus\QueryBusInterface;
use App\Billing\Application\Query\GetInvoiceSummary;

final class InvoiceController
{
    public function __construct(private QueryBusInterface $queries) {}

    public function show(string $id)
    {
        $summary = $this->queries->ask(new GetInvoiceSummary($id));
        // return JSON / view
    }
}
```

**Via facade**

```php
use Pillar\Facade\Pillar;
use App\Billing\Application\Query\GetInvoiceSummary;

$summary = Pillar::ask(new GetInvoiceSummary($id));
```

Both routes call the same underlying bus. Choose whichever suits your wiring style.

---

## Skipping projections (sometimes fine)

For small features or admin tooling, you can read by **rehydrating** an aggregate and computing a result in memory. That’s OK when:

- Result volume is tiny and doesn’t need filtering/pagination.
- Latency isn’t critical.
- You won’t run it for lists across many aggregates.

As you scale, the projection approach wins on:

- **Performance** (simple indexed SQL)
- **Operational safety** (queries don’t touch aggregate internals)
- **Flexibility** (new views without touching the write model)

---

## How Pillar ties it together

- **AggregateSession** uses your configured **[Fetch Strategies](/concepts/fetch-strategies)** to stream events when rehydrating aggregates. See **[Aggregate Sessions](/concepts/aggregate-sessions)**.
- **EventReplayer** also uses fetch strategies and **[Event Windows](/event-store/)** for efficient, scoped replays.
- The **Stream Browser UI** inspects events and can “time travel” an aggregate using the same windowing. See **[Stream Browser](/ui/stream-browser)**.

---

## Practical guidance

- **Return DTOs/arrays**, not models. Keep query results serializable.
- **Paginate** list queries; projectors should maintain sortable columns (e.g., `created_at`, `last_event_seq`).
- **Index** read models for the WHERE/ORDER BY you actually use.
- **Security/tenancy** belongs in query handlers (e.g., `where('tenant_id', $tenant)`).
- **Idempotency** in projectors: upsert on primary keys derived from aggregate id + domain keys.
- **Eventual consistency** — your read side trails a bit behind writes. Design UX accordingly.

---

## Putting it together

1. **Write**: implement/extend projectors to keep the read model you need.
2. **Read**: create a Query + Handler over that read model.
3. **Wire**: register the handler in your Context Registry.
4. **Use**: call `QueryBusInterface::ask()` or `Pillar::ask()`.
5. **Rebuild** (when needed): `pillar:replay-events` to populate or repair.

---

## See also

- **[Aggregate Sessions](/concepts/aggregate-sessions)**
- **[Projectors](/concepts/projectors)**
- **[Fetch Strategies](/concepts/fetch-strategies)**
- **[Event Store](/event-store/)**
- **[CLI: Replay events](/reference/cli-replay)**