<?php

namespace Tests\Fixtures\Projectors;

use Pillar\Event\EventContext;
use Pillar\Event\Projector;

final class ContextProbeProjector implements Projector
{
    /** @var list<array{type: class-string, corr: ?string, ts: string}> */
    public static array $seen = [];

    public static function reset(): void
    {
        self::$seen = [];
    }

    public function __invoke(object $event): void
    {
        self::$seen[] = [
            'type' => get_class($event),
            'corr' => EventContext::correlationId(),
            'ts' => (string)EventContext::occurredAt(),
            'aggregateRootId' => (string)EventContext::aggregateRootId(),
        ];
    }
}