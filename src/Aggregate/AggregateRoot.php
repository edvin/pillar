<?php

namespace Pillar\Aggregate;

use JsonSerializable;
use Pillar\Event\EventContext;
use ReflectionClass;

abstract class AggregateRoot
{
    public abstract function id(): AggregateRootId;


    /** @var object[] */
    private array $recordedEvents = [];

    protected function record(object $event): void
    {
        $this->apply($event);

        if (EventContext::isReconstituting()) {
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
