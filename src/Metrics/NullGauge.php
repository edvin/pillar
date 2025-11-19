<?php
// @codeCoverageIgnoreStart

namespace Pillar\Metrics;

class NullGauge implements Gauge
{
    public function set(float $value, array $labels = []): void
    {
    }

    public function inc(float $amount = 1.0, array $labels = []): void
    {
    }

    public function dec(float $amount = 1.0, array $labels = []): void
    {
    }
}
// @codeCoverageIgnoreEnd