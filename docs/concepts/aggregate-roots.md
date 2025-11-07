## 🧩 Aggregate Roots

Aggregates are the **core building blocks** of your domain model — they encapsulate state and enforce invariants through
event-driven or state-driven updates.

In Pillar, all aggregates extend the abstract base class `AggregateRoot`, which provides a consistent pattern for *
*recording and applying domain events**, while still allowing simpler state-backed persistence when full event sourcing
isn’t needed.

---

### Example (Event-Sourced Aggregate)

```php
use Pillar\Aggregate\AggregateRoot;
use Pillar\Snapshot\Snapshottable;
use Context\Document\Domain\Event\DocumentCreated;
use Context\Document\Domain\Event\DocumentRenamed;
use Context\Document\Domain\Identifier\DocumentId;

final class Document extends AggregateRoot implements Snapshottable
{
    private DocumentId $id;
    private string $title;

    public static function create(DocumentId $id, string $title): self
    {
        $self = new self();
        $self->record(new DocumentCreated($id, $title));
        return $self;
    }

    public function rename(string $newTitle): void
    {
        if ($this->title === $newTitle) {
            return;
        }

        $this->record(new DocumentRenamed($this->id(), $newTitle));
    }

    protected function applyDocumentCreated(DocumentCreated $event): void
    {
        $this->id = $event->id;
        $this->title = $event->title;
    }

    protected function applyDocumentRenamed(DocumentRenamed $event): void
    {
        $this->title = $event->newTitle;
    }

    // Snapshottable
    public function toSnapshot(): array
    {
        return [
            'id' => (string) $this->id,
            'title' => $this->title,
        ];
    }

    public static function fromSnapshot(array $data): static
    {
        $self = new self();
        $self->id = DocumentId::from($data['id']);
        $self->title = $data['title'];
        return $self;
    }

    public function id(): DocumentId
    {
        return $this->id;
    }
}
```

This is the **event-sourced** approach — every state change is expressed as a **domain event**, persisted to the event
store, and used to rebuild the aggregate’s state later.

This model gives you:

- 🔍 **Full auditability** of all domain changes over time
- 🕰️ **Reproducibility** and replay capability
- ⚙️ **Resilience** against schema evolution with versioned events and upcasters

---

### Example (State-Based Aggregate)

For simpler domains, you can skip event sourcing entirely.
In that case, your repository can directly persist and retrieve aggregates from a storage backend (like Eloquent or a
document store).
You don’t record or apply events — you just mutate the state directly.

```php
use Context\Document\Domain\Identifier\DocumentId;
use Pillar\Aggregate\AggregateRoot;
use Pillar\Snapshot\Snapshottable;

final class Document extends AggregateRoot implements Snapshottable
{
    public function __construct(
        private DocumentId $id,
        private string $title
    ) {}

    public function rename(string $newTitle): void
    {
        $this->title = $newTitle;
    }

    // Snapshottable
    public function toSnapshot(): array
    {
        return [
            'id' => (string) $this->id,
            'title' => $this->title,
        ];
    }

    public static function fromSnapshot(array $data): static
    {
        return new self(DocumentId::from($data['id']), $data['title']);
    }

    public function id(): DocumentId
    {
        return $this->id;
    }
}
```

This **state-based** model is ideal for:

- 🧾 Aggregates that don’t require **audit trails** or **historical replay**
- ⚡ Domains that favor **direct persistence** over event sourcing
- 🧰 Use cases where you want the same aggregate behavior API but backed by a simpler repository

Both models work seamlessly with Pillar’s repository and session abstractions — you can mix and match them in the same
application.

---

### 🧠 Aggregate Lifecycle Overview

```mermaid
sequenceDiagram
    participant Client
    participant CommandHandler
    participant Aggregate
    participant Session
    participant Repository
    participant EventStore

    Client ->> CommandHandler: Send command
    CommandHandler ->> Session: find(AggregateId)
    Session ->> Repository: load(Aggregate)
    Repository ->> EventStore: load events
    EventStore -->> Repository: return events
    Repository -->> Session: reconstituted aggregate
    CommandHandler ->> Aggregate: execute method (rename())
    Aggregate ->> Aggregate: record(event)
    Aggregate ->> Session: releaseEvents()
    Session ->> Repository: save(Aggregate)
    Repository ->> EventStore: append events
    Session -->> Client: commit complete
```

*(For state-based aggregates, the “EventStore” step is replaced with a direct database update.)*

---