<?php
// @codeCoverageIgnoreStart

namespace Pillar\Console;

use Illuminate\Console\Command;
use Pillar\Console\Scaffold\ConfigEditor;
use RuntimeException;

use function Laravel\Prompts\text;
use function Laravel\Prompts\confirm;

final class MakeContextCommand extends Command
{
    protected $signature = 'pillar:make:context {name? : PascalCase bounded context name, e.g. DocumentHandling}
        {--namespace= : Root PHP namespace override (defaults to config pillar.make.contexts_base_namespace)}
        {--path= : Base filesystem path override (defaults to config pillar.make.contexts_base_path)}
        {--force : Overwrite existing files}';

    protected $description = 'Create a bounded context skeleton and register its ContextRegistry in config/pillar.php.';

    public function handle(ConfigEditor $cfg): int
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
        $filePath = $ctxDir . '/' . $name . 'ContextRegistry.php';

        if (file_exists($filePath) && !$force) {
            if (!confirm("File exists at {$filePath}. Overwrite?", default: false)) {
                $this->warn('Nothing written.');
                return self::FAILURE;
            }
        }

        $stubPathCandidates = [
            base_path('stubs/context_registry.stub'),
            __DIR__ . '/../../stubs/context_registry.stub',    // packaged with library
            __DIR__ . '/../../../stubs/context_registry.stub', // extra fallback
        ];

        $stub = null;
        foreach ($stubPathCandidates as $p) {
            if (is_file($p)) {
                $stub = file_get_contents($p);
                break;
            }
        }

        if ($stub === null) {
            $this->error('Missing stub: stubs/context_registry.stub');
            return self::FAILURE;
        }

        $code = str_replace(
            ['{{namespace}}', '{{Name}}'],
            [$ctxNs, $name],
            $stub
        );

        if (false === file_put_contents($filePath, $code)) {
            $this->error("Failed to write file: {$filePath}");
            return self::FAILURE;
        }

        // --- Update config -----------------------------------------------------
        $configPath = base_path('config/pillar.php');
        try {
            $cfg->addContextRegistryFqcn($configPath, $fqcn);
        } catch (RuntimeException $e) {
            $this->warn('Context created, but could not update config: ' . $e->getMessage());
            $this->line("Please add this to 'context_registries' in config/pillar.php:");
            $this->line('    ' . $fqcn . '::class,');
        }

        $this->info("Context {$name} created at {$ctxDir}");
        $this->info("Registered {$fqcn} in config/pillar.php (or printed instructions above).");
        return self::SUCCESS;
    }
}
// @codeCoverageIgnoreEnd
