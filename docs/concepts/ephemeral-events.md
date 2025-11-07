## ⚡ Ephemeral Events

Not every domain event needs to be stored permanently.  
Some events represent **transient domain signals** — things that should be dispatched in real time but never recorded in
the event store. For these cases, Pillar provides the `EphemeralEvent` marker interface:

```php
use Pillar\Event\EphemeralEvent;

final class TemporaryCacheInvalidated implements EphemeralEvent
{
    public function __construct(
        public string $cacheKey
    ) {}
}
```

Any event implementing `EphemeralEvent` will be **dispatched normally** (to listeners, handlers, and projectors)  
but **excluded from persistence** in the event store. This is useful for:

- Events that only trigger external processes (like cache invalidation or notifications)
- Integration events that are transient and not part of aggregate history
- Temporary or internal system events that don’t represent durable business facts

This keeps your event streams clean, containing only domain events that truly represent **state changes**.