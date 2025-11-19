<?php

namespace Pillar\Metrics\Prometheus;

use Pillar\Metrics\Metrics;
use Pillar\Metrics\Counter;
use Pillar\Metrics\Histogram;
use Pillar\Metrics\Gauge;
use Illuminate\Container\Attributes\Config;

final class PrometheusMetrics implements Metrics
{
    public function __construct(
        private CollectorRegistryFactory $registryFactory,
        private PrometheusNameFactory $nameFactory,
        #[Config('pillar.metrics.prometheus.default_labels')]
        private array $defaultLabels = [],
    ) {}

    public function counter(string $name, array $labelNames = []): Counter
    {
        $registry = $this->registryFactory->get();

        $metricName = $this->nameFactory->metricName($name);
        $allLabelNames = $this->labelNamesWithDefaults($labelNames);

        $inner = $registry->getOrRegisterCounter(
            $this->nameFactory->namespace(),
            $metricName,
            "Pillar metric: $metricName",
            $allLabelNames,
        );

        return new PrometheusCounter($inner, $this->defaultLabels, $allLabelNames);
    }

    public function histogram(string $name, array $labelNames = []): Histogram
    {
        $registry = $this->registryFactory->get();

        $metricName = $this->nameFactory->metricName($name);
        $allLabelNames = $this->labelNamesWithDefaults($labelNames);

        $inner = $registry->getOrRegisterHistogram(
            $this->nameFactory->namespace(),
            $metricName,
            "Pillar metric: {$metricName}",
            $allLabelNames,
        );

        return new PrometheusHistogram($inner, $this->defaultLabels, $allLabelNames);
    }

    public function gauge(string $name, array $labelNames = []): Gauge
    {
        $registry = $this->registryFactory->get();

        $metricName = $this->nameFactory->metricName($name);
        $allLabelNames = $this->labelNamesWithDefaults($labelNames);

        $inner = $registry->getOrRegisterGauge(
            $this->nameFactory->namespace(),
            $metricName,
            "Pillar metric: {$metricName}",
            $allLabelNames,
        );

        return new PrometheusGauge($inner, $this->defaultLabels, $allLabelNames);
    }

    /**
     * Merge configured default label names with metric-specific label names,
     * ensuring uniqueness and stable ordering.
     */
    private function labelNamesWithDefaults(array $labelNames): array
    {
        if ($this->defaultLabels === []) {
            return $labelNames;
        }

        $names = array_merge(array_keys($this->defaultLabels), $labelNames);

        return array_values(array_unique($names));
    }
}