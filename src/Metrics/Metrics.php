<?php

namespace Pillar\Metrics;

interface Metrics
{
    public function counter(string $name, array $labelNames = []): Counter;

    public function histogram(string $name, array $labelNames = []): Histogram;

    public function gauge(string $name, array $labelNames = []): Gauge;
}