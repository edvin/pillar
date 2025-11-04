<?php

namespace Pillar\Event;

interface Upcaster
{
    /** Returns the event class this upcaster handles */
    public static function eventClass(): string;

    /** Returns the version this upcaster applies to (i.e., “from this version to next”) */
    public static function fromVersion(): int;

    /** Applies the transformation */
    public function upcast(array $payload): array;
}