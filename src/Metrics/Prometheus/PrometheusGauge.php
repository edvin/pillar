<?php

namespace Pillar\Metrics\Prometheus;

use Pillar\Metrics\Gauge;

final class PrometheusGauge implements Gauge
{
    /**
     * @param array<string,string> $defaultLabels
     * @param array<int,string>    $labelNames
     */
    public function __construct(
        private \Prometheus\Gauge $inner,
        private array $defaultLabels = [],
        private array $labelNames = [],
    ) {
    }

    public function set(float $value, array $labels = []): void
    {
        $this->inner->set($value, $this->labelValues($labels));
    }

    public function inc(float $amount = 1.0, array $labels = []): void
    {
        $this->inner->incBy($amount, $this->labelValues($labels));
    }

    public function dec(float $amount = 1.0, array $labels = []): void
    {
        $this->inner->incBy(-$amount, $this->labelValues($labels));
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