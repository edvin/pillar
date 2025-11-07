## 🧠 Aggregate Sessions

Command handlers use the **AggregateSession** to load and persist aggregates.

The session tracks all loaded aggregates, captures emitted events, and commits them atomically at the end of the
command.

```php
use Pillar\Aggregate\AggregateSession;
use Context\Document\Domain\Identifier\DocumentId;
use Context\Document\Application\Command\RenameDocumentCommand;

final class RenameDocumentHandler
{
    public function __construct(private AggregateSession $session) {}

    public function __invoke(RenameDocumentCommand $command): void
    {
        $document = $this->session->find(DocumentId::from($command->id));
        $document->rename($command->newTitle);
        $this->session->commit();
    }
}
```

This pattern ensures that all domain changes occur within a controlled *unit of work* —
capturing emitted events, maintaining consistency, and persisting all changes in a single transaction.