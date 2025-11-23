# Getting started

Pillar helps you build **rich domain models** in Laravel — with or without full event sourcing. You can adopt it
incrementally: start with a single [aggregate](/concepts/aggregate-roots) to gain audit trails and clean boundaries,
or go all‑in with [DDD patterns](/concepts/cqrs).
If you want the “why”, see the short overview in [Philosophy](/about/philosophy).

**What you’ll do on this page**

- Install and publish Pillar
- See what gets installed (migrations, tables, configuration) and how it fits into your app

Follow the link at the bottom right of the page to jump to the tutorial.

::: info
Prefer the big picture first? Read [Philosophy](/about/philosophy). Want to build something right away?
Jump to the [Tutorial](/tutorials/build-a-document-service) — it adds commands, handlers, aliases, and projectors.

Prefer to browse concepts in order? Start with **[Aggregates](/concepts/aggregate-roots)** from the sidebar and work
down.
:::

## 🧩 Installation

In a Laravel project:

```bash
composer require pillar/pillar
php artisan pillar:install
```
---

## ✅ After install: what you have

Once `pillar:install` has finished and migrations have run, you should see:

| File                                                                       | Description                                                               |
|----------------------------------------------------------------------------|---------------------------------------------------------------------------|
| `database/migrations/YYYY_MM_DD_HHMMSS_create_events_table.php`            | Stores domain events                                                      |
| `database/migrations/YYYY_MM_DD_HHMMSS_create_outbox_table.php`            | Outbox storage for events implementing `ShouldPublish`                    |
| `database/migrations/YYYY_MM_DD_HHMMSS_create_outbox_partitions_table.php` | Tracks outbox partitions to support cooperative leasing worker scheduling |
| `database/migrations/YYYY_MM_DD_HHMMSS_create_outbox_workers_table.php`    | Tracks connected outbox publishing workers                                |
| `database/migrations/YYYY_MM_DD_HHMMSS_create_snapshots_table.php`         | Stores aggregate root snapshots in a database table                       |
| `config/pillar.php`                                                        | Configure Pillar                                                          |

These give you:

- an `events` table for your domain’s event streams ([Event store](/concepts/event-store)),
- a transactional outbox plus worker/partition metadata (used when you publish events) ([Outbox](/concepts/outbox), [Outbox worker](/concepts/outbox-worker)),
- a central `config/pillar.php` file to tweak event store, snapshots, outbox and UI ([Configuration](/reference/configuration)).

Before writing any domain code, make sure:

1. Your database connection is configured and the migrations have run.
2. You’ve decided which **[bounded context](/concepts/context-registries)** you’ll start with (e.g. `Document`, `Billing`).
3. You’re ready to register a [`ContextRegistry`](/concepts/context-registries) for that context (the tutorial walks you through this).

From here, the next step is to put your first aggregate inside a bounded context, register its `AggregateRootId`, and
wire up commands and projectors. The **Build a document service** tutorial does exactly that, end‑to‑end.

---

## Where to next

- Add **commands & handlers**, aliases and projectors in a bounded context → [/tutorials/build-a-document-service](/tutorials/build-a-document-service)
- Learn the **Aggregate session** lifecycle → [/concepts/aggregate-sessions](/concepts/aggregate-sessions)
- Configure the **Event store** (tables, fetch strategies, optimistic locking) → [/event-store](/concepts/event-store)
- Optional: enable **payload encryption** → [/concepts/serialization#payload-encryption](/concepts/serialization#payload-encryption)