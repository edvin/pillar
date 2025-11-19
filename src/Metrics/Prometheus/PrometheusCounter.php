<?php

namespace Pillar\Metrics\Prometheus;

use Pillar\Metrics\Counter;

final class PrometheusCounter implements Counter
{
    /**
     * @param array<string,string> $defaultLabels
     * @param array<int,string>    $labelNames
     */
    public function __construct(
        private \Prometheus\Counter $inner,
        private array $defaultLabels = [],
        private array $labelNames = [],
    ) {
    }

    public function inc(float $amount = 1.0, array $labels = []): void
    {
        $this->inner->incBy($amount, $this->labelValues($labels));
    }

    /**
     * Build label values in the exact order the metric was registered with.
     *
     * promphp/prometheus_client_php expects a positional array of values whose
     * length and order match the label names passed to getOrRegisterCounter().
     *
     * We merge default labels (from config) with metric-specific labels and then
     * project the combined map onto the registered label name list.
     *
     * @param array<string,string> $labels
     * @return array<int,string>
     */
    private function labelValues(array $labels): array
    {
        // No explicit label names: fall back to positional values as-is.
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