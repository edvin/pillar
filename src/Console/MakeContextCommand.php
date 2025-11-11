<?php
declare(strict_types=1);

// @codeCoverageIgnoreStart

namespace Pillar\Console;

use Illuminate\Console\Command;
use Pillar\Console\Scaffold\ConfigEditor;
use Pillar\Console\Scaffold\Scaffolder;
use RuntimeException;

use function Laravel\Prompts\text;

/**
 * Artisan command: `pillar:make:context`
 *
 * Creates a bounded context skeleton and registers its ContextRegistry
 * in `config/pillar.php`.
 *
 * It will:
 *  - derive defaults from `pillar.make.contexts_base_namespace` and
 *    `pillar.make.contexts_base_path`;
 *  - prompt for a PascalCase name if not provided;
 *  - create the base folders:
 *      - {Context}/Application/Command
 *      - {Context}/Application/Query
 *      - {Context}/Domain
 *      - {Context}/Infrastructure
 *  - generate `{Name}ContextRegistry.php` from `stubs/context_registry.stub`;
 *  - update `config/pillar.php` via {@see Pillar\Console\Scaffold\ConfigEditor}.
 *
 * Options:
 *  - name                : Context name (e.g. DocumentHandling)
 *  - --namespace         : Root PHP namespace (overrides config default)
 *  - --path              : Base filesystem path (overrides config default)
 *  - --force             : Overwrite existing files
 *
 * Usage:
 *  php artisan pillar:make:context DocumentHandling
 *
 * The command is idempotent; re-run with `--force` to overwrite the generated registry.
 */
final class MakeContextCommand extends Command
{
    protected $signature = 'pillar:make:context {name? : PascalCase bounded context name, e.g. DocumentHandling}
        {--namespace= : Root PHP namespace override (defaults to config pillar.make.contexts_base_namespace)}
        {--path= : Base filesystem path override (defaults to config pillar.make.contexts_base_path)}
        {--force : Overwrite existing files}';

    protected $description = 'Create a bounded context skeleton and register its ContextRegistry in config/pillar.php.';

    public function handle(ConfigEditor $cfg, Scaffolder $scaffolder): int
    {
        // --- Resolve defaults from config pillar.make -------------------------
        $defaultNs   = (string) (config('pillar.make.contexts_base_namespace') ?? 'App');
        $defaultPath = (string) (config('pillar.make.contexts_base_path') ?? base_path('app'));

        // --- Name (Prompts with validation) -----------------------------------
        $name = (string) ($this->argument('name') ?? '');
        if ($name === '' || !preg_match('/^[A-Z][A-Za-z0-9]+$/', $name)) {
            $name = text(
                label: 'Context name',
                validate: function (string $v) {
                    return preg_match('/^[A-Z][A-Za-z0-9]+$/', $v)
                        ? null
                        : 'Please use PascalCase (e.g. DocumentHandling).';
                },
                hint: 'Example: DocumentHandling, Billing, Inventory'
            );
            if ($name === '') {
                $this->error('Aborted.');
                return self::FAILURE;
            }
        }

        // --- Namespace (prompt only if not provided) --------------------------
        $optNs = $this->option('namespace');
        $rootNs = trim(
            is_string($optNs) && $optNs !== ''
                ? $optNs
                : text(
                label: 'Root PHP namespace',
                default: $defaultNs,
                hint: 'Defaults to pillar.make.contexts_base_namespace'
            ),
            '\\'
        );
        if ($rootNs === '') {
            $rootNs = 'App';
        }

        // --- Base path (prompt only if not provided) --------------------------
        $optPath = $this->option('path');
        $baseDir = rtrim(
            is_string($optPath) && $optPath !== ''
                ? $optPath
                : text(
                label: 'Base filesystem path',
                default: $defaultPath,
                hint: 'Defaults to pillar.make.contexts_base_path'
            ),
            '/'
        );
        if ($baseDir === '') {
            $baseDir = base_path('app');
        }

        $force  = (bool) $this->option('force');
        $ctxNs  = $rootNs . '\\' . $name;
        $ctxDir = (str_starts_with($baseDir, base_path()))
            ? rtrim($baseDir, '/\\') . '/' . $name
            : base_path(trim($baseDir, '/\\') . '/' . $name);

        // --- Create folders (handlers are created on demand by scaffolder) ----
        $dirs = [
            $ctxDir,
            $ctxDir . '/Application/Command',
            $ctxDir . '/Application/Query',
            $ctxDir . '/Domain',
            $ctxDir . '/Infrastructure',
        ];
        foreach ($dirs as $d) {
            if (!is_dir($d) && !mkdir($d, 0777, true) && !is_dir($d)) {
                $this->error("Failed to create directory: {$d}");
                return self::FAILURE;
            }
        }

        // --- Write ContextRegistry from stub ----------------------------------
        $fqcn     = $ctxNs . '\\' . $name . 'ContextRegistry';

        try {
            $filePath = $scaffolder->writeContextRegistry($ctxNs, $name, $ctxDir, $force);
        } catch (RuntimeException $e) {
            $this->warn('Nothing written. ' . $e->getMessage());
            return self::FAILURE;
        }

        // --- Update config -----------------------------------------------------
        $configPath = base_path('config/pillar.php');
        try {
            $cfg->addContextRegistryFqcn($configPath, $fqcn);
        } catch (RuntimeException $e) {
            $this->warn('Bounded Context created, but could not update config: ' . $e->getMessage());
            $this->line("Please add this to 'context_registries' in config/pillar.php:");
            $this->line('    ' . $fqcn . '::class,');
        }

        $this->info("Bounded Context $name created at $ctxDir");
        $this->info("Registered $fqcn in config/pillar.php (or printed instructions above).");
        return self::SUCCESS;
    }
}
// @codeCoverageIgnoreEnd
