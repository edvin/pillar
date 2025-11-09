<?php
// src/Event/Window/EventWindow.php
declare(strict_types=1);

namespace Pillar\Event;

use DateTimeImmutable;

final class EventWindow
{
    public function __construct(
        // start (after …), pick at most one
        public readonly ?int               $afterAggregateSequence = null,
        public readonly ?int               $afterGlobalSequence = null,
        public readonly ?DateTimeImmutable $afterDateUtc = null,

        // end (until …), pick at most one
        public readonly ?int               $toAggregateSequence = null,
        public readonly ?int               $toGlobalSequence = null,
        public readonly ?DateTimeImmutable $toDateUtc = null,
    )
    {
    }

    public static function afterAggSeq(int $seq): self
    {
        return new self(afterAggregateSequence: $seq);
    }

    public static function afterGlobalSeq(int $seq): self
    {
        return new self(afterGlobalSequence: $seq);
    }

    public static function afterDateUtc(DateTimeImmutable $utc): self
    {
        return new self(afterDateUtc: $utc);
    }

    public static function toAggSeq(int $seq): self
    {
        return new self(toAggregateSequence: $seq);
    }

    public static function toGlobalSeq(int $seq): self
    {
        return new self(toGlobalSequence: $seq);
    }

    public static function toDateUtc(DateTimeImmutable $utc): self
    {
        return new self(toDateUtc: $utc);
    }

    public static function betweenAggSeq(int $after, int $to): self
    {
        return new self(afterAggregateSequence: $after, toAggregateSequence: $to);
    }

    public static function unbounded(): self
    {
        return new self();
    }
}