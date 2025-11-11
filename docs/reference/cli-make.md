# CLI — Make (Scaffolding)

Pillar ships with interactive generators to scaffold **bounded contexts**, **commands/queries + handlers**, **aggregates**, and **domain events**. All makers are interactive: if you omit flags, you’ll be prompted.

## At a glance

```bash
# Context
php artisan pillar:make:context Document

# Commands / Queries
php artisan pillar:make:command CreateDocument --context=Document
php artisan pillar:make:query   FindDocument   --context=Document

# Aggregate + Id
php artisan pillar:make:aggregate Document --context=Document

# Domain Events
php artisan pillar:make:event DocumentCreated --context=Document
php artisan pillar:make:event DocumentRenamed --context=Document
```

---

## Commands

### `pillar:make:context {name?}`

Creates a bounded context skeleton under the configured base (default `App/<Name>` in `app/<Name>`), writes a `*ContextRegistry` implementing `Pillar\Context\ContextRegistry`, and registers its FQCN in `config/pillar.php` under `context_registries`.

**Options**
- `--namespace=App` — override root namespace (defaults to `config('pillar.make.contexts_base_namespace')`)
- `--path=app` — override base path (defaults to `config('pillar.make.contexts_base_path')`)
- `--force` — overwrite files

**Example**
```bash
php artisan pillar:make:context Billing --namespace=App --path=app
```

---

### `pillar:make:command {name?}`

Generates a `*Command` + `*Handler` and auto‑registers them in the selected ContextRegistry. Uses `PathStyle` to decide handler placement. Default comes from `config('pillar.make.default_style')` (defaults to `colocate`).

**Options**
- `--context=` — pick the bounded context by `ContextRegistry::name()` (prompts if omitted)
- `--style=` — one of: `colocate, mirrored, split, subcontext, infer`
- `--subcontext=` — adds an extra folder level when using `subcontext`
- `--force` — overwrite files

**Example**
```bash
php artisan pillar:make:command RenameDocument --context=Document --style=mirrored
```

---

### `pillar:make:query {name?}`

Generates a `*Query` + `*Handler` and auto‑registers them in the selected ContextRegistry. Same flow and placement rules as `pillar:make:command`.

**Options**
- `--context=`, `--style=`, `--subcontext=`, `--force`

**Example**
```bash
php artisan pillar:make:query FindDocument --context=Document
```

---

### `pillar:make:aggregate {name?}`

Generates an Aggregate root (and its Id class) in the selected bounded context. Honors subcontext placement like other makers.

**Options**
- `--context=` — pick the bounded context by `ContextRegistry::name()` (prompts if omitted)
- `--style=` — one of: `colocate, mirrored, split, subcontext, infer` *(only `subcontext` impacts aggregates; others accepted for consistency)*
- `--subcontext=` — adds an extra folder level before the domain folder when using `subcontext`
- `--dir=` — base domain folder (relative to the context root). Defaults to `config('pillar.make.domain_defaults.domain_dir')` (e.g., `Domain`).
- `--aggregate-dir=` — folder for the Aggregate class (relative to the context root). Defaults to `config('pillar.make.aggregate_defaults.aggregate_dir')`.
- `--id-dir=` — folder for the Id class (relative to the context root). Defaults to `config('pillar.make.aggregate_defaults.id_dir')`.
- `--force` — overwrite files

**Notes**
- All directories are **relative to the context root**.
- If you pass `--dir` without `--aggregate-dir` / `--id-dir`, the defaults are kept as‑is (they do not auto‑prepend `--dir`).

**Example**
```bash
php artisan pillar:make:aggregate Document --context=Document --subcontext=Core \
    --aggregate-dir=Domain/Aggregate --id-dir=Domain/Identity
```

---

### `pillar:make:event {name?}`

Generates a Domain Event class in the selected bounded context. Uses the `domain_event` stub and honors subcontext placement.

**Options**
- `--context=` — pick the bounded context by `ContextRegistry::name()` (prompts if omitted)
- `--style=` — one of: `colocate, mirrored, split, subcontext, infer` *(only `subcontext` affects domain placement)*
- `--subcontext=` — adds an extra folder level before the domain folder when using `subcontext`
- `--dir=` — base domain folder (relative to the context root). Defaults to `config('pillar.make.domain_defaults.domain_dir')` (e.g., `Domain`).
- `--event-dir=` — folder for Event classes (relative to the context root). Defaults to `config('pillar.make.event_defaults.event_dir')` (e.g., `Domain/Event`).
- `--force` — overwrite files

