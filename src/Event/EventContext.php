<?php

namespace Pillar\Event;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

final class EventContext
{
    private static ?CarbonImmutable $occurredAt = null;
    private static ?string $correlationId = null;

    /**
     * Initialize a fresh event context.
     * Used at the start of a command or explicitly during replay.
     */
    public static function initialize(
        CarbonImmutable|string|null $occurredAt = null,
        string|null                 $correlationId = null,
    ): void
    {
        self::$occurredAt = $occurredAt
            ? self::normalizeToImmutable($occurredAt)
            : CarbonImmutable::now('UTC');

        self::$correlationId = $correlationId ?? (string)Str::uuid();
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
     * Clear the current context (used after replaying or completing a request).
     */
    public static function clear(): void
    {
        self::$occurredAt = null;
        self::$correlationId = null;
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
