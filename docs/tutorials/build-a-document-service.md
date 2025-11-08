# Tutorial — Build a Document service

In ~30–45 minutes you'll:

1. Install Pillar and publish migrations/config.
2. Model a simple `Document` aggregate with `create` and `rename`.
3. Wire a command handler using the `AggregateSession`.
4. Register a `ContextRegistry` with event aliases and a projector.
5. Run and verify replays safely.

You can follow along inside an existing Laravel project or a fresh one.

## 1) Install Pillar

The first command installs the package, the second publishes migrations and `config/pillar.php`, and the third applies the tables. The installer is interactive so you can choose what to publish; for this tutorial, publish both migrations and config.

```bash
composer require pillar/pillar
php artisan pillar:install
php artisan migrate
```

You now have `config/pillar.php` and migrations for the `events` and `aggregate_versions` tables should be run.

## 2) Create the aggregate and events

We’ll model a simple `Document` aggregate. Aggregates record domain events to express state changes; Pillar persists those events and replays them to rebuild state. IDs are strongly-typed value objects (extending `AggregateRootId`).

Create the ID and aggregate:

```php
// app/Context/Document/Domain/Identifier/DocumentId.php
use Pillar\Aggregate\AggregateRootId;
use App\Context\Document\Domain\Aggregate\Document;

final readonly class DocumentId extends AggregateRootId
{
    public static function aggregateClass()
    {
        return Document::class;
    }
}
```

```php
// app/Context/Document/Domain/Aggregate/Document.php
use Pillar\Aggregate\AggregateRoot;
use App\Context\Document\Domain\Event\DocumentCreated;
use App\Context\Document\Domain\Event\DocumentRenamed;
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

    public function rename(string $newTitle): void
    {
        if ($this->title === $newTitle) {
            return;
        }
        $this->record(new DocumentRenamed($this->id, $newTitle));
    }

    protected function applyDocumentCreated(DocumentCreated $e): void
    {
        $this->id = $e->id;
        $this->title = $e->title;
    }

    protected function applyDocumentRenamed(DocumentRenamed $e): void
    {
        $this->title = $e->newTitle;
    }

    public function id(): DocumentId { return $this->id; }
}
```
> Note: We keep `DocumentId` in the event payload. Pillar’s serializer reconstructs value objects during deserialization, so you don’t have to downcast to strings.

## 3) Create the events:

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

```php
// app/Context/Document/Domain/Event/DocumentRenamed.php
use App\Context\Document\Domain\Identifier\DocumentId;

final class DocumentRenamed
{
    public function __construct(
        public DocumentId $id,
        public string $newTitle,
    ) {}
}
```

## 3) Commands and handler

Commands capture intent (`CreateDocument`, `RenameDocument`). Handlers use an `AggregateSession` (a Unit of Work) to load the aggregate, call a method, and `commit()` the recorded events. The session tracks the version and applies optimistic locking for you.

```php
// app/Context/Document/Application/Command/CreateDocumentCommand.php
final class CreateDocumentCommand
{
    public function __construct(public DocumentId $id, public string $title) {}
}
```

```php
// app/Context/Document/Application/Command/RenameDocumentCommand.php
final class RenameDocumentCommand
{
    public function __construct(public DocumentId $id, public string $newTitle) {}
}
```

```php
// app/Context/Document/Application/Handler/RenameDocumentHandler.php
use Pillar\Aggregate\AggregateSession;
use App\Context\Document\Domain\Identifier\DocumentId;

final class RenameDocumentHandler
{
    public function __construct(private AggregateSession $session) {}

    public function __invoke(RenameDocumentCommand $cmd): void
    {
        $doc = $this->session->find($cmd->id);
        $doc->rename($cmd->newTitle);
        $this->session->commit();
    }
}
```

::: tip
Prefer constructor injection for handlers. You can also use the `Pillar` facade in quick scripts and tests.
:::

## 4) Context registry, aliases, projector

A `ContextRegistry` groups the commands, queries and events for a bounded context, and lets you declare short, stable event aliases plus replay-safe projectors. Pillar discovers registries from `config/pillar.php` and wires buses and listeners.

```php
// app/Context/Document/Application/DocumentContextRegistry.php
use Pillar\Context\{ContextRegistry, EventMapBuilder};

final class DocumentContextRegistry implements ContextRegistry
{
    public function name(): string { return 'document'; }
    public function commands(): array { return [CreateDocumentCommand::class, RenameDocumentCommand::class]; }
    public function queries(): array { return []; }

    public function events(): EventMapBuilder
    {
        return EventMapBuilder::create()
            ->event(DocumentCreated::class)->alias('document.created')->listeners([DocumentCreatedProjector::class])
            ->event(DocumentRenamed::class)->alias('document.renamed')->listeners([DocumentRenamedProjector::class]);
    }
}
```

Register it in `config/pillar.php` under `context_registries`. Aliases are stored instead of FQCNs; listeners implementing `Projector` will run on replays.

Create a projector:

```php
// app/Context/Document/Infrastructure/Projector/DocumentCreatedProjector.php
use Pillar\Event\Projector;

final class DocumentCreatedProjector implements Projector
{
    public function __invoke(DocumentCreated $e): void
    {
        // upsert into a read model table; or cache; or log
    }
}
```

## 5) Try it out

Wire a quick route (or use Tinker) to exercise the flow end‑to‑end: create a `Document`, attach and commit it, then dispatch a rename command. Check the `events` table to see the two events.

```php
use Pillar\Facade\Pillar;
use App\Context\Document\Domain\Identifier\DocumentId;
use App\Context\Document\Domain\Aggregate\Document;
use App\Context\Document\Application\Command\RenameDocumentCommand;

Route::get('/demo', function () {
    $id = DocumentId::new();
    $doc = Document::create($id, 'Hello Pillar');
    Pillar::session()->attach($doc)->commit();
    Pillar::dispatch(new RenameDocumentCommand($id, 'New Title'));
    return $id;
});
```

::: tip
See also: [Snapshotting](/concepts/snapshotting), [Event Store](/event-store/), and [Aliases](/concepts/event-aliases).
:::
