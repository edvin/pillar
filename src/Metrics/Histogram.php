<?php

namespace Pillar\Metrics;

interface Histogram
{
    public function observe(float $value, array $labels = []): void;
}