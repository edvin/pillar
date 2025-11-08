# 🧱 Architecture Overview

The big picture of how Pillar pieces fit together.

```mermaid
flowchart LR
  subgraph App[Your Laravel App]
    CH[Command Handler]
    QH[Query Handler]
    F[Pillar\\Facade]:::secondary
    S[AggregateSession]:::core
    R[Repository]:::core
    PJ[Projectors]:::secondary
  end

  ES[(Event Store)]:::infra
  DB[(Read Model DB)]:::infra

  U[Client/API] -->|send command| CH
  CH -->|load| S
  S -->|find| R
  R -->|load events| ES
  CH -->|record events| S
  S -->|commit| R
  R -->|append| ES
  ES -->|dispatch events| PJ
  PJ -->|upsert| DB

  U -->|ask query| QH
  QH --> DB

  classDef core fill:#334155,stroke:#94a3b8,color:#e5e7eb
  classDef infra fill:#0f172a,stroke:#64748b,color:#e5e7eb
  classDef secondary fill:#1f2937,stroke:#6b7280,color:#e5e7eb
```

See also:
- [/concepts/aggregate-sessions](/concepts/aggregate-sessions)
- [/event-store/](/event-store/)
- [/concepts/projectors](/concepts/projectors)
