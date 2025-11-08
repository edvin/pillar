# 🧠 Pillar

[![Coverage](https://codecov.io/gh/edvin/pillar/branch/main/graph/badge.svg)](https://app.codecov.io/gh/edvin/pillar)

**Elegant DDD & Event Sourcing for Laravel.**  
Build rich domain models and event‑sourced systems — without the complexity.

<p align="center">
  <a href="https://docs.pillarphp.dev"><img
    src="https://img.shields.io/badge/Read%20the%20Docs-https%3A%2F%2Fdocs.pillarphp.dev-2563eb?style=for-the-badge"
    alt="Pillar documentation"></a>
  <br/>
  <sub>Getting started • Concepts • Tutorial • Reference</sub>
</p>

## Install

```bash
composer require pillar/pillar
php artisan pillar:install
php artisan migrate
```

## Highlights

- 🧠 **Aggregate sessions (Unit of Work)** — `find()`, mutate, `commit()`
- 🗃️ **Pluggable event store** with **generator‑based** streams & optimistic locking
- 🧵 **Fetch strategies** (load‑all / chunked / streaming)
- 🧬 **Versioned events** & **upcasters**
- 💾 **Snapshotting** policies (Always / Cadence / On‑Demand)
- 🔒 **Payload encryption** — pluggable cipher, per‑event overrides
- 🎭 **Aliases** for readable event names
- 🔁 **Safe replays** (only `Projector` listeners run)
- 🧰 **Facade + buses** for quick wiring

## Documentation

Full docs at **https://docs.pillarphp.dev**  
— Getting started, concepts, tutorial, configuration & CLI reference.

## License

MIT © Edvin Syse
