<?php
// src/Event/Window/EventWindow.php
declare(strict_types=1);

namespace Pillar\Event;

use DateTimeImmutable;

final class EventWindow
{
    public function __construct(
        // start (after …), pick at most one
        public readonly ?int               $afterStreamSequence = null,
        public readonly ?int               $afterGlobalSequence = null,
        public readonly ?DateTimeImmutable $afterDateUtc = null,

        // end (until …), pick at most one
        public readonly ?int               $toStreamSequence = null,
        public readonly ?int               $toGlobalSequence = null,
        public readonly ?DateTimeImmutable $toDateUtc = null,
    )
    {
    }

    public static function afterStreamSeq(int $seq): self
    {
        return new self(afterStreamSequence: $seq);
    }

    public static function afterGlobalSeq(int $seq): self
    {
        return new self(afterGlobalSequence: $seq);
    }

    public static function afterDateUtc(DateTimeImmutable $utc): self
    {
        return new self(afterDateUtc: $utc);
    }

    public static function toStreamSeq(int $seq): self
    {
        return new self(toStreamSequence: $seq);
    }

    public static function toGlobalSeq(int $seq): self
    {
        return new self(toGlobalSequence: $seq);
    }

    public static function toDateUtc(DateTimeImmutable $utc): self
    {
        return new self(toDateUtc: $utc);
    }

    public static function betweenStreamSeq(int $after, int $to): self
    {
        return new self(afterStreamSequence: $after, toStreamSequence: $to);
    }

    public static function unbounded(): self
    {
        return new self();
    }
}