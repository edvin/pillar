<?php

use Illuminate\Support\Facades\Queue;
use Pillar\Repository\EventStoreRepository;
use Pillar\Snapshot\AlwaysSnapshotPolicy;
use Pillar\Snapshot\CreateSnapshotJob;
use Tests\Fixtures\Document\Document;
use Tests\Fixtures\Document\DocumentId;

it('dispatches CreateSnapshotJob when snapshot mode is queued', function () {
    Queue::fake();

    config()->set('pillar.snapshot.mode', 'queued');
    config()->set('pillar.snapshot.policy.class', AlwaysSnapshotPolicy::class);

    /** @var EventStoreRepository $repository */
    $repository = app(EventStoreRepository::class);

    $id = DocumentId::new();
    $aggregate = Document::create($id, 'Snapshot job test');

    // This will:
    //  - append events in a transaction
    //  - schedule snapshot via DB::afterCommit
    //  - in queued mode, dispatch CreateSnapshotJob with id/seq/payload
    $repository->save($aggregate);

    Queue::assertPushed(CreateSnapshotJob::class, function (CreateSnapshotJob $job) use ($id, $aggregate) {
        expect($job->idClass)->toBe($id::class)
            ->and($job->idValue)->toBe($id->value())
            ->and($job->seq)->toBeGreaterThan(0)
            ->and($job->payload)->toBe($aggregate->toSnapshot());

        return true;
    });
});