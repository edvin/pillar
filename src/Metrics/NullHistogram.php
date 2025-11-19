<?php

namespace Pillar\Metrics;

class NullHistogram implements Histogram
{
    public function observe(float $value, array $labels = []): void
    {
    }
}