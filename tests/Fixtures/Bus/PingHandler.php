<?php

namespace Tests\Fixtures\Bus;

final class PingHandler
{
    /** @var list<string> */
    public static array $seen = [];

    public static function reset(): void
    {
        self::$seen = [];
    }

    public function __invoke(PingCommand $command): void
    {
        self::$seen[] = $command->message;
    }
}