# Pillar health endpoint

Pillar provides a lightweight JSON health endpoint that you can use for
liveness/readiness checks (Kubernetes probes, load balancers, uptime
monitors, etc.). The endpoint is intentionally simple and read-only.

---

## Configuration

The health endpoint is configured in `config/pillar.php`:

```php
/*
|--------------------------------------------------------------------------
| ❤️ Pillar health check
|--------------------------------------------------------------------------
|
| Lightweight JSON health endpoint for Pillar internals. This is intended for
| readiness/liveness checks (Kubernetes probes, load balancers, uptime checks).
|
| enabled : Master switch. If false, the health route is not registered.
| path    : Absolute path where the health endpoint is mounted. This should
|           start with a leading slash. Default: "/pillar/health".
|
*/
'health' => [
    'enabled' => env('PILLAR_HEALTH_ENABLED', true),

    // Absolute path for the health endpoint. Example: "/pillar/health".
    // You can change this to anything that fits your app:
    //   PILLAR_HEALTH_PATH=/health/pillar
    'path' => env('PILLAR_HEALTH_PATH', '/pillar/health'),
],
```

- **`enabled`** — master switch. If set to `false`, the health route is not
  registered at all.
- **`path`** — absolute URL path where the endpoint is exposed. By default this
  is `/pillar/health`.

> **Note**  
> The health endpoint path is independent from the Pillar UI path. Changing
> `PILLAR_UI_PATH` does not affect `PILLAR_HEALTH_PATH`.

---

## Route and controller

When `pillar.health.enabled` is `true`, Pillar registers a single `GET` route
using the configured path:

```php
// routes/health.php

use Illuminate\Support\Facades\Route;
use Pillar\Health\Http\Controllers\HealthController;

Route::get(config('pillar.health.path', '/pillar/health'), HealthController::class)
    ->name('pillar.health');
```

The controller delegates to the `Pillar\Health\PillarHealth` service, which
runs a set of internal checks and returns a structured JSON response.

---

## Response format

The health endpoint returns a JSON body with an overall status and individual
checks:

```json
{
  "status": "ok",
  "checks": {
    "database": {
      "status": "ok",
      "details": null
    },
    "events_table": {
      "status": "ok",
      "details": null
    },
    "outbox_tables": {
      "status": "ok",
      "details": null
    },
    "metrics_backend": {
      "status": "ok",
      "details": "Prometheus metrics enabled using in-memory storage."
    }
  }
}
```

### Overall status

The top-level `status` summarises all checks:

- `ok` — all checks are healthy.
- `degraded` — at least one check reported `degraded`, but none are `down`.
- `down` — one or more checks are `down`.

### Per-check status

Each entry in `checks` has the form:

```json
"<name>": {
  "status": "ok|degraded|down|skipped",
  "details": "optional human-readable message or null"
}
```

The built-in checks are:

- **`database`** — can Pillar execute a simple query against the default
  database connection?
- **`events_table`** — does the configured events table exist?
- **`outbox_tables`** — do the configured outbox tables exist
  (`outbox`, `outbox_partitions`, `outbox_workers`)?
- **`metrics_backend`** — basic sanity checks for the configured metrics
  driver and storage (e.g. Redis reachability when using Prometheus + Redis).

### HTTP status codes

The HTTP status code is derived from the overall `status`:

- `200 OK` — when `status` is `ok` or `degraded`.
- `503 Service Unavailable` — when `status` is `down`.

This makes the endpoint suitable for both liveness and readiness probes.

---

## Example usage

### Kubernetes liveness / readiness probes

```yaml
livenessProbe:
  httpGet:
    path: /pillar/health
    port: 80
  initialDelaySeconds: 10
  periodSeconds: 15

readinessProbe:
  httpGet:
    path: /pillar/health
    port: 80
  initialDelaySeconds: 5
  periodSeconds: 10
```

If you change the path via `PILLAR_HEALTH_PATH`, update these probes
accordingly.

### Simple load balancer health check

Most load balancers support HTTP health checks by status code. Point them at
`/pillar/health` and mark the instance healthy when the response is `200`.

---

## Security considerations

The health endpoint intentionally returns only high-level information and never
sensitive data (no secrets, no raw SQL, no stack traces). However, you may still
want to restrict access in some environments, for example to:

- Kubernetes nodes
- a private network
- your monitoring system

In that case, you can wrap the route in your own middleware or place it behind
infrastructure-level protections (ingress rules, IP allow-lists, etc.).

If you do not need the endpoint at all, set:

```env
PILLAR_HEALTH_ENABLED=false
```

Pillar will then skip registering the route entirely.