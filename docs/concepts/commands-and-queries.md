# 🧩 Commands & Queries

Pillar keeps orchestration simple with two buses.

## Commands

A command is a plain object handled by an invokable class.

```php
final class RenameDocumentCommand
{
    public function __construct(public string $id, public string $newTitle) {}
}

final class RenameDocumentHandler
{
    public function __construct(private \Pillar\Aggregate\AggregateSession $session) {}

    public function __invoke(RenameDocumentCommand $c): void
    {
        $doc = $this->session->find(\Context\Document\Domain\Identifier\DocumentId::from($c->id));
        $doc->rename($c->newTitle);
        $this->session->commit();
    }
}
```

Dispatch via the facade or your own bus binding:

```php
\Pillar\Facade\Pillar::dispatch(new RenameDocumentCommand($id, 'New Title'));
```

## Queries

Queries return data; they do not change state.

```php
final class FindDocumentQuery { public function __construct(public string $id) {} }
final class FindDocumentHandler { public function __invoke(FindDocumentQuery $q): array {/* … */} }
```

Ask via the facade:

```php
$result = \Pillar\Facade\Pillar::ask(new FindDocumentQuery($id));
```

## Registration

Register handlers via your **ContextRegistry** so commands/queries are discoverable.
