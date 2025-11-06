<?php

namespace Tests\Fixtures\Projectors;

use Pillar\Event\Projector;
use Tests\Fixtures\Document\DocumentCreated;
use Tests\Fixtures\Document\DocumentRenamed;

final class TitleListProjector implements Projector
{
    /** @var list<string> */
    public static array $seen = [];

    public static function reset(): void
    {
        self::$seen = [];
    }

    public function __invoke(object $event): void
    {
        if ($event instanceof DocumentCreated) {
            self::$seen[] = $event->title;
        } elseif ($event instanceof DocumentRenamed) {
            self::$seen[] = $event->title;
        }
    }
}