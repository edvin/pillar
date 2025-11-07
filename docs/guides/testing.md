# ✅ Testing Aggregates & Projectors

## Given / When / Then for aggregates

```php
// Given
$doc = Document::create(DocumentId::new(), 'First');
$session->attach($doc)->commit();

// When
$doc->rename('Second');
$session->attach($doc)->commit();

// Then (assert emitted events or snapshot)
```

Tips:
- Keep events small and easy to assert.
- Prefer testing aggregate **behavior** over internal state.

## Projectors

Projectors must be **idempotent**. Test by applying the same event twice and
assert the read model stays consistent.

```php
$event = new DocumentCreated((string) DocumentId::new(), 'Hello');

$projector($event); // first time
$projector($event); // second time (replay)

// assert single upserted row, no duplicates
```

## Replays

Use `pillar:replay-events` in a test database to rebuild projections and verify end‑to‑end behavior of your projectors.
