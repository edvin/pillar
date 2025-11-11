<?php
declare(strict_types=1);
// @codeCoverageIgnoreStart

namespace Pillar\Console;

use Illuminate\Console\Command;
use Pillar\Console\Scaffold\PathStyle;
use Pillar\Console\Scaffold\PlacementResolver;
use Pillar\Console\Scaffold\RegistryEditor;
use Pillar\Console\Scaffold\RegistryFinder;
use Pillar\Console\Scaffold\Scaffolder;
use RuntimeException;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

/**
 * Base scaffolding command for Pillar code generators (Commands & Queries).
 *
 * Subclasses supply:
 *  - artifactKey(): string         // 'command' | 'query' (used for placement + registry editing)
 *  - nameLabel(): string           // Prompt label
 *  - nameHint(): string            // Prompt hint/examples
 *  - subcontextPlaceholder(): string // Example label shown for subcontext prompt
 *  - buildPlan(...): object        // Produce a Scaffolder plan object
 *  - registerPlan(...): void       // Write registration lines into ContextRegistry
 */
abstract class AbstractMakeArtifactCommand extends Command
{
    // ---- Specialize these in subclasses ------------------------------------

    /** 'command' | 'query' — used for placement + editor dispatch */
    abstract protected function artifactKey(): string;

    /** e.g. 'Command name' / 'Query name' */
    abstract protected function nameLabel(): string;

    /** Shown under the name prompt (examples) */
    abstract protected function nameHint(): string;

    /** e.g. 'Writer' for commands, 'Reader' for queries */
    abstract protected function subcontextPlaceholder(): string;

    /** Build the scaffolding plan for this artifact */
    abstract protected function buildPlan(Scaffolder $scaffolder, string $name, array $placement): object;

    /** Register plan into ContextRegistry (editor method varies) */
    abstract protected function registerPlan(RegistryEditor $editor, object $registry, object $plan): void;

    // ---- Shared handle() flow ----------------------------------------------

    public function handle(
        RegistryFinder $finder,
        PlacementResolver $resolver,
        Scaffolder $scaffolder,
        RegistryEditor $editor
    ): int {
        // Name
        $nameArg = (string) ($this->argument('name') ?? '');
        $name = $this->promptPascalCase(
            $this->nameLabel(),
            $nameArg !== '' ? $nameArg : null,
            $this->nameHint()
        );
        if ($name === '') {
            $this->error('Aborted.');
            return self::FAILURE;
        }

        // Context (deferred prompt inside finder)
        $contextName = (string) ($this->option('context') ?? '');

        // Style
        $choices    = PathStyle::promptOptions();
        $cfgDefault = PathStyle::fromConfig(config('pillar.make.default_style') ?? null)->value;
        $styleOpt   = $this->option('style');
        $styleEnum  = is_string($styleOpt) ? PathStyle::tryFrom($styleOpt) : null;
        $style      = $styleEnum?->value
            ?: (string) select(
                label: 'Placement style',
                options: $choices,
                default: $cfgDefault,
                hint: 'Controls where files are placed within the context.'
            );

        // Subcontext
        $subcontextOpt = $this->option('subcontext');
        $subcontext = $subcontextOpt ? (string) $subcontextOpt : null;
        if ($style === PathStyle::Subcontext->value && ($subcontext === null || $subcontext === '')) {
            $subcontext = (string) text(
                label: 'Subcontext folder (e.g. ' . $this->subcontextPlaceholder() . ')',
                hint: 'Adds an extra folder level before Application/...'
            );
            if ($subcontext === '') {
                $this->warn('No subcontext provided; keeping chosen style but without subcontext.');
            }
        }

        $force = (bool) $this->option('force');

        // Registry
        $registry = $finder->selectByContextName($contextName, ask: function (array $choices) {
            $value = (string) select(
                label: 'Select context',
                options: array_combine(array_keys($choices), array_keys($choices)),
                default: array_key_first($choices) ?? null
            );
            return $choices[$value];
        });

        if (!$registry) {
            $this->error('No ContextRegistry registered. Please register at least one.');
            return self::FAILURE;
        }

        // Placement
        $placement = $resolver->resolve(
            $registry,
            $this->artifactKey(), // 'command' | 'query'
            ['style' => $style, 'subcontext' => $subcontext],
            ask: function (string $question, array $options) {
                return (string) select(
                    label: $question,
                    options: array_combine($options, $options)
                );
            }
        );

        // Plan + write
        $plan = $this->buildPlan($scaffolder, $name, $placement);

        try {
            $scaffolder->execute($plan, $force);
        } catch (RuntimeException $e) {
            if (!$force && str_contains($e->getMessage(), 'File exists')) {
                if (confirm('Files already exist. Overwrite?', default: false)) {
                    $scaffolder->execute($plan, true);
                } else {
                    $this->warn('Nothing written.');
                    return self::FAILURE;
                }
            } else {
                $this->error($e->getMessage());
                return self::FAILURE;
            }
        }

        // Register
        try {
            $this->registerPlan($editor, $registry, $plan);
        } catch (RuntimeException $e) {
            $this->error('Generated files, but failed to register: ' . $e->getMessage());
            $this->line('Please add the following lines to your ContextRegistry:');
            foreach ($plan->registrationLines as $line) {
                $this->line('  ' . $line);
            }
            return self::FAILURE;
        }

        $this->info(ucfirst($this->artifactKey()) . ' created and registered.');
        return self::SUCCESS;
    }

    // ---- Small shared helpers ----------------------------------------------

    /**
     * Prompt for a PascalCase name with validation; uses $default if already valid.
     */
    protected function promptPascalCase(string $label, ?string $default, string $hint): string
    {
        $existing = $default ?? '';
        if ($existing !== '' && preg_match('/^[A-Z][A-Za-z0-9]+$/', $existing)) {
            return $existing;
        }

        return text(
            label: $label,
            default: $existing,
            validate: fn(string $v) =>
            preg_match('/^[A-Z][A-Za-z0-9]+$/', $v) ? null : 'Please use PascalCase (e.g. FooBar).',
            hint: $hint
        );
    }
}
// @codeCoverageIgnoreEnd