<?php

namespace Pillar\Event;

final class UpcastResult
{
    /**
     * @param array $payload The transformed payload (latest shape).
     * @param int $fromVersion Version stored in the DB.
     * @param int $toVersion Version after applying upcasters.
     * @param list<class-string<Upcaster>> $upcasters Upcasters that actually ran, in order.
     */
    public function __construct(
        public array $payload,
        public int   $fromVersion,
        public int   $toVersion,
        public array $upcasters = [],
    )
    {
    }
}