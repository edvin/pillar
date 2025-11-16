## 🧩 Context Registries

A **ContextRegistry** acts as a central place to register and organize the commands, queries, and events belonging to a
bounded context. It helps structure your application by grouping related domain logic and event handling in one place.

A ContextRegistry typically defines:

- The **name** of the context
- The **AggregateRootId classes** whose streams live in this context
- The **commands** handled within the context
- The **queries** supported by the context
- The **events** produced, along with their listeners, optional aliases, and upcasters

In addition, Pillar uses your Context Registries to automatically register **Tinker aliases** for all commands, queries, and AggregateRootId classes.  
This means that inside `php artisan tinker`, you can reference domain classes by their short name — for example, `CreateInvoiceCommand` — without manually typing full namespaces.

This registration enables Pillar to automatically wire up command and query buses, event dispatching, and alias
management across your application.

### Example ContextRegistry

```php
use Pillar\Context\ContextRegistry;
use Pillar\Context\EventMapBuilder;
use App\Context\Document\Domain\Identifier\DocumentId;

final class DocumentContextRegistry implements ContextRegistry
{
    public function name(): string
    {
        return 'document';
    }

    /**
     * All AggregateRootId implementations whose streams belong to this context.
     */
    public function aggregateRootIds(): array
    {
        return [
            DocumentId::class,
        ];
    }

    public function commands(): array
    {
        return [
            CreateDocumentCommand::class,
            RenameDocumentCommand::class,
        ];
    }

    public function queries(): array
    {
        return [
            FindDocumentQuery::class,
        ];
    }

    public function events(): EventMapBuilder
    {
        return EventMapBuilder::create()
            ->event(DocumentCreated::class)
                ->alias('document.created')
                ->upcasters([DocumentCreatedV1ToV2Upcaster::class])
                ->listeners([DocumentCreatedProjector::class])
            ->event(DocumentRenamed::class)
                ->alias('document.renamed')
                ->listeners([DocumentRenamedProjector::class]);
    }
}
```

🧰 Registering Context Registries

Each ContextRegistry must be registered in your application’s `config/pillar.php` file under the `context_registries`
key:

```php
'context_registries' => [
    \Context\DocumentHandling\Application\DocumentContextRegistry::class,
    \Context\UserManagement\Application\UserContextRegistry::class,
],
```

You can scaffold contexts, registries, aggregates, commands, queries, and events using the `pillar:make:*` commands.
See the CLI reference for details: [/reference/cli-make](/reference/cli-make).

---