**Notes**
- Directories are **relative to the context root**. When `--dir` is provided without `--event-dir`, the event folder name from config is used under your chosen base.

**Example**
```bash
php artisan pillar:make:event DocumentCreated --context=Document --subcontext=Core
```

---

## Registration model

Context registries implement:

```php
interface ContextRegistry {
    public function commands(): array; // [CommandFQCN::class => HandlerFQCN::class]
    public function queries(): array;  // [QueryFQCN::class => HandlerFQCN::class]
    public function events(): EventMapBuilder;
    public function name(): string;
}
```

The generators **edit the source file** of your registry to add entries to the return arrays of `commands()` / `queries()` — no runtime reflection or service‑location.

---

## Placement styles (PathStyle)

Pillar can place Handlers in different locations relative to their Commands/Queries. Choose a style per run (via `--style`) or set a global default in config (`pillar.make.default_style`).

> **Tip:** Override per context via `pillar.make.overrides[<RegistryFQCN>|<name()>]['style']`.

Examples below use the context **DocumentHandling** and command **RenameDocument**.

### 1) `colocate` (default)
Handler sits **next to** its message (command/query). Keeps related files together and is easiest to navigate in small/medium contexts.

```
app/DocumentHandling/
  Application/
    Command/
      RenameDocumentCommand.php
      RenameDocumentHandler.php   ← same folder
    Query/
      FindDocumentQuery.php
      FindDocumentHandler.php     ← same folder
```

**Pros:** minimal hopping; mirrors day‑to‑day work.  
**Cons:** large folders if you have many actions.

---

### 2) `mirrored`
Handlers live under a **mirrored tree**, per message type.

```
app/DocumentHandling/
  Application/
    Command/
      RenameDocumentCommand.php
    Handler/
      Command/
        RenameDocumentHandler.php
    Query/
      FindDocumentQuery.php
    Handler/
      Query/
        FindDocumentHandler.php
```

**Pros:** explicit structure; easy to see all handlers.  
**Cons:** more navigation; extra directories.

---

### 3) `split`
All handlers go into a single **Application/Handler** folder.

```
app/DocumentHandling/
  Application/
    Command/
      RenameDocumentCommand.php
    Query/
      FindDocumentQuery.php
    Handler/
      RenameDocumentHandler.php
      FindDocumentHandler.php
```

**Pros:** one‑stop overview of entry points.  
**Cons:** loses per‑type grouping; can get crowded.

---

### 4) `subcontext`
Adds an extra **sub‑folder** before `Application/...`. Good when you split a bounded context into smaller sub‑modules (e.g., Writer/Reader or Admin/Public).

```
# with --subcontext=Writer
app/DocumentHandling/
  Writer/
    Application/
      Command/
        RenameDocumentCommand.php
      Handler/
        RenameDocumentHandler.php
```

**Notes**
- If you choose `subcontext` but don’t pass `--subcontext`, the generator will prompt for one.
- Works with `colocate`/`mirrored`/`split` semantics inside the sub‑folder (per your config/override).

---

### 5) `infer` (future‑friendly)
Attempts to derive placement from **existing files** in the selected bounded context (e.g., “first registration wins”). If it can’t infer, it **falls back** to your configured default (usually `colocate`).

**Today:** treated as `colocate` unless you provide a custom inference strategy via `PlacementResolver`.

---

## Configuration keys (quick reference)

```php
'make' => [
    'contexts_base_path'      => base_path('app'),
    'contexts_base_namespace' => 'App',
    'default_style'           => 'colocate',

    // Shared domain
    'domain_defaults' => [
        'domain_dir' => 'Domain',
    ],

    // Aggregates
    'aggregate_defaults' => [
        'aggregate_dir' => 'Domain/Aggregate',
        'id_dir'        => 'Domain/Aggregate',
    ],

    // Events
    'event_defaults' => [
        'event_dir'  => 'Domain/Event',
    ],

    // Per‑registry overrides (FQCN or name())
    'overrides' => [ /* ... */ ],
];
```

---

**That’s it!** Use the makers to get the boring bits out of the way, then focus on your domain.
