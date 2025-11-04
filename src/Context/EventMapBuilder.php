<?php

namespace Pillar\Context;

class EventMapBuilder
{
    private array $events = [];
    private ?string $current = null;

    /**
     * Create a new instance of EventMapBuilder.
     *
     * @return self
     */
    public static function create(): self
    {
        return new self();
    }

    /**
     * Define an event to build the map for.
     *
     * @param string $event
     * @return self
     */
    public function event(string $event): self
    {
        $this->current = $event;
        $this->events[$event] = ['alias' => null, 'listeners' => []];
        return $this;
    }

    /**
     * Set an alias for the current event.
     *
     * @param string $alias
     * @return self
     */
    public function alias(string $alias): self
    {
        $this->events[$this->current]['alias'] = $alias;
        return $this;
    }

    /**
     * Set listeners for the current event.
     *
     * @param string|array $listeners
     * @return self
     */
    public function listeners(string|array $listeners): self
    {
        $this->events[$this->current]['listeners'] = is_array($listeners)
            ? $listeners
            : [$listeners];
        return $this;
    }

    /**
     * Set upcasters for the current event.
     *
     * @param string|array $upcasters
     * @return self
     */
    public function upcasters(string|array $upcasters): self
    {
        $this->events[$this->current]['upcasters'] = is_array($upcasters)
            ? $upcasters
            : [$upcasters];
        return $this;
    }

    /**
     * Get a map of aliases to event names.
     *
     * @return array
     */
    public function getAliases(): array
    {
        $aliases = [];

        foreach ($this->events as $event => $data) {
            if (!empty($data['alias'])) {
                $aliases[$data['alias']] = $event;
            }
        }

        return $aliases;
    }

    /**
     * Get a map of events to their listeners.
     *
     * @return array
     */
    public function getListeners(): array
    {
        $map = [];

        foreach ($this->events as $event => $data) {
            if (!empty($data['listeners'])) {
                $map[$event] = $data['listeners'];
            }
        }

        return $map;
    }

    /**
     * Get a map of events to their upcasters.
     *
     * @return array
     */
    public function getUpcasters(): array
    {
        $map = [];
        foreach ($this->events as $event => $data) {
            if (!empty($data['upcasters'])) {
                $map[$event] = $data['upcasters'];
            }
        }
        return $map;
    }

}
