<?php
// @codeCoverageIgnoreStart
namespace Pillar\Console;

use Illuminate\Console\Command;
use Pillar\Console\Scaffold\PlacementResolver;
use Pillar\Console\Scaffold\RegistryFinder;
use Pillar\Console\Scaffold\PathStyle;
use Pillar\Console\Scaffold\Scaffolder;
use RuntimeException;

use function Laravel\Prompts\select;
use function Laravel\Prompts\text;
use function Laravel\Prompts\confirm;

/**
 * Generates an Aggregate Root and its ID class using stubs.
 *
 * This command delegates placement to PlacementResolver and file creation
 * to Scaffolder (which uses StubWriter under the hood). It prompts for
 * context and placement style when not provided via options.
 */
final class MakeAggregateCommand extends Command
{
    protected $signature = 'pillar:make:aggregate {name? : e.g. Invoice}
        {--context= : Context name as returned by ContextRegistry::name()}
        {--dir= : Folder for the aggregate class (default from config)}
        {--id-dir= : Folder for the ID class (default from config)}
        {--non-es : Generate a non event-sourced aggregate}
        {--force : Overwrite existing files if they exist}
        {--style= : Placement style}
        {--subcontext= : Subcontext folder}';

    protected $description = 'Create Aggregate + ID classes (event-sourced by default) from stubs.';

    public function handle(
        RegistryFinder    $finder,
        PlacementResolver $resolver,
        Scaffolder        $scaffolder
    ): int
    {
        $name = (string)($this->argument('name') ?? '');
        if ($name === '' || !preg_match('/^[A-Z][A-Za-z0-9]+$/', $name)) {
            $name = text(
                label: 'Aggregate name',
                default: $name,
                validate: fn(string $v) => preg_match('/^[A-Z][A-Za-z0-9]+$/', $v) ? null : 'Use PascalCase (e.g. Invoice).'
            );
            if ($name === '') {
                $this->error('Aborted.');
                return self::FAILURE;
            }
        }

        // Context
        $contextName = (string)($this->option('context') ?? '');
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

        $choices    = PathStyle::promptOptions();
        $cfgDefault = PathStyle::fromConfig(config('pillar.make.default_style') ?? null)->value;

        $styleOpt  = $this->option('style');
        $styleEnum = is_string($styleOpt) ? PathStyle::tryFrom($styleOpt) : null;
        $style     = $styleEnum?->value
            ?: (string) select(
                label: 'Placement style',
                options: $choices,
                default: $cfgDefault,
                hint: 'Controls where the Aggregate is placed.'
            );

        $subcontextOpt = $this->option('subcontext');
        $subcontext = $subcontextOpt ? (string) $subcontextOpt : null;

        $cfg = (array) config('pillar.make.aggregate_defaults', []);
        $domainDefault = (string) (config('pillar.make.domain_defaults.domain_dir') ?? 'Domain');

        if ($style === PathStyle::Subcontext->value && ($subcontext === null || $subcontext === '')) {
            $subcontext = text(
                label: 'Subcontext folder',
                default: $domainDefault,
                hint: 'Relative to the context root'
            );
            if ($subcontext === '') {
                $this->warn('Empty subcontext folder.');
            }
        }

        // Defaults from config
        $defaultAggDir = (string) ($cfg['aggregate_dir'] ?? 'Domain/Aggregate');
        $defaultIdDir  = (string) ($cfg['id_dir'] ?? $defaultAggDir);

        $aggDir = trim((string) ($this->option('dir') ?? $defaultAggDir), '/');
        $idDir  = trim((string) ($this->option('id-dir') ?? $defaultIdDir), '/');
        if ($aggDir === '') { $aggDir = trim((string) $defaultAggDir, '/'); }
        if ($idDir === '')  { $idDir  = trim((string) $defaultIdDir, '/'); }

        $nonEsFlag = (bool) $this->option('non-es');
        if ($nonEsFlag) {
            $isEventSourced = false;
        } else {
            // Prompt only when running interactively; default to event-sourced
            $isInteractive = method_exists($this->input, 'isInteractive') ? $this->input->isInteractive() : true;
            $isEventSourced = $isInteractive
                ? confirm(
                    label: 'Create an event-sourced aggregate?',
                    default: true,
                    hint: 'Event-sourced aggregates emit domain events. Choose “no” for a plain aggregate.'
                )
                : true;
        }

        // Resolve placement
        $placement = $resolver->resolve($registry, 'aggregate', [
            'style'      => $style,
            'subcontext' => $subcontext,
        ], ask: function (string $question, array $options) {
            return (string) select(
                label: $question,
                options: array_combine($options, $options)
            );
        });

        $force = (bool) $this->option('force');

        // Plan + write files (overwrite prompting handled by StubWriter via Scaffolder)
        try {
            $plan = $scaffolder->planAggregate($name, $placement, [
                'aggregate_dir' => $aggDir,
                'id_dir'        => $idDir,
                'event_sourced' => $isEventSourced,
            ]);
            $scaffolder->execute($plan, $force);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }

        $this->info('Aggregate created.');
        if (isset($plan->aggregatePath)) { $this->line('  - ' . $plan->aggregatePath); }
        if (isset($plan->idPath))        { $this->line('  - ' . $plan->idPath); }

        return self::SUCCESS;
    }
}
// @codeCoverageIgnoreEnd