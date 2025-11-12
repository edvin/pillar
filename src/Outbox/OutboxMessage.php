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
        public readonly int                $globalSequence,                  // PK == events.sequence
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
    public static function fromRow(object|array $row): self
    {
        // Accept both array and object shapes
        $r = is_array($row) ? (object)$row : $row;

        return new self(
            globalSequence: (int)$r->global_sequence,
            attempts: (int)$r->attempts,
            availableAt: new DateTimeImmutable((string)$r->available_at),
            publishedAt: isset($r->published_at) && $r->published_at !== null
                ? new DateTimeImmutable((string)$r->published_at)
                : null,
            partitionKey: $r->partition_key ?? null,
            lastError: $r->last_error ?? null,
        );
    }

    /**
     * Convenience for logging/metrics.
     */
    public function toArray(): array
    {
        return [
            'global_sequence' => $this->globalSequence,
            'attempts' => $this->attempts,
            'available_at' => $this->availableAt->format(DATE_ATOM),
            'published_at' => $this->publishedAt?->format(DATE_ATOM),
            'partition_key' => $this->partitionKey,
            'last_error' => $this->lastError,
        ];
    }
}