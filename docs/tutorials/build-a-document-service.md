# Tutorial — Build a Document service

In ~30–45 minutes you'll:

1. Install Pillar and publish migrations/config.
2. Model a simple `Document` aggregate with `create` and `rename`.
3. Wire a command handler using the `AggregateSession`.
4. Register a `ContextRegistry` with event aliases and a projector.
5. Run and verify replays safely.

You can follow along inside an existing Laravel project or a fresh one.

## 1) Install Pillar

```bash
composer require pillar/pillar
php artisan pillar:install
php artisan migrate
```

Confirm `config/pillar.php` exists and migrations created `events` and `aggregate_versions`.

## 2) Create the aggregate & events

Create the events:

```php
// app/Context/Document/Domain/Event/DocumentCreated.php
use Pillar\Event\VersionedEvent;

final class DocumentCreated implements VersionedEvent
{
    public static function version(): int { return 1; }

    public function __construct(
        public string $id,
        public string $title,
    ) {}
}
```

```php
// app/Context/Document/Domain/Event/DocumentRenamed.php
final class DocumentRenamed
{
    public function __construct(
        public string $id,
        public string $newTitle,
    ) {}
}
```

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
use Pillar\Snapshot\Snapshottable;
use App\Context\Document\Domain\Event\{DocumentCreated, DocumentRenamed};
use App\Context\Document\Domain\Identifier\DocumentId;

final class Document extends AggregateRoot implements Snapshottable
{
    private DocumentId $id;
    private string $title;

    public static function create(DocumentId $id, string $title): self
    {
        $self = new self();
        $self->record(new DocumentCreated((string)$id, $title));
        return $self;
    }

    public function rename(string $newTitle): void
    {
        if ($this->title === $newTitle) return;
        $self = $this;
        $self->record(new DocumentRenamed((string)$this->id, $newTitle));
    }

    protected function applyDocumentCreated(DocumentCreated $e): void
    {
        $this->id = DocumentId::from($e->id);
        $this->title = $e->title;
    }

    protected function applyDocumentRenamed(DocumentRenamed $e): void
    {
        $this->title = $e->newTitle;
    }

    public function id(): DocumentId { return $this->id; }

    // Snapshots
    public function toSnapshot(): array { return ['id'=>(string)$this->id,'title'=>$this->title]; }
    public static function fromSnapshot(array $d): static
    {
        $self = new self();
        $self->id = DocumentId::from($d['id']);
        $self->title = $d['title'];
        return $self;
    }
}
```

## 3) Commands & handler

```php
// app/Context/Document/Application/Command/CreateDocumentCommand.php
final class CreateDocumentCommand
{
    public function __construct(public string $id, public string $title) {}
}
```

```php
// app/Context/Document/Application/Command/RenameDocumentCommand.php
final class RenameDocumentCommand
{
    public function __construct(public string $id, public string $newTitle) {}
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
        $doc = $this->session->find(DocumentId::from($cmd->id));
        $doc->rename($cmd->newTitle);
        $this->session->commit();
    }
}
```

## 4) Context registry, aliases, projector

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

Register it in `config/pillar.php` under `context_registries`.

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

Create a tinker script or route:

```php
use Pillar\Facade\Pillar;
use App\Context\Document\Domain\Identifier\DocumentId;
use App\Context\Document\Domain\Aggregate\Document;

Route::get('/demo', function () {
    $id = DocumentId::new();
    $doc = Document::create($id, 'Hello Pillar');
    Pillar::session()->attach($doc)->commit();
    Pillar::dispatch(new RenameDocumentCommand($id, 'New Title'));
    return $id;
});
```

Now replay safely:

```bash
php artisan pillar:replay-events
```

Only projectors run during replay, not side-effect listeners.

::: tip
See also: [Snapshotting](/concepts/snapshotting), [Event Store](/event-store/), and [Aliases](/concepts/event-aliases).
:::
