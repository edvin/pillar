## 🎭 Event Aliases

Pillar supports mapping **event classes to short aliases** to make stored event names more readable and stable over
time.

Event aliases are defined in your `ContextRegistry` using the `EventMapBuilder`.  
This allows each bounded context to declare its own aliases alongside its event listeners:

```php
public function events(): EventMapBuilder
{
    return EventMapBuilder::create()
        ->event(DocumentCreated::class)
            ->alias('document_created')
            ->listeners([DocumentCreatedProjector::class])
        ->event(DocumentRevised::class)
            ->alias('document_revised')
            ->listeners([DocumentRevisedProjector::class]);
}
```

During serialization, the alias will be stored in the event store instead of the full class name.  
When loading events, both the alias **and** the original class name are supported — ensuring **backward compatibility**
with existing event streams.

Event aliases are automatically registered through the `ContextRegistry` during application boot,  
and managed globally by the `EventAliasRegistry`, which is used internally by the `DatabaseEventStore`.

### ✅ Benefits

- Shorter, human-readable event names in your database
- Backward compatibility for renamed or refactored event classes
- Centralized alias management across contexts

---

### ⚠️ Avoiding Alias Collisions

Aliases must be **globally unique** because all events share a single alias registry. If two different events (even from
different bounded contexts) use the same alias, it can cause deserialization of the wrong event type, leading to subtle
and hard-to-debug errors.

To prevent collisions, always **prefix your aliases with your context name** or another unique namespace. For example:

- `document.created`
- `billing.invoice_issued`
- `user.password_reset`

This namespacing convention ensures that even if multiple contexts define events with similar names (like `created`),
their aliases remain distinct and unambiguous.

Using fully qualified, namespaced aliases helps maintain the integrity and readability of your event store across all
bounded contexts.

---