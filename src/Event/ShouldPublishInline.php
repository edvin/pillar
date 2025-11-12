<?php
declare(strict_types=1);

namespace Pillar\Event;

/**
 * Marker interface: handle this event inline (synchronously) within
 * the current database transaction — typically to drive projectors.
 *
 * Semantics
 * ---------
 * - The event is persisted, then dispatched to inline handlers before
 *   the transaction commits. If a handler throws, the entire tx rolls back.
 * - Keep handlers fast, deterministic, and local (no remote I/O).
 *
 * Relationship to ShouldPublish
 * -----------------------------
 * - Inline vs async are usually **mutually exclusive**. Prefer one.
 * - If you implement both, ensure handlers are idempotent and you actually
 *   want both inline projection and async publication.
 */
interface ShouldPublishInline
{
}