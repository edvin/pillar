# Getting started

::: tip
This guide takes you through install to your first aggregate, events, commands and queries.
:::

## 🧩 Installation

```bash
composer create-project laravel/laravel myproject
cd myproject
composer require pillar/pillar
```

Pillar automatically registers its service provider via Laravel package discovery.

Run the installer to set up migrations and configuration:

```bash
php artisan pillar:install
```

This is an interactive installer that asks whether to publish the migrations and config file.

You’ll be prompted to:

- Publish the **events table migration** (for event sourcing)
- Publish the **aggregate_versions table migration** (for per-aggregate versioning)
- Publish the **configuration file** (`config/pillar.php`)

Once published, run:

```bash
php artisan migrate
```

to create the `events` and `aggregate_versions` tables in your database.

---

### 📁 Published files

| File                                                                        | Description                                                                                   |
|-----------------------------------------------------------------------------|-----------------------------------------------------------------------------------------------|
| `database/migrations/YYYY_MM_DD_HHMMSS_create_events_table.php`             | The table used to store domain events                                                         |
| `database/migrations/YYYY_MM_DD_HHMMSS_create_aggregate_versions_table.php` | Counter table tracking the last per-aggregate version for optimistic concurrency & sequencing |
| `config/pillar.php`                                                         | Global configuration for repositories, event store, serializer and snapshotting               |

---