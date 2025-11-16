## 🔁 Event Replay Command

Replays stored domain events to rebuild projections. This command **never writes new events** — it only re‑dispatches already stored events to your read‑model projectors. Only listeners implementing **`Projector`** are invoked; command handlers and reactors are not run.

---

### Usage

```bash
php artisan pillar:replay-events
php artisan pillar:replay-events {aggregate_id}
php artisan pillar:replay-events {aggregate_id} {event_type}
php artisan pillar:replay-events null {event_type}
```

### Arguments

- **`aggregate_id`** (optional) — UUID of a specific aggregate.  
  Use the literal `null` to target **all** aggregates when also filtering by event type.
- **`event_type`** (optional) — Fully‑qualified event class name to replay (e.g. `App\\Events\\DocumentRenamed`).

### Options

- **`--from-seq=`** _int_ — Inclusive lower bound on **global sequence**.
- **`--to-seq=`** _int_ — Inclusive upper bound on **global sequence**.
- **`--from-date=`** _datetime_ — Inclusive lower bound on **occurred_at (UTC)**.
- **`--to-date=`** _datetime_ — Inclusive upper bound on **occurred_at (UTC)**.


Dates accept ISO‑8601 (recommended) or anything Carbon parses. Always interpreted as **UTC**.

#### How it works (under the hood)

- Builds an **EventWindow** from your bounds (sequence and/or date). All bounds are **inclusive**.
- Streams events from the **Event Store** using your configured **fetch strategy**.
- Dispatches each event to registered **Projectors** only (no command handlers / reactors).

See: [Event Store](/concepts/event-store) and [Fetch strategies](/concepts/fetch-strategies).


---

### Windows (sequence/time)

Constrain by **global sequence** and/or **occurred_at (UTC)**:

```bash
# Sequence window (inclusive)
php artisan pillar:replay-events --from-seq=1000 --to-seq=2000

# Date window (inclusive, UTC). ISO-8601 or anything Carbon parses.
php artisan pillar:replay-events --from-date="2025-01-01T00:00:00Z" --to-date="2025-01-31T23:59:59Z"

# Combine with aggregate and event type
php artisan pillar:replay-events 3f2ca9d8-4e0b-4d1b-a1d5-4c1b9f0f1f2e \
  App\\Events\\DocumentRenamed \
  --from-date="2025-01-01" --to-seq=50000
```

---

### Examples

```bash
# Replay everything
php artisan pillar:replay-events

# Replay a single aggregate
php artisan pillar:replay-events 3f2ca9d8-4e0b-4d1b-a1d5-4c1b9f0f1f2e

# Replay all "DocumentRenamed" events across all aggregates
php artisan pillar:replay-events null App\\Events\\DocumentRenamed

# Replay only events in a sequence window
php artisan pillar:replay-events --from-seq=25000 --to-seq=30000

# Replay only events in a date window (UTC)
php artisan pillar:replay-events --from-date="2025-02-01T00:00:00Z" --to-date="2025-02-28T23:59:59Z"
```

---

### Notes

- All bounds are **inclusive**.
- Dates are parsed and compared in **UTC** against each event’s `occurred_at`.
- The `--to-seq` upper bound can **short‑circuit** early since the stream is ordered by global `sequence`.
- Exit codes: **0** on success, **1** on failure.
- Use the literal `null` for the first argument to mean “all aggregates” when also specifying an `event_type`.