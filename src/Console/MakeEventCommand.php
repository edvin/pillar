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

final class MakeEventCommand extends Command
{
    protected $signature = 'pillar:make:event {name? : e.g. InvoiceCreated}
        {--context= : Context name as returned by ContextRegistry::name()}
        {--dir= : Base domain folder (default from config)}
        {--event-dir= : Folder for the Event class (default from config)}
        {--force : Overwrite existing files if they exist}
        {--style= : Placement style}
        {--subcontext= : Subcontext folder}';

    protected $description = 'Create a Domain Event class from a stub.';

    public function handle(
        RegistryFinder    $finder,
        PlacementResolver $resolver,
        Scaffolder        $scaffolder
    ): int {
        // --- Name (PascalCase) ------------------------------------------------
        $name = (string)($this->argument('name') ?? '');
        if ($name === '' || !preg_match('/^[A-Z][A-Za-z0-9]+$/', $name)) {
            $name = text(
                label: 'Event name',
                default: $name,
                validate: fn(string $v) => preg_match('/^[A-Z][A-Za-z0-9]+$/', $v) ? null : 'Use PascalCase (e.g. InvoiceCreated).'
            );
            if ($name === '') {
                $this->error('Aborted.');
                return self::FAILURE;
            }
        }

        // --- Context ----------------------------------------------------------
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

        // --- Placement style / subcontext ------------------------------------
        $choices    = PathStyle::promptOptions();
        $cfgDefault = PathStyle::fromConfig(config('pillar.make.default_style') ?? null)->value;

        $styleOpt  = $this->option('style');
        $styleEnum = is_string($styleOpt) ? PathStyle::tryFrom($styleOpt) : null;
        $style     = $styleEnum?->value
            ?: (string) select(
                label: 'Placement style',
                options: $choices,
                default: $cfgDefault,
                hint: 'Controls where the Event is placed.'
            );

        $subcontextOpt = $this->option('subcontext');
        $subcontext    = $subcontextOpt ? (string) $subcontextOpt : null;

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

        // --- Defaults from config --------------------------------------------
        $cfg            = (array) config('pillar.make.event_defaults', []);
        $defaultEventDir = (string) ($cfg['event_dir'] ?? 'Domain/Event');

        $dirOpt      = $this->option('dir');
        $eventDirOpt = $this->option('event-dir');

        if (is_string($eventDirOpt) && $eventDirOpt !== '') {
            $eventDir = trim($eventDirOpt, '/');
        } elseif (is_string($dirOpt) && $dirOpt !== '') {
            $base = trim($dirOpt, '/');
            $eventFolder = basename(str_replace('\\', '/', $defaultEventDir));
            $eventDir = $base . '/' . $eventFolder;
        } else {
            $eventDir = trim($defaultEventDir, '/');
        }

        // --- Resolve placement ------------------------------------------------
        $placement = $resolver->resolve($registry, 'event', [
            'style'      => $style,
            'subcontext' => $subcontext,
        ], ask: function (string $question, array $options) {
            return (string) select(
                label: $question,
                options: array_combine($options, $options)
            );
        });

        $force = (bool) $this->option('force');

        // --- Write (StubWriter handles overwrite prompt) ----------------------
        try {
            $path = $scaffolder->writeDomainEvent($name, $placement, [
                'event_dir' => $eventDir,
            ], $force);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }

        $this->info('Event created.');
        $this->line('  - ' . $path);
        return self::SUCCESS;
    }
}
// @codeCoverageIgnoreEnd