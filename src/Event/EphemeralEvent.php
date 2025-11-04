<?php

namespace Pillar\Event;

/**
 * This interface marks events that are *not persisted* to the event store and are instead handled transiently
 * (e.g., in-memory or for immediate projection).
 *
 * Implementing this interface is a declarative way to signal to the system that the event should not be recorded historically.
 */
interface EphemeralEvent
{

}