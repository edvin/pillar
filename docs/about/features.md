# ✨ Features

Pillar brings Domain‑Driven Design and Event Sourcing to Laravel with a compact, expressive API.

- 🧠 **Aggregate sessions (Unit of Work)** — track loaded aggregates and persist changes atomically with `commit()`.  
  → See [/concepts/aggregate-sessions](/concepts/aggregate-sessions)

- 🧰 **Pillar facade** — shortcuts for `session()`, `dispatch()` (command bus) and `ask()` (query bus).  
  → See [/concepts/pillar-facade](/concepts/pillar-facade)

- 🧪 **Tinker superpowers** — automatic class aliases for Commands, Queries, and Aggregate IDs, plus rich aggregate casting for clean, human-readable inspection in `php artisan tinker`.  
  → See [/concepts/tinker-support](/concepts/tinker-support)

- 🗃️ **Event store abstraction** — optimistic locking and generator‑based streams.  
  → See [/concepts/event-store](/concepts/event-store)

- 🧵 **Event fetch strategies** — `db_load_all`, `db_chunked`, `db_streaming`, or plug in your own backend-specific strategy.  
  → See [/concepts/fetch-strategies](/concepts/fetch-strategies)

- 🧩 **Aggregate IDs & streams** — strongly-typed UUID IDs with readable stream names (e.g. `document-<uuid>`).  
  → See [/concepts/aggregate-ids](/concepts/aggregate-ids)

- 🎭 **Event aliases** — store stable, human‑readable names instead of class strings.  
  → See [/concepts/event-aliases](/concepts/event-aliases)

- 🧬 **Upcasters & versioned events** — evolve schemas safely; upcast older payloads on read.  
  → See [/concepts/event-upcasters](/concepts/event-upcasters) and [/concepts/versioned-events](/concepts/versioned-events)

- 💾 **Snapshotting (opt‑in)** — Always / Cadence / On‑Demand policies, pluggable store (default: cache).  
  → See [/concepts/snapshotting](/concepts/snapshotting)

- 🧱 **Repositories** — event‑sourced or state‑based per aggregate; swap via config.  
  → See [/concepts/repositories](/concepts/repositories)

- 🔁 **Safe replays** — only `Projector` listeners execute during `pillar:replay-events`.  
  → See [/concepts/projectors](/concepts/projectors) and [/reference/cli-replay](/reference/cli-replay)

- 🪶 **Serializer abstraction** — default JSON serializer; bring your own if you need a different format.  
  → See [/concepts/serialization](/concepts/serialization)

- 🛠️ **Pillar Make**: Bounded Context/Command/Query scaffolding
