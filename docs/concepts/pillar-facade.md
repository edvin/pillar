## 🧰 Pillar Facade

Prefer dependency injection for core domain code, but the `Pillar` facade is a handy convenience in application code,
console commands, and tests.

While the facade gives you quick access to core Pillar services, it is **not** intended for deep domain logic.  
Your aggregates, projectors, and domain services should instead depend on the underlying abstractions directly:
- See [Aggregate Sessions](../concepts/aggregate-sessions.md)  
- See [Commands & Queries](../concepts/commands-and-queries.md)  
- See [CQRS](../concepts/cqrs.md)

**Methods**

- `Pillar::session(): AggregateSession` — returns a fresh [Aggregate Session](../concepts/aggregate-sessions.md) for loading and committing aggregates.
- `Pillar::dispatch(object $command): void` — dispatches a command through the registered [Command Bus](../concepts/commands-and-queries.md#commands).
- `Pillar::ask(object $query): mixed` — executes a query through the [Query Bus](../concepts/commands-and-queries.md#queries).

### Usage

Below is a typical application-layer example—calling Pillar from controllers, console commands,
or other framework-facing code.

**Example**

```php
use Pillar\Facade\Pillar;

$session = Pillar::session();

// Dispatch a command
Pillar::dispatch(new CreateDocumentCommand($id, $title, $authorId));

// Ask a query
$document = Pillar::ask(new FindDocumentQuery($id));
```

> 📝 **Note:**  
> The facade is intentionally thin. It does not expose low-level internals and it never bypasses
> the session/command/query patterns that keep your domain model consistent.