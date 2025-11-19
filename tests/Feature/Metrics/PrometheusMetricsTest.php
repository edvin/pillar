<?php

use Illuminate\Support\Facades\Config;
use Pillar\Metrics\Metrics;
use Pillar\Metrics\Prometheus\CollectorRegistryFactory;
use Pillar\Metrics\Prometheus\PrometheusMetrics;
use Pillar\Provider\PillarServiceProvider;
use Prometheus\Storage\Redis as RedisAdapter;

function configurePrometheusMetrics(string $namespace = 'pillar_test', array $defaultLabels = [
    'app' => 'pillar-tests',
    'env' => 'testing',
], string $storage = 'in_memory'): Metrics {
    Config::set('pillar.metrics.driver', 'prometheus');
    Config::set('pillar.metrics.prometheus.namespace', $namespace);
    Config::set('pillar.metrics.prometheus.default_labels', $defaultLabels);
    Config::set('pillar.metrics.prometheus.storage.driver', $storage);

    (new PillarServiceProvider(app()))->register();
    app()->forgetInstance(Metrics::class);

    /** @var Metrics $metrics */
    $metrics = app(Metrics::class);

    return $metrics;
}

function configureMetricsDriver(string $driver): Metrics
{
    Config::set('pillar.metrics.driver', $driver);

    (new PillarServiceProvider(app()))->register();
    app()->forgetInstance(Metrics::class);

    /** @var Metrics $metrics */
    $metrics = app(Metrics::class);

    return $metrics;
}

function renderPrometheusOutput(): string
{
    $registryFactory = app(\Pillar\Metrics\Prometheus\CollectorRegistryFactory::class);
    $registry = $registryFactory->get();

    $renderer = new \Prometheus\RenderTextFormat();

    return $renderer->render($registry->getMetricFamilySamples());
}

it('uses the prometheus driver when configured', function () {
    // Arrange: enable Prometheus metrics with in-memory storage
    $metrics = configurePrometheusMetrics();

    expect($metrics)->toBeInstanceOf(PrometheusMetrics::class);

    // Act: increment a counter
    $counter = $metrics->counter('prometheus_metrics_test_total', ['label']);
    $counter->inc(1, ['label' => 'value']);

    // Assert: ensure we can render metrics without error
    $familiesOutput = renderPrometheusOutput();

    expect($familiesOutput)->toContain('pillar_test_prometheus_metrics_test_total');
});

it('applies default labels and metric labels', function () {
    // Arrange
    $metrics = configurePrometheusMetrics();

    // Act
    $counter = $metrics->counter('prometheus_metrics_labels_total', ['label']);
    $counter->inc(1, ['label' => 'value']);

    $output = renderPrometheusOutput();

    // Assert: metric name and all labels are present in the exposition
    expect($output)->toContain('pillar_test_prometheus_metrics_labels_total');
    expect($output)->toContain('app="pillar-tests"');
    expect($output)->toContain('env="testing"');
    expect($output)->toContain('label="value"');
});

it('uses NullMetrics when driver is none', function () {
    // Arrange
    $metrics = configureMetricsDriver('none');

    expect($metrics)->toBeInstanceOf(\Pillar\Metrics\NullMetrics::class);

    // And calling methods should not throw
    $metrics->counter('noop')->inc();
    $metrics->histogram('noop')->observe(0.1);
    $metrics->gauge('noop')->set(1.0);
});

it('records histogram and gauge metrics', function () {
    // Arrange
    $metrics = configurePrometheusMetrics();

    // Act
    $histogram = $metrics->histogram('prometheus_metrics_histogram_test_seconds', ['label']);
    $histogram->observe(0.5, ['label' => 'value']);

    $gauge = $metrics->gauge('prometheus_metrics_gauge_test', ['label']);
    $gauge->set(3.14, ['label' => 'value']);

    $output = renderPrometheusOutput();

    // Assert: both metrics are present in the rendered output
    expect($output)->toContain('pillar_test_prometheus_metrics_histogram_test_seconds');
    expect($output)->toContain('pillar_test_prometheus_metrics_gauge_test');
});

it('omits default labels when none are configured', function () {
    // Arrange
    $metrics = configurePrometheusMetrics('pillar_nolabels', []);

    // Act
    $counter = $metrics->counter('prometheus_metrics_nolabels_total', ['label']);
    $counter->inc(1, ['label' => 'value']);

    $output = renderPrometheusOutput();

    // Assert: metric name and explicit label are present, but no default labels
    expect($output)->toContain('pillar_nolabels_prometheus_metrics_nolabels_total');
    expect($output)->toContain('label="value"');
    expect($output)->not()->toContain('app="');
    expect($output)->not()->toContain('env="');
});

it('throws when redis driver is selected but ext-redis is not installed', function () {
    if (class_exists(\Redis::class)) {
        $this->markTestSkipped('ext-redis is installed, cannot test missing-extension branch.');
    }

    $factory = new CollectorRegistryFactory(
        namespace: 'pillar',
        driver: 'redis',
    );

    expect(fn () => invokePrivateMethod($factory, 'createStorageAdapter'))
        ->toThrow(
            \RuntimeException::class,
            "Prometheus storage driver 'redis' selected, but ext-redis is not installed.",
        );
});

it('creates a Redis adapter when redis driver is configured and ext-redis is available', function () {
    if (!class_exists(\Redis::class)) {
        $this->markTestSkipped('ext-redis is not installed, cannot test redis adapter creation.');
    }

    $factory = new CollectorRegistryFactory(
        namespace: 'pillar',
        driver: 'redis',
        redisHost: '127.0.0.1',
        redisPort: 6379,
        redisTimeout: 0.1,
        redisAuth: null,
        redisDatabase: 0,
    );

    try {
        /** @var \Prometheus\Storage\Adapter $adapter */
        $adapter = invokePrivateMethod($factory, 'createStorageAdapter');
    } catch (\RedisException $e) {
        $this->markTestSkipped('Redis server not available: ' . $e->getMessage());
    }

    expect($adapter)->toBeInstanceOf(RedisAdapter::class);
});

/**
 * Helper to invoke a private method on the factory.
 *
 * @param array<int, mixed> $args
 */
function invokePrivateMethod(object $object, string $method, array $args = []): mixed
{
    $ref = new ReflectionClass($object);
    $m = $ref->getMethod($method);
    $m->setAccessible(true);

    return $m->invokeArgs($object, $args);
}
