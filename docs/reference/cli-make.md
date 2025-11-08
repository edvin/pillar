# CLI — Make (Scaffolding)

Pillar ships with interactive generators to scaffold Commands/Queries and their Handlers, and to create new bounded contexts.

## Commands

### `pillar:make:context {name?}`
- Creates a bounded context skeleton under the configured base (default `App/<Name>` in `app/<Name>`).
- Writes a `*ContextRegistry` that implements `Pillar\Context\ContextRegistry`.
- Registers the registry FQCN in `config/pillar.php` under `context_registries`.

Options:
- `--namespace=App` — override root namespace (defaults to `config('pillar.make.contexts_base_namespace')`)
- `--path=app` — override base path (defaults to `config('pillar.make.contexts_base_path')`)
- `--force` — overwrite files

---

### `pillar:make:command {name?}`
- Generates a `*Command` + `*Handler` and auto-registers them in the selected ContextRegistry.
- Interactive when args are omitted (name, style, subcontext if applicable).
- Uses `PathStyle` to decide handler placement. Default comes from `config('pillar.make.default_style')` (defaults to `colocate`).

Options:
- `--context=` — pick the bounded context by `ContextRegistry::name()` (will prompt if not provided)
- `--style=` — one of: `colocate, mirrored, split, subcontext, infer` (prompted if omitted)
- `--subcontext=` — adds an extra folder level when using `subcontext`
- `--force` — overwrite files

---

### `pillar:make:query {name?}`
- Generates a `*Query` + `*Handler` and auto-registers them in the selected ContextRegistry.
- Same interactive flow and placement rules as `pillar:make:command`.

Options:
- `--context=`, `--style=`, `--subcontext=`, `--force`

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

The generators **edit the source file** of your registry to add entries to the return arrays of `commands()` / `queries()`—no runtime reflection or service-location.

---

## Placement styles (PathStyle)

Pillar can place Handlers in different locations relative to their Commands/Queries. You choose a style per run (via `--style`) or set the global default in config (`pillar.make.default_style`).

> **Tip:** You can override per context via `pillar.make.overrides[<RegistryFQCN>|<name()>]['style']`.

Below, examples use the context name **DocumentHandling** and the command **RenameDocument**.

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

**Use when:** you prefer local proximity and simple navigation.  
**Pros:** minimal hopping; mirrors how many teams work day-to-day.  
**Cons:** large folders if you have lots of actions.

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

**Use when:** you want clear separation of “messages” vs “handlers”, and you have tooling that expects this.  
**Pros:** explicit structure; easy to see all handlers.  
**Cons:** more navigation; extra directories.

---

### 3) `split`
All handlers go into a single **Application/Handler** folder, regardless of command/query.

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

**Use when:** you like one place to browse handlers or run code owners/reviews on handler code.  
**Pros:** one-stop overview of behavior entry points.  
**Cons:** loses per-type grouping; can get crowded.

---

### 4) `subcontext`
Adds an extra **sub-folder** before `Application/...`. Good for teams that split a bounded context into smaller “submodules” (e.g., Writer/Reader or Admin/Public).

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
- If you choose `subcontext` style but don’t pass `--subcontext`, the generator will prompt for one.
- Works with `colocate`/`mirrored`/`split` semantics inside the sub-folder (per your config/override).

---

### 5) `infer` (future-friendly)
Attempts to derive placement from **existing files** in the selected bounded context (e.g., “first registration wins”). If it can’t infer, it **falls back to** the configured default (usually `colocate`).

**Use when:** you incrementally migrate legacy projects—let history drive structure.  
**Behavior today:** treated as `colocate` unless you’ve implemented a custom inference strategy via `PlacementResolver`.

---

## How the style affects namespaces and registration

Regardless of style, registration adds the same **array entries** to your registry:

```php
// App\DocumentHandling\DocumentHandlingContextRegistry::commands()
return [
    App\DocumentHandling\Application\Command\RenameDocumentCommand::class
        => App\DocumentHandling\Application\Command\RenameDocumentHandler::class, // colocate
    // or, for mirrored:
    // => App\DocumentHandling\Application\Handler\Command\RenameDocumentHandler::class,
];
```

Namespace differences come from folder layout only; the generator calculates them for you.

---

## Changing the default

In `config/pillar.php`:

```php
'make' => [
    'default_style' => 'colocate', // colocate|mirrored|split|subcontext|infer
    // 'overrides' => [
    //     \App\DocumentHandling\DocumentHandlingContextRegistry::class => [
    //         'style' => 'mirrored',
    //     ],
    // ],
],
```
