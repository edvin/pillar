<?php

use Mockery as m;
use Pillar\Aggregate\AggregateRootId;
use Pillar\Repository\EventStoreRepository;
use Pillar\Snapshot\CreateSnapshotJob;
use Pillar\Snapshot\SnapshotStore;
use Pillar\Aggregate\EventSourcedAggregateRoot;
use Pillar\Snapshot\Snapshottable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
final readonly class CreateSnapshotJobTestId extends AggregateRootId
{
    public static function aggregateClass(): string
    {
        // Any class-string is fine for this test; it's never used by the job itself.
        return stdClass::class;
    }
}

it('calls snapshot store save with reconstructed id and payload', function () {
    $id = CreateSnapshotJobTestId::new();
    $seq = 42;
    $payload = ['foo' => 'bar', 'baz' => 123];

    $store = m::mock(SnapshotStore::class);

    $store->shouldReceive('save')
        ->once()
        ->withArgs(function (AggregateRootId $saveId, int $sequence, array $data)
        use ($id, $seq, $payload) {
            expect($id)->toBeInstanceOf(CreateSnapshotJobTestId::class)
                ->and((string)$id)->toBe($id->value())
                ->and($sequence)->toBe($seq)
                ->and($data)->toBe($payload);

            return true;
        });

    $job = new CreateSnapshotJob(
        idClass: $id::class,
        idValue: $id->value(),
        seq: $seq,
        payload: $payload,
    );

    $job->handle($store);
});

it('is configured to run after commit', function () {
    $job = new CreateSnapshotJob(
        idClass: CreateSnapshotJobTestId::class,
        idValue: 'some-id',
        seq: 1,
        payload: [],
    );

    expect($job->afterCommit)->toBeTrue();
});

