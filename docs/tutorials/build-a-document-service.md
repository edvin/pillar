# Tutorial — Build a Document service

In ~10 minutes you'll:

1. Install Pillar and publish migrations/config.
2. Model a simple `Document` aggregate with `create` and `rename`.
3. Wire a command handler using the `AggregateSession`.
4. Register a `ContextRegistry` with event aliases and a projector.
5. Run and verify replays safely.

You can follow along inside an existing Laravel project or a fresh one.

::: info What we’re building
We’ll model a tiny **Document** domain with two actions: `create` and `rename`. You’ll see how an **aggregate** records
**domain events**, how a **session** loads/commits changes (with optimistic locking), and how a **ContextRegistry** binds
aliases and replay‑safe **projectors**. Along the way we’ll use Pillar’s CLI to **replay events** and verify projections.
:::

## 1) Install Pillar


The first command installs the package, the second publishes migrations and `config/pillar.php` and runs the migrations.
The installer is interactive, so you can choose what to publish; for this tutorial, select yes to all.

```bash
composer require pillar/pillar
php artisan pillar:install
```

## 2) Create the aggregate and events

We’ll model a simple `Document` aggregate. Aggregates record domain events to express state changes; Pillar persists
those events and replays them to rebuild state. IDs are strongly-typed value objects (extending `AggregateRootId`).

**Why value‑object IDs?** They keep types honest and prevent mixing IDs from different aggregates. Pillar’s serializer
handles (de)serializing them, so you can put `DocumentId` *directly* in event payloads.

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
use Pillar\Aggregate\EventSourcedAggregateRoot;
use Pillar\Aggregate\RecordsEvents;
use App\Context\Document\Domain\Event\DocumentCreated;
use App\Context\Document\Domain\Event\DocumentRenamed;
use App\Context\Document\Domain\Identifier\DocumentId;

final class Document implements EventSourcedAggregateRoot
{
    use RecordsEvents;
    
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

> Note: We keep `DocumentId` in the event payload. Pillar’s serializer reconstructs value objects during
> deserialization, so you don’t have to downcast to strings.

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

Commands capture intent (`CreateDocument`, `RenameDocument`). A handler uses an `AggregateSession` (a **Unit of Work**) to
load the aggregate, call a method, and `commit()` the recorded events. The session tracks the version and enforces
**optimistic concurrency** (no extra code needed): if someone else committed first, you’ll get a concurrency exception.

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

::: tip Prefer generators?
You can scaffold the classes you just created with:

```bash
php artisan pillar:make:context Document
php artisan pillar:make:aggregate Document --context=Document
php artisan pillar:make:event DocumentCreated --context=Document
php artisan pillar:make:event DocumentRenamed --context=Document
php artisan pillar:make:command CreateDocument --context=Document
php artisan pillar:make:command RenameDocument --context=Document
```

For details on placement options (`--style`, `--subcontext`, etc.), see the **Make Commands** reference (coming next).
:::

## 4) Context registry, aliases, projector

A `ContextRegistry` groups the commands, queries and events for a bounded context, and lets you declare short, stable
event aliases plus replay-safe projectors. Pillar discovers registries from `config/pillar.php` and wires buses and
listeners.

**Aliases** are stored instead of FQCNs for stability; **projectors** are the only listeners invoked during **replay**.

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

Register it in `config/pillar.php` under `context_registries`. Aliases are stored instead of FQCNs; listeners
implementing `Projector` will run on replays.

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

Wire a quick route (or use Tinker) to exercise the flow end‑to‑end: create a `Document`, attach and commit it, then
dispatch a rename command. Check the `events` table to see the two events.

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

Now hit `/demo` in the browser (or via `curl`). You should see a `DocumentId` returned. Check the `events` table — you’ll
find two rows (created, renamed) for that aggregate. If you use Tinker:

```bash
php artisan tinker
>>> DB::table('events')->orderBy('sequence')->take(2)->get();
```

You can also query by alias, e.g. `document.created`.
  
::: tip
See also: [Snapshotting](/concepts/snapshotting), [Event Store](/event-store/), and [Aliases](/concepts/event-aliases).
:::

## 6) Rebuild projections with the CLI

Pillar ships a replay command that re-runs **projectors only** (side-effect listeners are ignored):

```bash
php artisan pillar:replay-events            # all events
php artisan pillar:replay-events {aggregate_id}
php artisan pillar:replay-events null {Event\Class\Name}
```

Filter by sequence or date (UTC):

```bash
php artisan pillar:replay-events --from-seq=1000 --to-seq=2000
php artisan pillar:replay-events --from-date="2025-01-01" --to-date="2025-01-31"
```

This is handy whenever you change a projector or need to rebuild a read model.

## What’s next
- Skim the **concepts** in order, starting with **[Aggregates](/concepts/aggregate-roots)**.
- Read the short **[Philosophy](/about/philosophy)** for why Pillar favors incremental DDD.
- Dive into **[Event Store](/event-store/)** and **[Snapshotting](/concepts/snapshotting)** when your aggregates grow.
