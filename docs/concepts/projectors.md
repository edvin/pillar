## 🧩 Projectors

Projectors are special event listeners that build or update read models and are safe to replay. They implement the new
marker interface `Projector`, and only projectors are invoked during event replay. This separation ensures that replays
do not trigger side effects such as sending emails or other external actions.


Example of a projector implementing the interface:

```php
use Pillar\Event\Projector;

final class DocumentCreatedProjector implements Projector
{
    public function __invoke(DocumentCreated $event): void
    {
        // Update read model, e.g. insert or update a database record
    }
}
```

👉 **Related concepts:**  
- [Events](../concepts/events.md)  
- [Versioned Events](../concepts/versioned-events.md)  
- [Event Window](../concepts/event-window.md)  
- [Aggregate Sessions](../concepts/aggregate-sessions.md)  
- [Serialization](../concepts/serialization.md)

Example of a listener that is not a projector and will not be invoked during replay:

```php
final class SendDocumentCreatedNotification
{
    public function __invoke(DocumentCreated $event): void
    {
        // Send email notification, side effect not safe for replay
    }
}
```

### ⚠️ Projector Safety & Idempotency

Projectors must be **pure and idempotent**.  
They are re-invoked during event replays to rebuild read models, so applying the same event multiple times should never
produce different results or duplicate data.

When a projector runs during **replay**, it does so inside a special replay context.  
You can detect this via `EventContext::isReplaying()`, which ensures projectors remain safe even when rebuilding large portions of your read model.

For example, when updating a database, projectors should use *insert-or-update* logic instead of blindly inserting new
records.

⚠️ **Important:** Listeners that perform side effects (such as sending emails, publishing messages, or calling APIs)
must **not** implement `Projector`, since replays would re-trigger those side effects. Projectors should handle only
deterministic, replay-safe updates to read models.

---