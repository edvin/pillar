<?php

namespace Tests\Fixtures\Bus;

final class EchoHandler
{
    public function __invoke(EchoQuery $query): string
    {
        return 'echo:' . $query->input;
    }
}