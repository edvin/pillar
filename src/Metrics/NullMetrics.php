<?php

namespace Pillar\Metrics;

final class NullMetrics implements Metrics
{
    public function counter(string $name, array $labelNames = []): Counter
    {
        return new NullCounter();
    }

    public function histogram(string $name, array $labelNames = []): Histogram
    {
        return new NullHistogram();
    }

    public function gauge(string $name, array $labelNames = []): Gauge
    {
        return new NullGauge();
    }
}