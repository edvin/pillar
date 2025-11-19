<?php

namespace Pillar\Metrics;

interface Counter
{
    public function inc(float $amount = 1.0, array $labels = []): void;
}