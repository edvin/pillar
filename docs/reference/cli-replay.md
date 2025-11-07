## 🔁 Event Replay Command

Replays stored domain events to rebuild projections. Only listeners implementing `Projector` are invoked during replay (
no side‑effects).

### Usage

```bash
php artisan pillar:replay-events
php artisan pillar:replay-events {aggregate_id}
php artisan pillar:replay-events {aggregate_id} {event_type}
php artisan pillar:replay-events null {event_type}
```

### Filters

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

**Notes**

- Bounds are **inclusive**.
- Dates are parsed and compared in **UTC** against each event’s `occurred_at`.
- The `--to-seq` upper bound short‑circuits early since the `all()` stream is ordered by global `sequence`.
- Pass `null` as the first positional argument to mean “all aggregates”.