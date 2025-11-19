<?php

namespace Pillar\Metrics\Prometheus;

use Pillar\Metrics\Histogram;

final class PrometheusHistogram implements Histogram
{
    /**
     * @param array<string,string> $defaultLabels
     * @param array<int,string>    $labelNames
     */
    public function __construct(
        private \Prometheus\Histogram $inner,
        private array $defaultLabels = [],
        private array $labelNames = [],
    ) {
    }

    public function observe(float $value, array $labels = []): void
    {
        $this->inner->observe($value, $this->labelValues($labels));
    }

    /**
     * @param array<string,string> $labels
     * @return array<int,string>
     */
    private function labelValues(array $labels): array
    {
        if ($this->labelNames === []) {
            return array_values($labels);
        }

        $all = $this->defaultLabels === []
            ? $labels
            : array_merge($this->defaultLabels, $labels);

        $values = [];
        foreach ($this->labelNames as $name) {
            $values[] = array_key_exists($name, $all) ? (string) $all[$name] : '';
        }

        return $values;
    }
}