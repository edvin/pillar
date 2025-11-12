<?php

declare(strict_types=1);

namespace Pillar\Event;

/**
 * Marker interface indicating that a recorded domain event should be published.
 *
 * Semantics
 * ---------
 * - ALL events you record on an aggregate are persisted to its event stream.
 * - Events that ALSO implement this interface are enqueued into the
 *   transactional outbox within the SAME database transaction and will be
 *   delivered to your event bus with retries (at-least-once delivery).
 * - Events that do NOT implement this interface are still persisted for
 *   rehydration, but are considered private to the aggregate and are NOT
 *   published to external handlers/projections/integrations.
 *
 * Usage
 * -----
 *   final class DocumentCreated implements ShouldPublish
 *   {
 *       // ...
 *   }
 *
 * Notes
 * -----
 * - This interface is intentionally empty: it’s a pure “marker”, similar to
 *   Laravel’s ShouldQueue/ShouldBroadcast style.
 * - If you also support an attribute (e.g. #[Publish]), your PublicationPolicy
 *   can treat either the interface or the attribute as a publish signal.
 */
interface ShouldPublish
{

}