# Outbox Worker (CLI & UI)

The worker delivers publishable events from the [Transactional Outbox](/concepts/outbox) to your bus with retries.

---

## Command

```bash
php artisan pillar:outbox:work   [--no-leasing] [--once] [--json] [--silent]   [--interval-ms=0] [--window=0]
```

**Options**

- `--no-leasing` – Disable partition leasing (single‑worker mode). Best for local dev.
- `--once` – Run a single tick and exit (useful in tests / cron‑style runs).
- `--json` – Emit one JSON line per tick (structured logs).
- `--silent` – Run without printing anything.
- `--interval-ms` – Extra sleep between ticks; default 0ms.
- `--window` – Aggregate stats over N seconds in interactive mode (shows running totals & next refresh timer).

---

## Interactive UI

When run in a TTY, the command renders a compact UI showing:

- **Summary**: avg tick duration (or last), last heartbeat age, active workers, next refresh timer, tick count in
  window.
- **Throughput**: claimed / published / failed in the current tick or window, purge count, last backoff.
- **Partitions**: desired (target), owned, lease attempts, released in the last tick.
- **Recent errors**: a small rolling buffer of the latest failures (time, sequence, message).

> The UI adapts to terminal width: three columns (wide), two columns (medium), or stacked sections (narrow).

```bash
php artisan pillar:outbox:work
```

![Dashboard](/outbox-worker-cli.png)

---

## Behavior highlights

- **Leasing**: with `leasing = true`, the runner divides partitions among active workers (stable modulo). It leases,
  renews, and releases partitions and heartbeats to remain active.
- **Fair work split**: the tick splits `batch_size` fairly across owned partitions.
- **Backoff**: when a tick processes nothing, the runner sleeps `idle_backoff_ms` before continuing (cooperative
  backoff).
- **Purge stale**: stale worker rows are purged opportunistically (rate‑limited via cache).

---

## Lease & claim internals (DB‑native, cooperative)

Pillar’s worker coordination is implemented **entirely in the database**—no separate coordinator service:

- **Cooperative leasing**: Each partition (e.g., `p00..p63`) has a lease row in `outbox_partitions`. Workers
  acquire/renew leases by updating `lease_owner` and `lease_until` **using the DB clock**. If a worker dies, leases
  naturally expire.
- **Dynamic discovery**: Active workers are tracked in `outbox_workers` with a `heartbeat_until` timestamp. The runner
  computes its **target partitions** via a stable modulo over the sorted list of active worker ids, so load **rebalances
  automatically** as workers join/leave.
- **Per‑partition ordering**: With leasing enabled, at most one worker processes a given partition at a time, preserving
  order within that partition.

### Database‑specific optimizations

To claim outbox rows, the worker uses the most efficient path for your driver:

- **PostgreSQL & SQLite**: Single‑statement `UPDATE … RETURNING` performs the selection and claim **atomically** and
  returns the claimed rows in one round‑trip.
- **MySQL**: A fast two‑step approach: a SELECT determines candidate ids; then an `UPDATE … JOIN` stamps a `claim_token`
  and bumps `available_at`. The batch is fetched by that token. Despite two statements, this is still **very performant
  ** with proper indexes.

> All paths use DB‑derived timestamps to avoid app clock drift between worker nodes.

### Claim vs. lease

- **Leases** (in `outbox_partitions`) control **which partitions** a worker is allowed to pull from.
- **Claims** (in `outbox`) are short, per‑row holds (via `claim_token` / `available_at` bump) that prevent duplicate
  delivery while a batch is being processed.

---

## Configuration reference

See the **full list of options** (partitioning, worker timings, table names, partitioner strategy) in the configuration
docs: [/configuration#outbox](/configuration#outbox).

---

## Output modes

- Human UI (default, TTY)
- One‑line summary (non‑interactive, no `--json`)
- JSON per tick (`--json`), containing counts, durations, and partition info

Example JSON line:

```json
{
  "claimed": 10,
  "published": 10,
  "failed": 0,
  "duration_ms": 3.12,
  "backoff_ms": 0,
  "renewed_heartbeat": true,
  "purged_stale": 0,
  "active_workers": 1,
  "desired_count": 64,
  "owned_count": 64,
  "leased_count": 0,
  "released_count": 0,
  "ts": "2025-11-12T00:00:00Z"
}
```

---

## Operational tips

- Keep handler side‑effects **idempotent** (outbox is at‑least‑once).
- For single instance deployments, you can **disable leasing** and just run one worker.
- If you scale horizontally, set `partition_count` to a power of two and let workers auto‑balance via leasing.
- Use `--window` to get more meaningful throughput numbers in the interactive UI.

---

## Related docs

- **Transactional Outbox** → [Outbox](/concepts/outbox)
- **Outbox Worker (CLI)** → [Outbox Worker](/concepts/outbox-worker)