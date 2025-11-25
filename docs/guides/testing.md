# ✅ Testing with Pillar

Pillar is very test friendly. Because everything is built on
*explicit* events and strong aggregate identities, you can write tests
that read like stories:

> Given these past events… when something happens… then these new
> events should be recorded and the aggregate should end up in this
> state.

On top of that, Pillar plays nicely with Laravel’s testing ecosystem:

- In-memory databases (SQLite) via `RefreshDatabase`
- Orchestra Testbench for package / library style setups
- Pest or PHPUnit as you prefer

In this guide you’ll learn how to:

1. Run Pillar in a **test environment** (DB, migrations, context registry).
2. Use **`AggregateScenario`** for fast, pure aggregate tests (no DB).
3. Use **`CommandScenario`** to exercise command handlers + EventStore.
4. Test **projectors**, multi-aggregate flows, and **replays**.

If you’re new to Pillar, it helps to skim these first:

- [Aggregate roots](/concepts/aggregate-roots)
- [Aggregate IDs](/concepts/aggregate-ids)
- [Aggregate sessions](/concepts/aggregate-sessions)
- [Projectors](/concepts/projectors)

---

## 🧪 Test environment & database setup {#test-environment}

In Pillar’s own test suite we use:

- **Pest** for test definitions.
- **Orchestra Testbench** to boot a minimal Laravel app.
- **In-memory SQLite** for the database.
- Laravel’s **`RefreshDatabase`** trait so migrations are run for each test group.

A minimal setup looks like this:

```php
// tests/Pest.php
<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(
    TestCase::class,
    RefreshDatabase::class,
)->in('Feature');

uses(TestCase::class)->in('Unit');
```

…and the base `TestCase`:

```php
// tests/TestCase.php
<?php

namespace Tests;

use Illuminate\Contracts\Config\Repository as Config;
use Orchestra\Testbench\TestCase as BaseTestCase;
use Pillar\Provider\PillarServiceProvider;
use Tests\Support\Context\DefaultTestContextRegistry;

abstract class TestCase extends BaseTestCase
{
    protected function getPackageProviders($app): array
    {
        return [PillarServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app): void
    {
        /** @var Config $config */
        $config = $app['config'];

        // Minimal app key / cipher for encryption features
        $config->set('app.cipher', 'AES-256-CBC');
        $config->set('app.key', 'base64:' . base64_encode(random_bytes(32)));

        // In-memory SQLite for fast, isolated tests
        $config->set('database.default', 'sqlite');
        $config->set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        // Array cache to avoid external services
        $config->set('cache.default', 'array');

        // Register a test ContextRegistry so commands, events, projectors, etc. are wired
        // or use your actual context registries
        $config->set('pillar.context_registries', [
            DefaultTestContextRegistry::class,
        ]);
    }

    protected function defineDatabaseMigrations(): void
    {
        // Load Pillar’s own migrations (events, outbox, workers, …)
        $this->loadMigrationsFrom(\dirname(__DIR__) . '/database/migrations');
    }
}
```

In your **own** app you’ll typically:

- Reuse your existing `tests/TestCase.php`, or
- Add `PillarServiceProvider` + migrations to it, and
- Still use `RefreshDatabase` or `DatabaseTransactions` to reset state between tests.

The exact DB backend doesn’t matter; Pillar happily runs on SQLite in tests and Postgres/MySQL in production.

---

## 1. 🧩 Testing aggregates in isolation with `AggregateScenario` {#aggregate-scenario}

`AggregateScenario` is a **pure domain helper**:

- No database, no EventStore.
- You construct the aggregate, apply “given” events, then call methods directly.
- You assert on **emitted events** and **final aggregate state**.

```php
use Pillar\Testing\AggregateScenario;
use Tests\Fixtures\Document\Document;
use Tests\Fixtures\Document\DocumentCreated;
use Tests\Fixtures\Document\DocumentId;
use Tests\Fixtures\Document\DocumentRenamed;

it('renames a document', function () {
    $id = DocumentId::new();

    AggregateScenario::for($id)
        // Given an existing document
        ->given(new DocumentCreated($id, 'First'))

        // When we rename it
        ->whenAggregate(fn (Document $doc) => $doc->rename('Second'))

        // Then it emits the correct event
        ->thenEvents(new DocumentRenamed($id, 'Second'))

        // …and ends up in the expected state
        ->thenAggregate(function (Document $doc) {
            expect($doc->title())->toBe('Second');
        });
});
```

