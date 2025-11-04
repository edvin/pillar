<?php

namespace Pillar\Event;

/**
 * A Projector is a specialized event listener that updates read models or other projections
 * in response to domain events. Projectors are designed to be side-effect-free during event replay,
 * ensuring idempotent updates. They are typically used to maintain queryable state derived from event streams.
 *
 * Only events handled by projectors implementing this interface will be replayed during projection rebuilds.
 */
interface Projector
{

}