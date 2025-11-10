# 🧠 Pillar

[![Coverage](https://codecov.io/gh/edvin/pillar/branch/main/graph/badge.svg)](https://app.codecov.io/gh/edvin/pillar)

## Elegant DDD & Event Sourcing for Laravel

Build rich domain models and event‑sourced systems — without the complexity.

[![Read the Docs](https://img.shields.io/badge/Read%20the%20Docs-https%3A%2F%2Fdocs.pillarphp.dev-2563eb?style=for-the-badge)](https://docs.pillarphp.dev)

## Install

```bash
composer require pillar/pillar
php artisan pillar:install
```

## Highlights

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

## Documentation

Full docs at **https://docs.pillarphp.dev**  
— Getting started, concepts, tutorial, configuration & CLI reference.

## License

MIT © Edvin Syse
