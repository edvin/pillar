<?php

namespace Pillar\Metrics\Prometheus;

use Illuminate\Container\Attributes\Config;

/**
 * Central place to normalize metric names and expose the namespace.
 *
 * This keeps Prometheus naming rules in one place and avoids duplicating
 * the namespace string throughout the codebase.
 */
final class PrometheusNameFactory
{
    public function __construct(
        #[Config('pillar.metrics.prometheus.namespace')]
        private string $namespace = 'pillar',
    ) {}

    public function namespace(): string
    {
        return $this->namespace;
    }

    /**
     * Normalize a metric name to something Prometheus accepts.
     *
     * Prometheus allows [a-zA-Z_:][a-zA-Z0-9_:]* so we just replace
     * "bad" characters with underscores.
     */
    public function metricName(string $name): string
    {
        // You can choose to prefix or not; here we just normalize.
        $normalized = preg_replace('/[^a-zA-Z0-9_:]/', '_', $name);

        // Ensure it starts with a letter or underscore
        if (! preg_match('/^[a-zA-Z_]/', $normalized)) {
            $normalized = '_' . $normalized;
        }

        return $normalized;
    }
}