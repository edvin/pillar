# Getting started

::: tip
Fast path: install → migrate → create one tiny aggregate → persist it with a session. No buses, no registries. The full tutorial adds commands, aliases, and projectors.
:::

## 🧩 Installation

```bash
composer create-project laravel/laravel myproject
cd myproject
composer require pillar/pillar
```

Pillar registers its service provider via Laravel package discovery.

Publish migrations & config, then migrate:

```bash
php artisan pillar:install
php artisan migrate
```

You’ll get:

| File                                                                        | Description                                                             |
|-----------------------------------------------------------------------------|-------------------------------------------------------------------------|
| `database/migrations/YYYY_MM_DD_HHMMSS_create_events_table.php`             | Stores domain events                                                    |
| `database/migrations/YYYY_MM_DD_HHMMSS_create_aggregate_versions_table.php` | Tracks per‑aggregate versions (for optimistic concurrency & sequencing) |
| `config/pillar.php`                                                         | Configure repositories, event store, serializer (+ optional encryption) |

---

## ✅ Hello Pillar

We’ll create a minimal **Document** aggregate with a single event and persist it using an **AggregateSession**. This keeps the first run simple; the tutorial adds command/query buses and registries later.

### 1) ID value object
```php
// app/Context/Document/Domain/Identifier/DocumentId.php
use Pillar\Aggregate\AggregateRootId;

final readonly class DocumentId extends AggregateRootId
{
    public static function aggregateClass(): string
    {
        return Document::class;
    }
}
```

### 2) Event
```php
// app/Context/Document/Domain/Event/DocumentCreated.php
use App\Context\Document\Domain\Identifier\DocumentId;

final class DocumentCreated
{
    public function __construct(
        public DocumentId $id,
        public string $title,
    ) {}
}
```

### 3) Aggregate
```php
// app/Context/Document/Domain/Aggregate/Document.php
use Pillar\Aggregate\AggregateRoot;
use App\Context\Document\Domain\Event\DocumentCreated;
use App\Context\Document\Domain\Identifier\DocumentId;

final class Document extends AggregateRoot
{
    private DocumentId $id;
    private string $title;

    public static function create(DocumentId $id, string $title): self
    {
        $self = new self();
        $self->record(new DocumentCreated($id, $title));
        return $self;
    }

    protected function applyDocumentCreated(DocumentCreated $e): void
    {
        $this->id = $e->id;
        $this->title = $e->title;
    }

    public function id(): DocumentId { return $this->id; }
}
```

### 4) Persist once to prove it works
```php
// routes/web.php
use Pillar\Facade\Pillar;
use App\Context\Document\Domain\Identifier\DocumentId;
use App\Context\Document\Domain\Aggregate\Document;

Route::get('/pillar-hello', function () {
    $id = DocumentId::new();
    $doc = Document::create($id, 'Hello Pillar');

    Pillar::session()->attach($doc)->commit();

    return 'OK: ' . (string) $id;
});
```
Visit `/pillar-hello`, then check the `events` table — you’ll see a `DocumentCreated` row for your aggregate ID.

---

## Where to next

- Add **commands & handlers**, aliases and projectors → [/tutorials/build-a-document-service](/tutorials/build-a-document-service)
- Learn the **Aggregate session** lifecycle → [/concepts/aggregate-sessions](/concepts/aggregate-sessions)
- Configure the **Event store** (fetch strategies, stream resolver) → [/event-store](/event-store/)
- Optional: enable **payload encryption** → [/concepts/serialization#payload-encryption](/concepts/serialization#payload-encryption)