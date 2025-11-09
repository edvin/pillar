---
layout: home
title: "Pillar"
titleTemplate: "Elegant DDD & Event Sourcing for Laravel"
hero:
  name: "Pillar"
  text: "Elegant DDD & Event Sourcing for Laravel"
  tagline: "Build rich domain models and event-sourced systems in Laravel — without the complexity."
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
    link: "/event-store/"

  - icon: "🧵"
    title: "Fetch strategies"
    details: "Load all, chunked, or streaming — pick per aggregate."
    link: "/event-store/"

  - icon: "🧩"
    title: "Stream resolver"
    details: "Route events per type or ID — great for multi-tenancy."
    link: "/event-store/"

  - icon: "🎭"
    title: "Event aliases"
    details: "Human-readable names with backward compatibility."
    link: "/concepts/event-aliases"

  - icon: "🧬"
    title: "Upcasters & versions"
    details: "Evolve event schemas safely over time."
    link: "/concepts/versioned-events"

  - icon: "💾"
    title: "Snapshotting"
    details: "Always, cadence, or on-demand policies with pluggable store."
    link: "/concepts/snapshotting"

  - icon: "🧱"
    title: "Repositories"
    details: "Event-sourced or state-based per aggregate."
    link: "/concepts/repositories"

  - icon: "🔁"
    title: "Safe replays"
    details: "Only projectors run during replays to rebuild read models."
    link: "/reference/cli-replay"

  - icon: "📦"
    title: "Commands & Queries"
    details: "Dispatch commands, ask queries; keep orchestration simple."
    link: "/concepts/commands-and-queries"

  - icon: "🪶"
    title: "Serialization"
    details: "Default JSON serializer; swap or implement your own."
    link: "/concepts/serialization"

  - icon: "🔒"
    title: "Payload encryption"
    details: "Pluggable cipher, per‑event overrides."
    link: "/concepts/serialization#payload-encryption"

  - icon: "📊"
    title: "Stream Browser (UI)"
    details: "Browse event streams, inspect payloads, and time‑travel aggregate state."
    link: "/ui/stream-browser"

  - icon: "🏗️"
    title: "Architecture & Config"
    details: "How pieces fit together and how to configure them."
    link: "/architecture/overview"
---