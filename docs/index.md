---
layout: home
title: "Pillar"
titleTemplate: "Elegant DDD & Event Sourcing for Laravel"
hero:
  name: "Pillar"
  text: "Elegant DDD & Event Sourcing for Laravel"
  tagline: "Build rich domain models and event-sourced systems in Laravel — without friction."
  image:
    src: /hero-code.svg
    alt: "AggregateSession: find, rename, commit"
    width: 800
    height: 440
    align: right
  actions:
    - theme: brand
      text: "Get started"
      link: /getting-started/
    - theme: alt
      text: "Tutorial"
      link: /tutorials/build-a-document-service
    - theme: alt
      text: "View on GitHub"
      link: "https://github.com/edvin/pillar"
features:
  - icon: "🧠"
    title: "Aggregate sessions"
    details: "Unit of Work that tracks aggregates & persists changes atomically."
    link: "/concepts/aggregate-sessions"

  - icon: "🗃️"
    title: "Event store"
    details: "Pluggable backend, optimistic locking, generator-based streams."
    link: "/concepts/event-store"

  - icon: "🧵"
    title: "Fetch strategies"
    details: "Load all, chunked, or streaming — pick per aggregate."
    link: "/concepts/fetch-strategies"

  - icon: "🧩"
    title: "Aggregate IDs & streams"
    details: "Strongly-typed UUID IDs with readable stream names (e.g. document-<uuid>)."
    link: "/concepts/aggregate-ids"

  - icon: "🎭"
    title: "Event aliases"
    details: "Human-readable names with backward compatibility."
    link: "/concepts/event-aliases"

  - icon: "📣"
    title: "Events"
    details: "Local, inline, and publishable events."
    link: "/concepts/events"

  - icon: "🧬"
    title: "Upcasters & versions"
    details: "Evolve event schemas safely over time."
    link: "/concepts/versioned-events"

  - icon: "💾"
    title: "Snapshotting"
    details: "Always, cadence, or on-demand policies with pluggable store."
    link: "/concepts/snapshotting"

  - icon: "⏱️"
    title: "Event windows"
    details: "Slice streams by stream seq, global seq, or time for partial loads and replays."
    link: "/concepts/event-window"

  - icon: "🖥️"
    title: "Projectors"
    details: "Build read models fed from event streams; safe to rebuild with replay."
    link: "/concepts/projectors"

  - icon: "🧭"
    title: "Context registries"
    details: "Per-context wiring for commands, queries, events, upcasters & aggregate IDs."
    link: "/concepts/context-registries"

  - icon: "✅"
    title: "Testing"
    details: "Patterns and helpers for testing aggregates, sessions, and projections."
    link: "/guides/testing"

  - icon: "🧱"
    title: "Repositories"
    details: "Event-sourced or state-based per aggregate."
    link: "/concepts/repositories"

  - icon: "🔁"
    title: "Safe replays"
    details: "Only projectors run during replays to rebuild read models."
    link: "/reference/cli-replay"

  - icon: "📬"
    title: "Transactional Outbox"
    details: "Reliable event delivery with retries & partitioning."
    link: "/concepts/outbox"

  - icon: "🛠️"
    title: "Outbox Worker"
    details: "CLI with leasing, live stats UI & JSON mode."
    link: "/concepts/outbox-worker"

  - icon: "📦"
    title: "Commands & Queries"
    details: "Dispatch commands, ask queries; keep orchestration simple."
    link: "/concepts/commands-and-queries"

  - icon: "⚡"
    title: "CQRS"
    details: "Read/write separation with projectors and a fast query side."
    link: "/concepts/cqrs"

  - icon: "🪶"
    title: "Serialization"
    details: "Default JSON serializer, swap or implement your own."
    link: "/concepts/serialization"

  - icon: "🔒"
    title: "Payload encryption"
    details: "Pluggable cipher, per‑event overrides."
    link: "/concepts/serialization#payload-encryption"

  - icon: "📊"
    title: "Stream Browser (UI)"
    details: "Browse event streams, inspect payloads, and time‑travel aggregate state."
    link: "/stream-browser"

  - icon: "🏗️"
    title: "Architecture & Config"
    details: "How pieces fit together and how to configure them."
    link: "/architecture/overview"

  - icon: "📈"
    title: "Metrics"
    details: "Prometheus metrics for event store, outbox, commands, queries and workers."
    link: "/concepts/metrics"
