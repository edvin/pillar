<?php
// @codeCoverageIgnoreStart

namespace Pillar\Metrics;

final class NullCounter implements Counter
{
    public function inc(float $amount = 1.0, array $labels = []): void
    {
    }
}
// @codeCoverageIgnoreEnd