<?php

namespace Tests\Fixtures\Bus;

final class NotInvokableHandler
{
    // Intentionally no __invoke(); container can build it, but it's not callable
    public function handle(NotInvokableQuery $q): string
    {
        return 'handled:' . $q->payload;
    }
}