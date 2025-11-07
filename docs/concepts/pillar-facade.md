## 🧰 Pillar Facade

Prefer dependency injection for core domain code, but the `Pillar` facade is a handy convenience in application code,
console commands, and tests.

**Methods**

- `Pillar::session(): AggregateSession` — get a fresh unit-of-work session
- `Pillar::dispatch(object $command): void` — forward to the Command Bus
- `Pillar::ask(object $query): mixed` — forward to the Query Bus

**Example**

```php
use Pillar\Facade\Pillar;

$session = Pillar::session();

// Dispatch a command
Pillar::dispatch(new CreateDocumentCommand($id, $title, $authorId));

// Ask a query
$document = Pillar::ask(new FindDocumentQuery($id));
```

---