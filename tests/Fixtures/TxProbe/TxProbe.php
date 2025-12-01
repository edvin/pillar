<?php

namespace Tests\Fixtures\TxProbe;

use Pillar\Aggregate\AggregateRootId;
use Pillar\Aggregate\EventSourcedAggregateRoot;
use Pillar\Aggregate\RecordsEvents;
use Tests\Fixtures\Event\TxProbeCreated;
use Tests\Fixtures\Event\TxProbeRenamed;

final class TxProbe implements EventSourcedAggregateRoot
{
    use RecordsEvents;

    private TxProbeId $id;
    private string $title = '';

    public static function create(): self
    {
        $self = new self();
        $self->record(new TxProbeCreated(TxProbeId::new()));
        return $self;
    }

    public function applyTxProbeCreated(TxProbeCreated $event): void
    {
        $this->id = $event->id;
    }

    public function rename(string $title): void
    {
        $this->record(new TxProbeRenamed($title));
    }

    public function applyTxProbeRenamed(TxProbeRenamed $event): void
    {
        $this->title = $event->title;
    }

    public function id(): AggregateRootId
    {
        return $this->id;
    }
}
