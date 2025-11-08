<?php
/** @noinspection DuplicatedCode */
// @codeCoverageIgnoreStart

namespace Pillar\Console;

use Illuminate\Console\Command;
use Pillar\Console\Scaffold\RegistryFinder;
use Pillar\Console\Scaffold\PlacementResolver;
use Pillar\Console\Scaffold\Scaffolder;
use Pillar\Console\Scaffold\RegistryEditor;
use Pillar\Console\Scaffold\PathStyle;
use RuntimeException;

use function Laravel\Prompts\text;
use function Laravel\Prompts\select;
use function Laravel\Prompts\confirm;

final class MakeQueryCommand extends Command
{
    protected $signature = 'pillar:make:query {name? : PascalCase action, e.g. FindDocument}
        {--context= : Context name as returned by ContextRegistry::name()}
        {--subcontext= : Optional subfolder within the bounded context}
        {--style= : infer|mirrored|split|subcontext|colocate}
        {--force : Overwrite existing files if they exist}';

    protected $description = 'Create a Query + Handler and register them in the selected ContextRegistry.';

    public function handle(
        RegistryFinder $finder,
        PlacementResolver $resolver,
        Scaffolder $scaffolder,
        RegistryEditor $editor
    ): int {
        // --- Name (Prompts with validation) -----------------------------------
        $name = (string) ($this->argument('name') ?? '');
        if ($name === '' || !preg_match('/^[A-Z][A-Za-z0-9]+$/', $name)) {
            $name = text(
                label: 'Query name (PascalCase)',
                default: $name !== '' ? $name : 'FindDocument',
                validate: function (string $v) {
                    return preg_match('/^[A-Z][A-Za-z0-9]+$/', $v)
                        ? null
                        : 'Please use PascalCase (e.g. FindDocument).';
                },
                hint: 'Example: FindDocument, ListInvoices'
            );
            if ($name === '') {
                $this->error('Aborted.');
                return self::FAILURE;
            }
        }

        // --- Context (prompt inside finder when needed) -----------------------
        $contextName = (string) ($this->option('context') ?? '');

        // --- Style (enum + config default via Prompts) ------------------------
        $choices    = PathStyle::promptOptions(); // value => label (with emojis)
        $cfgDefault = PathStyle::fromConfig(config('pillar.make.default_style') ?? null)->value;

        $styleOpt  = $this->option('style');
        $styleEnum = is_string($styleOpt) ? PathStyle::tryFrom($styleOpt) : null;
        $style     = $styleEnum?->value
            ?: (string) select(
                label: 'Placement style',
                options: $choices,
                default: $cfgDefault,
                hint: 'Controls where the Handler file is placed.'
            );

        // --- Subcontext (ask only when style=subcontext) ----------------------
        $subcontextOpt = $this->option('subcontext');
        $subcontext = $subcontextOpt ? (string) $subcontextOpt : null;
        if ($style === PathStyle::Subcontext->value && ($subcontext === null || $subcontext === '')) {
            $subcontext = (string) text(
                label: 'Subcontext folder (e.g. Reader)',
                hint: 'Adds an extra folder level before Application/...'
            );
            if ($subcontext === '') {
                $this->warn('No subcontext provided; keeping chosen style but without subcontext.');
            }
        }

        $force = (bool) $this->option('force');

        // --- Resolve target ContextRegistry (prompts if multiple) -------------
        $registry = $finder->selectByContextName($contextName, ask: function (array $choices) {
            // $choices: ['Name' => $registryObject]
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

        // --- Compute placement -------------------------------------------------
        $placement = $resolver->resolve($registry, 'query', [
            'style'      => $style,      // pass string; resolver accepts string or enum
            'subcontext' => $subcontext,
        ], ask: function (string $question, array $options) {
            return (string) select(
                label: $question,
                options: array_combine($options, $options)
            );
        });

        // --- Plan + write files ------------------------------------------------
        $plan = $scaffolder->planQuery($name, $placement);

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

        // --- Register in the ContextRegistry source ---------------------------
        try {
            $editor->registerQuery($registry, $plan);
        } catch (RuntimeException $e) {
            $this->error('Generated files, but failed to register: ' . $e->getMessage());
            $this->line('Please add the following lines to your ContextRegistry:');
            foreach ($plan->registrationLines as $line) {
                $this->line('  ' . $line);
            }
            return self::FAILURE;
        }

        $this->info('Query created and registered.');
        return self::SUCCESS;
    }
}
// @codeCoverageIgnoreEnd
