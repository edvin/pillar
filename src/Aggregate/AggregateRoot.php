<?php

namespace Pillar\Aggregate;

use JsonSerializable;
use ReflectionClass;

abstract class AggregateRoot implements JsonSerializable
{
    protected bool $reconstituting = false;

    public function markAsReconstituting(): void
    {
        $this->reconstituting = true;
    }

    public function markAsNotReconstituting(): void
    {
        $this->reconstituting = false;
    }

    public function isReconstituting(): bool
    {
        return $this->reconstituting;
    }

    public abstract static function fromSnapshot(array $data): self;

    public abstract function id(): AggregateRootId;


    /** @var object[] */
    private array $recordedEvents = [];

    protected function record(object $event): void
    {
        $this->apply($event);

        if ($this->isReconstituting()) {
            return;
        }

        $this->recordedEvents[] = $event;
    }

    public function apply(object $event): void
    {
        $method = 'apply' . new ReflectionClass($event)->getShortName();
        if (method_exists($this, $method)) {
            $this->$method($event);
        }
    }

    /** @return object[] */
    public function recordedEvents(): array
    {
        return $this->recordedEvents;
    }

    /** @return object[] */
    public function releaseEvents(): array
    {
        $events = $this->recordedEvents;
        $this->recordedEvents = [];
        return $events;
    }

}
