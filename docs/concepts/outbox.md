# Transactional Outbox

Reliable, at‑least‑once publication for your domain events—without leaving the safety of your database transaction.

---

## Why an outbox?

- You persist the aggregate & its events **and** enqueue publishable events **in the same DB transaction**.
- A worker claims rows and delivers events to your bus with retries.
- This avoids dual‑write problems and keeps your write path consistent.

See also [Events](/concepts/events) and [Outbox](/concepts/outbox).

> **Scope**: Pillar’s outbox stores **pointers** (global event sequence) rather than duplicating payloads. The worker rehydrates events via the `EventStore` when delivering.

---

## How it works (flow)

1. Your aggregate records events.
2. On save, **all** events are persisted to the `events` table.
3. Events that implement **`ShouldPublish`** are **enqueued** into the `outbox` table (same transaction), with an optional `partition_key` for sharding (see also [Aggregate Roots](/concepts/aggregate-roots) and [Events](/concepts/events)).
4. A background worker periodically **claims** pending rows, **rehydrates** events via `EventStore::getByGlobalSequence($seq)`, **dispatches** them to the bus, and marks rows **published**.
5. Failures increment `attempts`, capture `last_error`, and retry after `retry_backoff` seconds.

**Delivery**: at‑least‑once. Make handlers [idempotent](/concepts/projectors).

---

## Schema (reference)

Your initial migration should create the outbox as a pointer table (see also /event-store):

```php
Schema::create('outbox', function (Blueprint $t) {
    $t->unsignedBigInteger('global_sequence')->primary(); // = events.sequence
    $t->unsignedInteger('attempts')->default(0);
    $t->timestamp('available_at')->useCurrent();
    $t->timestamp('published_at')->nullable();
    $t->string('partition_key', 64)->nullable();
    $t->string('claim_token', 36)->nullable(); // batch-claim token (MySQL/generic path)
    $t->text('last_error')->nullable();

    $t->index(['published_at', 'available_at']);
    $t->index(['partition_key', 'published_at', 'available_at']);
    $t->foreign('global_sequence')->references('sequence')->on('events');
});
```

- **Pointer‑only**: no payload duplication.
- **Claim token**: used to atomically identify the rows a worker claimed in this batch (on DBs without `UPDATE … RETURNING`).

On PostgreSQL, the `outbox` table can also be a **partitioned table** (for example,  
range-partitioned by `available_at` or hash-partitioned by `partition_key`). Pillar  
always talks to the logical table name from `pillar.outbox.tables.outbox`, so Postgres  
routes reads and writes to the correct partition transparently.

---

## Claiming strategy

- **Postgres / SQLite**: a single `UPDATE … RETURNING` both claims and returns the rows.
- **MySQL / generic**: `SELECT` candidate ids → batch `UPDATE` (set `claim_token`, bump `available_at`) → `SELECT` rows where `claim_token = $token`.

In all cases, database time is used (portable helpers) to avoid clock skew between workers and the DB.

Events are rehydrated from the [EventStore](/concepts/event-store/index).

---

## Partitioning (ordering & scale)

- Configure `partition_count` (e.g., 16). Each outbox row may include a `partition_key` like `p07`.
- Each partition is processed by **at most one worker at a time**, providing **ordering guarantees per partition**.
- The default **CRC32** [partitioner](/concepts/outbox) maps an aggregate or stream id to a bucket label `pNN`.
- You can swap the partitioner to route by tenant, context, or any business key that matters for ordering.

When leasing is enabled, workers coordinate to divide partitions among themselves using a DB‑backed lease table.

---

## Configuration

Excerpt from `config/pillar.php`:

```php
'outbox' => [
    // 🧩 Partitioning
    'partition_count' => 16,

    // 👷 Worker runtime
    'worker' => [
        'leasing'        => true,
        'lease_ttl'      => 15,
        'lease_renew'    => 6,
        'heartbeat_ttl'  => 20,
        'batch_size'     => 100,
        'idle_backoff_ms'=> 1000,
        'claim_ttl'      => 15,
        'retry_backoff'  => 60,
    ],

    // 🗄️ Table names
    'tables' => [
        'outbox'     => 'outbox',
        'partitions' => 'outbox_partitions',
        'workers'    => 'outbox_workers',
    ],

    // 🧮 Partitioner strategy
    'partitioner' => [
        'class' => \Pillar\Outbox\Crc32Partitioner::class,
    ],
],
```

> The outbox is used automatically when `PublicationPolicy` says an event should be published (e.g., implements `ShouldPublish`).

---

## Worker coordination (leasing)

When [`leasing`](/concepts/outbox-worker) = true, workers share partitions via a DB table (e.g., `outbox_partitions`). A lease has:

- `partition_key`, `lease_owner`, `lease_until`, `lease_epoch`, `updated_at`

Runner behavior:

- The runner discovers **active workers** (from `outbox_workers`, based on `heartbeat_until`).
- Using a stable modulo over worker ids, each worker computes the **target set** of partitions it *should* own.
- It **tries** to lease those partitions. If successful, it **renews** leases periodically and **releases** partitions it no longer targets.
- With **leasing = false**, there’s no partition restriction—claims can interleave across partitions (no per‑partition ordering).

---

## UI: Outbox Monitor

If the Pillar UI is enabled, you can inspect workers, partitions and outbox messages:

![Dashboard](/outbox-monitor.png)

```
`/{pillar.ui.path}/outbox` (default: `/pillar/outbox`)
```

The `{pillar.ui.path}` comes from your UI config (`pillar.ui.path`). The page shows:

- **Active workers** with heartbeats and TTL
- **Throughput** for the last 60 minutes
- **Partition leases** (who owns what, TTLs)
- **Outbox messages** (Pending / Published / Failed views)

For configuration options, see the [configuration → Outbox](/reference/configuration#outbox) section.

## CLI: Outbox Worker

See **`outbox-worker.md`** for the full CLI and UI. Quick start:

```bash
php artisan pillar:outbox:work
```

Useful flags:

- `--no-leasing` – single‑worker mode (no leasing)
- `--once` – run a single tick and exit
- `--json` – emit one JSON line per tick (good for logs)
- `--silent` – run without printing
- `--interval-ms=50` – add sleep between ticks
- `--window=5` – aggregate interactive stats over a 5s window

---

## Failure & retries

- On failure: `attempts += 1`, `last_error` is captured, `available_at` is moved forward by `retry_backoff` seconds.
- The message remains pending until published; after successful publication, `published_at` is set and `claim_token` cleared.
- **At‑least‑once**: Handlers must be idempotent.

---

## Replay interaction

During replay, publication is disabled (`EventContext::isReplaying()`): outbox remains silent and your projectors receive events directly from the replayer.

---

## Extending

- Swap the **Partitioner** to route by tenant, context, or business key.
- Replace the **PublicationPolicy** to recognize custom attributes/annotations.
- Implement a custom **Outbox** if you want a different storage (e.g., message broker)—keep pointer semantics if you want consistent rehydration behavior.

---

## See also

- **Events & marker interfaces** → [Events](/concepts/events)
- **Outbox Worker (CLI/UI)** → [Outbox Worker](/concepts/outbox-worker)
- **Outbox** → [Outbox](/concepts/outbox)
- **EventStore** → [EventStore](/concepts/event-store/index)
