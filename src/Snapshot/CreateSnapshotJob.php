<?php

namespace Pillar\Snapshot;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Pillar\Aggregate\AggregateRootId;

final class CreateSnapshotJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    /**
     * @param class-string<AggregateRootId> $idClass
     * @param array<string,mixed> $payload
     */
    public function __construct(
        public string $idClass,
        public string $idValue,
        public int    $seq,
        public array  $payload,
    )
    {
        $this->afterCommit = true;
    }

    public function handle(SnapshotStore $store): void
    {
        /** @var AggregateRootId $id */
        $id = $this->idClass::from($this->idValue);

        $store->save($id, $this->seq, $this->payload);
    }
}