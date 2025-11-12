<?php
declare(strict_types=1);

namespace Pillar\Outbox\Worker;

use Illuminate\Support\Str;

final class WorkerIdentity
{
    public function __construct(
        public readonly string  $id,
        public readonly ?string $hostname = null,
        public readonly ?int    $pid = null,
    )
    {
    }
}