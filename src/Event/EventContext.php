<?php

namespace Pillar\Event;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Pillar\Aggregate\AggregateRootId;

final class EventContext
{
    private static ?CarbonImmutable $occurredAt = null;
    private static ?string $correlationId = null;
    private static bool $reconstituting = false;
    private static bool $replaying = false;
    private static ?AggregateRootId $aggregateRootId = null;


    public static function isReplaying(): bool
    {
        return self::$replaying;
    }

    /**
     * Initialize a fresh event context.
     * Used at the start of a command or explicitly during replay.
     */
    public static function initialize(
        CarbonImmutable|string|null $occurredAt = null,
        string|null                 $correlationId = null,
        bool                        $reconstituting = false,
        bool                        $replaying = false,
        ?AggregateRootId            $aggregateRootId = null
    ): void
    {
        self::$occurredAt = $occurredAt
            ? self::normalizeToImmutable($occurredAt)
            : CarbonImmutable::now('UTC');

        self::$correlationId = $correlationId ?? (string)Str::uuid();
        self::$reconstituting = $reconstituting;
        self::$replaying = $replaying;
        self::$aggregateRootId = $aggregateRootId;
    }

    public static function isReconstituting(): bool
    {
        return self::$reconstituting;
    }

    /**
     * Return the current event timestamp (UTC).
     */
    public static function occurredAt(): ?CarbonImmutable
    {
        return self::$occurredAt;
    }

    /**
     * Return the correlation ID for this logical operation.
     */
    public static function correlationId(): ?string
    {
        return self::$correlationId;
    }

    /**
     * Return the AggregateRootId connected to the current event.
     *
     * @return AggregateRootId|null
     */
    public static function aggregateRootId(): ?AggregateRootId {
        return self::$aggregateRootId;
    }

    /**
     * Clear the current context (used after replaying or completing a request).
     */
    public static function clear(): void
    {
        self::$occurredAt = null;
        self::$correlationId = null;
        self::$reconstituting = false;
        self::$replaying = false;
        self::$aggregateRootId = null;
    }

    /**
     * Normalize input to a CarbonImmutable in UTC.
     */
    private static function normalizeToImmutable(CarbonImmutable|string $time): CarbonImmutable
    {
        if ($time instanceof CarbonImmutable) {
            return $time->setTimezone('UTC');
        }

        return new CarbonImmutable($time, 'UTC');
    }
}
