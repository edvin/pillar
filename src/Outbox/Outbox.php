<?php
declare(strict_types=1);

namespace Pillar\Outbox;

use Throwable;

/**
 * Outbox (reference-based)
 * ------------------------
 * Transactional Outbox that stores a pointer to the persisted event row
 * (the global event sequence) instead of duplicating payloads.
 *
 * Flow
 * - During aggregate save (same DB transaction), enqueue the event's global
 *   sequence id in the outbox with delivery metadata.
 * - A worker claims pending rows, rehydrates the event via
 *   EventStore::getByGlobalSequence(), dispatches, then marks published.
 */
interface Outbox
{
    /**
     * Enqueue a pointer to an already-persisted event.
     *
     * @param int $globalSequence Global PK from events.sequence
     * @param null|string $partition Optional shard key for workers
     */
    public function enqueue(
        int     $globalSequence,
        ?string $partition = null,
    ): void;

    /**
     * Claim a batch of pending rows for exclusive processing.
     *
     * @param int $limit Maximum number of messages to claim
     * @param string[] $partitions Optional list of partitions to claim from. Empty list means all partitions.
     *
     * @return iterable<OutboxMessage>
     */
    public function claimPending(int $limit = 100, array $partitions = []): iterable;

    public function markPublished(OutboxMessage $message): void;

    public function markFailed(OutboxMessage $message, Throwable $error): void;
}