### API overview

```php
AggregateScenario::for(AggregateRootId $id)
    ->given(object ...$events)
    ->whenAggregate(callable $fn)
    ->when(callable $fn)               // alias for whenAggregate()
    ->thenEvents(object ...$expected)
    ->thenNoEvents()
    ->thenException(string $class)
    ->thenNoException()
    ->thenAggregate(callable $assert)
    ->emittedEvents(): array<object>
    ->thrown(): ?Throwable
    ->aggregate(): EventSourcedAggregateRoot
    ->at(CarbonImmutable|string $time): self;
```

#### Multi-step flows on the same aggregate

You can chain multiple `whenAggregate()` calls on the same scenario instance:

```php
$scenario = AggregateScenario::for($id)
    ->given(new DocumentCreated($id, 'v0'));

$scenario
    ->whenAggregate(fn (Document $d) => $d->rename('v1'))
    ->thenEvents(new DocumentRenamed($id, 'v1'));

$scenario
    ->whenAggregate(fn (Document $d) => $d->rename('v2'))
    ->thenEvents(new DocumentRenamed($id, 'v2'));

$scenario->thenAggregate(function (Document $d) {
    expect($d->title())->toBe('v2');
});
```

Each `whenAggregate()`:

- Reuses the **same in-memory aggregate instance**.
- Clears previously recorded events before running your callback.
- Captures only the **newly recorded events** from that step.

#### Asserting exceptions

When you expect a domain invariant to fail:

```php
AggregateScenario::for($id)
    ->given(new DocumentCreated($id, 'v0'))
    ->whenAggregate(fn (Document $d) => $d->rename('')) // invalid
    ->thenException(DomainException::class);
```

Or the opposite:

```php
AggregateScenario::for($id)
    ->given(new DocumentCreated($id, 'v0'))
    ->whenAggregate(fn (Document $d) => $d->rename('v1'))
    ->thenNoException()
    ->thenEvents(new DocumentRenamed($id, 'v1'));
```

#### Working directly with events & exceptions

If you prefer Pest’s `expect()` style:

```php
$scenario = AggregateScenario::for($id)
    ->given(new DocumentCreated($id, 'v0'))
    ->whenAggregate(fn (Document $d) => $d->rename('v1'));

expect($scenario->emittedEvents())->toEqual([
    new DocumentRenamed($id, 'v1'),
]);

// No exception was thrown in this step
expect($scenario->thrown())->toBeNull();
```

You can also inspect the **actual exception instance** when something fails:

```php
$scenario = AggregateScenario::for($id)
    ->given(new DocumentCreated($id, 'v0'))
    ->whenAggregate(fn (Document $d) => $d->rename('')); // invalid

$e = $scenario->thrown();

expect($e)->toBeInstanceOf(DomainException::class)
    ->and($e->getMessage())->toContain('empty title');
```

`->thrown()` always returns either the last `Throwable` raised in `whenAggregate()` (or `when()`), or `null` if no exception occurred.

#### Time-sensitive behavior with `->at()`

`AggregateScenario` also lets you control the **logical time** via `EventContext`:

```php
use Carbon\CarbonImmutable;
use Pillar\Event\EventContext;

it('records occurredAt correctly', function () {
    $id = DocumentId::new();
    $frozen = CarbonImmutable::parse('2025-01-01T12:00:00Z');

    AggregateScenario::for($id)
        ->at($frozen)
        ->whenAggregate(fn (Document $d) => $d->rename('Hello'));

    // Inside your aggregate/event factory, you can read EventContext::occurredAt()
    // to stamp events deterministically for testing.
});
```

Inside your aggregate or event factory you can use:

```php
EventContext::occurredAt();        // logical event time (UTC)
EventContext::correlationId();     // per-operation trace id
EventContext::aggregateRootId();   // AggregateRootId|null for the current stream
EventContext::isReconstituting();
EventContext::isReplaying();
```

You can also read `EventContext::aggregateRootId()` in handler or projector tests (or via the `InteractsWithEvents` trait) to assert that the correct aggregate id was propagated for a given event.

---

## 2. 🚦 Testing command handlers with `CommandScenario` {#command-scenario}

`CommandScenario` tests a **full command pipeline** for a single aggregate:

