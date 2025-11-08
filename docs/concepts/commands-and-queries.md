# 🧩 Commands & Queries

Pillar keeps orchestration simple with two buses.

::: code-group

```php [Command]
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

```php [Query]
final class FindDocumentQuery
{
    public function __construct(public string $id) {}
}

final class FindDocumentHandler
{
    public function __invoke(FindDocumentQuery $q): array
    {
        // return DTO/array for read model
        return ['id' => $q->id, 'title' => '...'];
    }
}
```

```php [Facade]
use Pillar\Facade\Pillar;

Pillar::dispatch(new RenameDocumentCommand($id, 'New Title'));
$result = Pillar::ask(new FindDocumentQuery($id));
```

:::
