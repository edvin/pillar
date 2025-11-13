<?php
declare(strict_types=1);

namespace Pillar\Outbox;

use DateTimeImmutable;

/**
 * Immutable view of a row in the outbox table.
 * Pointer-only: we rehydrate the actual event via EventStore::getByGlobalSequence().
 */
final class OutboxMessage
{
    public function __construct(
        public readonly int                $globalSequence, // PK == events.sequence
        public readonly int                $attempts,
        public readonly DateTimeImmutable  $availableAt,
        public readonly ?DateTimeImmutable $publishedAt,
        public readonly ?string            $partitionKey,
        public readonly ?string            $lastError,
    )
    {
    }

    public function isPublished(): bool
    {
        return $this->publishedAt !== null;
    }

    public function isReady(DateTimeImmutable $now = new DateTimeImmutable('now')): bool
    {
        return $this->publishedAt === null && $this->availableAt <= $now;
    }

    /**
     * Hydrate from a DB row (stdClass from Query Builder or associative array).
     */
    public static function fromRow(object $row): self
    {
        return new self(
            globalSequence: (int)$row->global_sequence,
            attempts: (int)$row->attempts,
            availableAt: new DateTimeImmutable((string)$row->available_at),
            publishedAt: ($row->published_at === null) ? null : new DateTimeImmutable((string)$row->published_at),
            partitionKey: $row->partition_key ?? null,
            lastError: $row->last_error ?? null,
        );
    }
}