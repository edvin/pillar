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

- 🧠 **Aggregate sessions (Unit of Work)** — `find()`, mutate, `commit()`
- 🗃️ **Pluggable event store** with **generator‑based** streams & optimistic locking
- 🧵 **Fetch strategies** (load‑all / chunked / streaming)
- 🧬 **Versioned events** & **upcasters**
- 💾 **Snapshotting** policies (Always / Cadence / On‑Demand)
- 🧩 **Object serialization** — JSON by default, MessagePack built-in, or custom serializer
- 🔒 **Payload encryption** — pluggable cipher, per‑event overrides
- 🖥️ **Event stream browser Web UI** — browse streams and timelines and inspect payloads
- ⏱️ **Point‑in‑time reads** — load up to aggregate/global sequence or date via `EventWindow`
- 🎭 **Aliases** for readable event names
- 🔁 **Safe replays** to rebuild projections
- ⚡ **CQRS** — projectors and query bus for a fast, scalable read side
- 🧰 **Facade + buses** for quick wiring
- 🛠️ **Pillar Make**: Bounded Context/Command/Query Scaffolding

## Use‑cases

- Systems that need an **audit trail** of business decisions and changes.
- Collaborative domains with **long‑lived aggregates** (documents, orders, accounts).
- **Integrations** that prefer *events as the source of truth* and read models for queries.
- Legacy apps where you want to **introduce event sourcing gradually** in critical areas.

## Next steps

- Start with the [Getting started](/getting-started/) guide.
- Then read the core concepts in this order: **Aggregate roots → Aggregate IDs → Aggregate sessions**.
- Explore the [Event Store](/event-store/) and [Snapshotting](/concepts/snapshotting) when you outgrow simple cases.
