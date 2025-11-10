<?php

namespace Pillar\Aggregate;

namespace Pillar\Aggregate;

use Pillar\Event\EventContext;
use ReflectionClass;

trait RecordsEvents
{
    /** @var object[] */
    private array $recordedEvents = [];

    protected function record(object $event): void
    {
        $this->apply($event);

        if (!EventContext::isReconstituting()) {
            $this->recordedEvents[] = $event;
        }
    }

    public function apply(object $event): void
    {
        $method = 'apply' . new ReflectionClass($event)->getShortName();
        if (method_exists($this, $method)) {
            $this->{$method}($event);
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