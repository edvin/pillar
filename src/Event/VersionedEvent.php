<?php

namespace Pillar\Event;

/**
 * Implemented by events that have a schema version.
 *
 * Used by the event store and upcasters to handle
 * versioned serialization and backward compatibility.
 */
interface VersionedEvent
{
    /**
     * The schema version of this event.
     *
     * @return int Must be >= 1.
     */
    public static function version(): int;
}