- Uses the real **CommandBus**.
- Uses the real **EventStore** and **EventStoreRepository**.
- Respects **snapshots**, **upcasters**, **fetch strategies**, etc.
- Focuses on “what did this aggregate stream emit when I dispatched this command?”.

```php
use Pillar\Testing\CommandScenario;
use Tests\Fixtures\Document\Commands\RenameDocument;
use Tests\Fixtures\Document\Document;
use Tests\Fixtures\Document\DocumentCreated;
use Tests\Fixtures\Document\DocumentId;
use Tests\Fixtures\Document\DocumentRenamed;

it('renames a document via command handler', function () {
    $id = DocumentId::new();

    CommandScenario::for($id)
        // Seed history in the real EventStore
        ->given(new DocumentCreated($id, 'First'))

        // Dispatch a command through the CommandBus
        ->whenCommand(new RenameDocument($id, 'Second'))

        // Assert events appended for this stream
        ->thenEvents(new DocumentRenamed($id, 'Second'))

        // Assert the repository view of the aggregate
        ->thenAggregate(function (Document $doc) {
            expect($doc->title())->toBe('Second');
        });
});
```

### API overview


```php
CommandScenario::for(AggregateRootId $id)
    ->given(object ...$events)          // seeds events via EventStore::append()
    ->whenCommand(object $command)
    ->when(object $command)             // alias for whenCommand()
    ->thenEvents(object ...$expected)
    ->thenNoEvents()
    ->thenException(string $class)
    ->thenNoException()
    ->thenAggregate(callable $assert)   // loads from EventStoreRepository
    ->emittedEvents(): array<object>    // last command’s new events (payloads)
    ->thrown(): ?Throwable              // exception from handler, if any
    ->aggregate(): EventSourcedAggregateRoot;
```

#### Inspecting handler exceptions with `->thrown()`

Just like `AggregateScenario`, `CommandScenario` lets you look at the actual exception thrown by the handler:

```php
$scenario = CommandScenario::for($id)
    ->given(new DocumentCreated($id, 'v0'))
    ->whenCommand(new RenameDocument($id, '')); // invalid

$e = $scenario->thrown();

expect($e)->toBeInstanceOf(DomainException::class)
    ->and($e->getMessage())->toContain('empty title');
```

This is useful when you care about the **message**, **code**, or a custom exception type, rather than just asserting that “something failed”.

### How it works under the hood

For each `whenCommand($command)`:

1. It resolves `EventStore` and `CommandBus` from the container.
2. It figures out the **current per-stream version** for this aggregate (by reading the stream once).
3. It dispatches the command via `$bus->dispatch($command)`.
4. It calls `EventStore::streamFor($id, EventWindow::afterStreamSeq($before))`
   to fetch only the **new events** appended to this stream.
5. It stores those new events’ **payloads** in `$emitted` for your assertions.
6. It tracks the latest `streamSequence` for subsequent commands.

Because it uses `EventStoreRepository` in `thenAggregate()`, your tests see the same behavior you’d have in production:

- Snapshots are respected.
- Upcasters run.
- Fetch strategies (chunked, streaming, load-all) are used.
- Outbox publication runs (if your handlers record publishable events).

---

## 3. 🧠 Testing projectors {#projectors}

Projectors should be **idempotent** and deterministic:

- Applying the same event twice should not create duplicates.
- Replaying historical events into a fresh read model should yield the same result as “live” processing.

A simple projector test looks like this:

```php
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\Projectors\TitleListProjector;
use Tests\Fixtures\Document\DocumentCreated;

it('is idempotent', function () {
    DB::table('titles')->truncate();

    $projector = app(TitleListProjector::class);
    $event = new DocumentCreated(DocumentId::new(), 'Hello');

    // First time
    $projector($event);

    // Second time (e.g. during replay)
    $projector($event);

    $rows = DB::table('titles')->get();

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->title)->toBe('Hello');
});
```

For more end-to-end projector tests, you can:

- Seed events in the EventStore (via `CommandScenario` or plain `EventStore::append()`).
- Run `pillar:replay-events` against a **test** database.
- Assert on the resulting read models.

---

## 4. 🌐 Multi-aggregate / process tests {#multi-aggregate}

Sometimes your business flow involves **multiple aggregates**:

- `Order` emits `OrderPaid`.
- A process or projector reacts and sends a command to `Invoice`.
- `Invoice` transitions to “paid”.

