

# Metrics

Pillar includes first‑class support for application‑level metrics. These metrics give you deep visibility into
what your system is doing—how often commands run, how quickly queries execute, how many events are appended,
how the outbox worker behaves, how replays progress, and more.

Pillar's metrics system is **optional**, lightweight, and designed to work seamlessly in highly concurrent
multi‑process environments. When enabled, Pillar emits a rich set of counters and histograms suitable for
scraping by Prometheus.

---

## Overview

Pillar exposes metrics through a pluggable `Metrics` interface with two drivers:

- `prometheus` — backed by [`promphp/prometheus_client_php`](https://github.com/promphp/prometheus_client_php),
  optionally using Redis for cross‑process aggregation.
- `none` — a noop metrics implementation used when metrics are disabled or the client library is not installed.

The driver is configured using:

```php
pillar.metrics.driver = 'prometheus' // or 'none'
```

When `prometheus` is selected, Pillar automatically discovers whether the Prometheus client is installed.
If the dependency is missing, Pillar logs a warning and transparently falls back to `NullMetrics`.


The metrics driver is safe to enable unconditionally: metrics do nothing unless the
Prometheus library is available.

### Default Labels

Pillar allows you to define *default labels* that apply to every metric emitted by the
Prometheus driver. These labels are useful for attaching high‑level context such as the
application name, environment, region, or deployment identifier.

Default labels are configured in `config/pillar.php`:

```php
'prometheus' => [
    'default_labels' => [
        'app' => env('APP_NAME', 'laravel'),
        'env' => env('APP_ENV', 'local'),
    ],
],
```

These are automatically merged into every metric's labels.
Metric‑specific labels continue to work as normal, and default labels always come first
in label ordering.

If you do not define any default labels, the metrics driver simply omits them.

---

## Process Model Considerations

### PHP‑FPM (in‑memory storage)

If you configure the Prometheus client for **in‑memory storage** under PHP‑FPM:

- Each FPM worker maintains **its own independent metric state**.
- A `/metrics` scrape only exposes metrics from the worker handling the request.
- Worker recycling resets that worker's metrics.

For this reason, in‑memory storage is **not recommended in production** under PHP‑FPM.
It is suitable for tests or single‑process CLI tools.

### Redis storage (recommended)

If you configure Prometheus to use Redis:

- All PHP‑FPM workers, queue workers, and outbox workers push metrics into the **same shared Redis store**.
- Aggregation is handled centrally.
- Scraping the web process exposes combined metrics from the whole system.

This is the preferred setup in production.

---

## Where Metrics Are Emitted

Pillar instruments the execution flow across several core components:

### Event Store
- **`EventStoreRepository`**: appends, aggregate loads, snapshot loads/saves.
- **`DatabaseEventStore`**: concurrency conflicts, `getByGlobalSequence`, and `recent()` queries.

### Outbox
- **`DatabaseOutbox`**: enqueued, claimed, published, failed messages.
- **`WorkerRunner`**: per‑message dispatch success/failure, per‑tick execution, tick duration.

### Command and Query Buses
- **`LaravelCommandBus`**: total commands, command failures, and execution duration.
- **`InMemoryQueryBus`**: total queries, query failures, and execution duration.

### Event Replay
- **`EventReplayer`**: replay starts, replayed events, replay failures.

If you replace any of these components with your own implementations,
you are responsible for emitting metrics in the same places. Pillar's built‑in versions are
useful references for which metrics to emit and how to label them.

---

## Metrics Reference

Below is a complete list of all metrics emitted by Pillar, grouped by subsystem.
All metric names are prefixed internally to avoid collisions, but listed here without
namespace for clarity.

### Event Store Metrics

| Metric | Type | Labels | Description |
|-------|------|--------|-------------|
| `eventstore_appends_total` | counter | `aggregate_type` | Number of events appended for aggregates. |
| `eventstore_load_total` | counter | `aggregate_type` | Number of times an aggregate is loaded. |
| `eventstore_snapshot_load_total` | counter | `aggregate_type`, `hit` | Snapshot loads, with hit/miss label. |
| `eventstore_snapshot_save_total` | counter | `aggregate_type` | Snapshots saved by the snapshot policy. |
| `eventstore_conflicts_total` | counter | `stream_id` | Optimistic locking failures during append. |
| `eventstore_get_by_sequence_total` | counter | `found` | Reads by global sequence number, labelled by hit/miss. |
| `eventstore_recent_queries_total` | counter | — | Number of calls to `recent()`. |

### Outbox Metrics

| Metric | Type | Labels | Description |
|--------|------|---------|-------------|
| `outbox_enqueued_total` | counter | `partition` | Messages added to the outbox. |
| `outbox_claimed_total` | counter | `partition` | Messages claimed by a worker for processing. |
| `outbox_published_total` | counter | `partition` | Successfully published messages. |
| `outbox_failed_total` | counter | `partition` | Messages that failed and were rescheduled. |
| `outbox_dispatch_total` | counter | `success` | Delivery attempts inside `WorkerRunner`. |
| `outbox_tick_total` | counter | `empty` | Worker ticks (empty vs work found). |
| `outbox_tick_duration_seconds` | histogram | — | Duration of a worker tick. |

### Command Bus Metrics

| Metric | Type | Labels | Description |
|--------|------|---------|-------------|
| `commands_total` | counter | `command`, `success` | Total commands dispatched, labelled by success/failure. |
| `commands_failed_total` | counter | `command` | Commands that threw exceptions. |
| `command_duration_seconds` | histogram | `command` | Execution time for command handlers. |

### Query Bus Metrics

| Metric | Type | Labels | Description |
|--------|------|---------|-------------|
| `queries_total` | counter | `query`, `success` | Total queries executed, labelled by success/failure. |
| `queries_failed_total` | counter | `query` | Queries that threw exceptions. |
| `query_duration_seconds` | histogram | `query` | Execution time for query handlers. |

### Replay Metrics

| Metric | Type | Labels | Description |
|--------|------|---------|-------------|
| `replay_started_total` | counter | — | Replays initiated. |
| `replay_events_processed_total` | counter | — | Total events processed during replays. |
| `replay_failed_total` | counter | — | Listener failures during replay. |

---

## Exporting Metrics

Pillar does not force you to expose metrics in any particular way. A typical Laravel project
adds a route such as:

```php
Route::get('/metrics', function () {
    return response()->make(app(\Prometheus\RenderTextFormat::class)
        ->render(app(\Prometheus\CollectorRegistry::class)->getMetricFamilySamples()));
});
```

Or uses a dedicated controller if preferred.

If Redis storage is used, all metrics from workers, FPM processes, web requests, and CLI commands
are aggregated automatically.

---

## Notes for Custom Implementations

If you provide your own implementations of any of Pillar’s interfaces—such as `CommandBusInterface`,
`QueryBusInterface`, `EventStore`, `Outbox`, or projector runners—Pillar will not automatically
instrument them. You are responsible for emitting the metrics you care about.

The built‑in instrumented classes serve as the canonical examples.

---

## Example: How Default Labels Appear in Output

If you enable default labels in your `pillar.php` config:

```php
'default_labels' => [
    'app' => 'myapp',
    'env' => 'production',
],
```

a Prometheus scrape will look like:

```text
eventstore_appends_total{app="myapp",env="production",aggregate_type="User"} 42
eventstore_conflicts_total{app="myapp",env="production",stream_id="user-123"} 1
outbox_published_total{app="myapp",env="production",partition="default"} 532
```

Default labels appear first and are applied consistently to every metric emitted by Pillar.

---

## Defining Custom Metrics

You can emit your own metrics anywhere in your application by type-hinting the `Metrics` interface:

```php
use Pillar\Metrics\Metrics;

class MyService
{
    public function __construct(private Metrics $metrics) {}

    public function doWork(): void
    {
        $this->metrics->counter('myservice_operations_total', ['result'])
            ->inc(1, ['result' => 'success']);
    }
}
```

Custom metrics automatically receive default labels (if configured) and follow the same naming rules as internal metrics.

---

## Testing With Metrics

During tests, Pillar will typically use the `none` driver (NullMetrics), which makes all metric calls no-ops.
If you want to assert against metrics in tests, you can bind your own in-memory implementation of `Metrics`:

```php
use Pillar\Metrics\Metrics;

class TestMetrics implements Metrics
{
    public array $counters = [];

    public function counter(string $name, array $labelNames = []): \Pillar\Metrics\Counter
    {
        // return a simple Counter implementation that records calls into $this->counters
    }

    public function histogram(string $name, array $labelNames = []): \Pillar\Metrics\Histogram
    {
        // implement if needed
    }

    public function gauge(string $name, array $labelNames = []): \Pillar\Metrics\Gauge
    {
        // implement if needed
    }
}

$this->app->instance(Metrics::class, new TestMetrics());

// run code under test and then inspect TestMetrics::$counters
```

This keeps your test environment deterministic and free from Redis or other shared state while still letting you assert on metric behaviour.

## Summary

Pillar's metrics system is designed to give you production‑grade observability with minimal setup.
It works safely in both development and multi‑process production environments,
provides rich insight into command handling, queries, event storage, the outbox and workers,
and is fully optional.

As your application grows, you can extend metrics emission to your own subsystems or custom infrastructure.