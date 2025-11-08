# Why Pillar

Pillar helps you build **rich domain models** and **event‑sourced systems** in Laravel — without forcing a rigid, heavyweight project layout.

## Pragmatic DDD, not dogma

- Pillar **supports** classic DDD patterns (aggregates, repositories, commands/queries, upcasters, snapshotting), but it **doesn’t force** a complex package/module structure.
- Start from your current codebase and adopt the parts you need. Keep controllers + Eloquent where they make sense; introduce aggregates where the domain benefits.

## Fits non‑DDD apps, too

Use Pillar just for **auditing** or for **event‑driven islands** inside a conventional app:

- Model a few **event‑sourced aggregates** to capture important business facts.
- Keep the rest state‑based with a custom repository — both styles live side‑by‑side.
- Use **projectors** to maintain read models and denormalized views for queries and dashboards.

## Performance by design

- **Generator‑based streams** in the event store enable *true streaming* of large histories.
- Pluggable **fetch strategies** (load‑all, chunked, streaming) so you can tune per aggregate.
- **Snapshotting** policies (Always / Cadence / On‑Demand) avoid long replays.
- **Optimistic concurrency** handled by the session; no extra round‑trips.
- **Reflection metadata caching** in the default serializer for fast (de)serialization.

## Solid feature set

- 🧠 **Aggregate sessions (Unit of Work)** with `commit()`
- 🗃️ **Event Store** abstraction + **Stream Resolver** (multi‑tenancy/sharding ready)
- 🧵 **Fetch strategies** per aggregate
- 🧬 **Versioned events** + **Upcasters**
- 💾 **Snapshotting** policies and pluggable stores
- 🎭 **Aliases** for human‑readable event names
- 🧰 **Facade + buses** for quick wiring
- 🔁 **Replay command** that only runs **Projectors** (safe replays)

## Use‑cases

- Systems that need an **audit trail** of business decisions and changes.
- Collaborative domains with **long‑lived aggregates** (documents, orders, accounts).
- **Integrations** that prefer *events as the source of truth* and read models for queries.
- Legacy apps where you want to **introduce event sourcing gradually** in critical areas.

## Next steps

- Start with the [Getting started](/getting-started/) guide.
- Then read the core concepts in this order: **Aggregate roots → Aggregate IDs → Aggregate sessions**.
- Explore the [Event Store](/event-store/) and [Snapshotting](/concepts/snapshotting) when you outgrow simple cases.