These flows are **not naturally centered** on a single aggregate, so `CommandScenario` is less of a fit. For those, write a small “system test” using the real bus, store and repository directly:

```php
use Pillar\Event\EventStore;
use Pillar\Repository\EventStoreRepository;
use Pillar\Bus\CommandBus;

it('marks invoice paid when order is paid', function () {
    $orderId   = OrderId::new();
    $invoiceId = InvoiceId::new();

    /** @var EventStore $store */
    $store = app(EventStore::class);
    /** @var EventStoreRepository $repo */
    $repo  = app(EventStoreRepository::class);
    /** @var CommandBus $bus */
    $bus   = app(CommandBus::class);

    // Given: order and invoice exist
    $store->append($orderId, new OrderCreated($orderId, $invoiceId));
    $store->append($invoiceId, new InvoiceCreated($invoiceId, 1000));

    // When: we pay the order via command handler
    $bus->dispatch(new PayOrder($orderId));

    // Then: both aggregates have the expected state
    $order   = $repo->find($orderId)?->aggregate;
    $invoice = $repo->find($invoiceId)?->aggregate;

    expect($order->status)->toBe('paid');
    expect($invoice->status)->toBe('paid');
});
```

If you prefer, you can also lean on the [`Pillar` facade](/concepts/pillar-facade) to keep command dispatching terse, while still using the repository directly for reads:

```php
use Pillar\Support\Facades\Pillar;
use Pillar\Repository\EventStoreRepository;

it('marks invoice paid when order is paid (facade version)', function () {
    $orderId   = OrderId::new();
    $invoiceId = InvoiceId::new();

    // Given: order and invoice exist (created via commands)
    Pillar::dispatch(new CreateOrder($orderId, $invoiceId));
    Pillar::dispatch(new CreateInvoice($invoiceId, 1000));

    // When: we pay the order via command handler
    Pillar::dispatch(new PayOrder($orderId));

    // Then: both aggregates have the expected state
    $session = Pillar::session();

    $order   = $session->find($orderId);
    $invoice = $session->find($invoiceId);

    expect($order->status)->toBe('paid');
    expect($invoice->status)->toBe('paid');
});
```

You can freely **mix and match**:

- Use `AggregateScenario` for fast, pure domain tests.
- Use `CommandScenario` when you want a single aggregate’s full pipeline.
- Drop down to “manual wiring” for richer cross-aggregate stories.

---

## 5. 🔁 Testing replays {#replays}

Finally, you can test the replay CLI itself in a test database:

```php
use Illuminate\Support\Facades\Artisan;
use Pillar\Aggregate\AggregateRegistry;
use Tests\Fixtures\Document\Document;
use Tests\Fixtures\Document\DocumentId;
use Tests\Fixtures\Projectors\TitleListProjector;

it('pillar:replay-events rebuilds projectors', function () {
    TitleListProjector::reset();

    $id = DocumentId::new();

    // Produce some events via the Session + EventStoreRepository
    $s = Pillar::session();
    $s->attach(Document::create($id, 'v0'));
    $s->commit();

    $s2 = Pillar::session();
    $doc = $s2->find($id);
    $doc->rename('v1');
    $s2->commit();

    // Simulate a “cold” projector
    TitleListProjector::reset();

    $streamId = app(AggregateRegistry::class)->toStreamName($id);

    $exit = Artisan::call('pillar:replay-events', [
        'stream_id' => $streamId,
    ]);

    expect($exit)->toBe(0);
    expect(TitleListProjector::$seen)->toBe(['v0', 'v1']);
});
```

Because tests use an **isolated, in-memory DB** (via `RefreshDatabase` + SQLite), replaying is fast and safe, and you can assert on both:

- CLI output / exit codes.
- Side-effects in projectors / read models.

---

## 🚀 Summary

- Use **`AggregateScenario`** when you want **pure aggregate tests**: no DB, no bus, just events and state.
- Use **`CommandScenario`** when you want to exercise **command handlers + EventStore + repository** for a single aggregate.
- Use **direct wiring** (EventStore + CommandBus + EventStoreRepository) for **multi-aggregate flows** and integration tests.
- Use `pillar:replay-events` in a test DB to verify **projector idempotency** and replay behavior end-to-end.
- Let `RefreshDatabase` + an in-memory SQLite connection keep tests **fast and isolated**.

With these pieces, you can push confidence all the way from tiny invariants to full process flows—without sacrificing speed or clarity.
