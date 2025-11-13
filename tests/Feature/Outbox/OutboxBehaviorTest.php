<?php

use Pillar\Aggregate\GenericAggregateId;
use Pillar\Event\EventStore;
use Pillar\Event\ShouldPublish;
use Pillar\Outbox\Outbox;
use Pillar\Outbox\OutboxMessage;
use Pillar\Outbox\Worker\WorkerRunner;

it('does not dispatch a ShouldPublish event until a worker tick runs', function () {
    // Arrange: make the worker claim from all partitions (no leasing complexity)
    config()->set('pillar.outbox.worker.leasing', false);

    // We only care about this specific event class
    Event::fake([TestPublishedForTick::class]);

    /** @var EventStore $store */
    $store = app(EventStore::class);

    // Append a publishable event directly (no aggregate needed for this assertion)
    $aggregateId = GenericAggregateId::new();
    $store->append($aggregateId, new TestPublishedForTick('hello'), null);

    // Assert: nothing dispatched yet (enqueued in outbox only)
    Event::assertNotDispatched(TestPublishedForTick::class);

    // Act: run one worker tick (claims & publishes from outbox)
    /** @var WorkerRunner $runner */
    $runner = app(WorkerRunner::class);
    $runner->tick();

    // Assert: now it has been dispatched exactly once
    Event::assertDispatchedTimes(TestPublishedForTick::class, 1);
});


it('hydrates a pending outbox message', function () {
    /** @var Outbox $outbox */
    $outbox = app(Outbox::class);

    $outbox->enqueue(globalSequence: 42, partition: 'p00');

    $messages = $outbox->claimPending(limit: 10);
    expect($messages)->toHaveCount(1);

    /** @var OutboxMessage $m */
    $m = $messages[0];

    expect($m->globalSequence)->toBe(42)
        ->and($m->publishedAt)->toBeNull()
        ->and($m->isPublished())->toBeFalse()
        ->and($m->isReady(new DateTimeImmutable('2030-01-01T00:00:00Z')))->toBeTrue();
});


/**
 * Minimal event fixture that is publishable via the outbox.
 */
final class TestPublishedForTick implements ShouldPublish
{
    public function __construct(public string $payload) {}
}