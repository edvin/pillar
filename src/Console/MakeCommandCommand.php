<?php
declare(strict_types=1);
// @codeCoverageIgnoreStart

namespace Pillar\Console;

use Pillar\Console\Scaffold\Scaffolder;
use Pillar\Console\Scaffold\RegistryEditor;

/**
 * Artisan command: pillar:make:command
 *
 * Creates a Command + its Handler using the unified Scaffolder plan/execute flow
 * and registers both into the selected ContextRegistry via RegistryEditor.
 *
 * This concrete command supplies command-specific labels/placeholders and defers
 * all prompting, placement resolution, and file I/O to AbstractMakeArtifactCommand.
 */
final class MakeCommandCommand extends AbstractMakeArtifactCommand
{
    protected $signature = 'pillar:make:command {name? : action, e.g. RenameDocument}
        {--context= : Context name as returned by ContextRegistry::name()}
        {--subcontext= : Optional subfolder within the bounded context}
        {--style= : infer|mirrored|split|subcontext|colocate}
        {--force : Overwrite existing files if they exist}';

    protected $description = 'Create a Command + Handler and register them in the selected ContextRegistry.';

    protected function artifactKey(): string
    {
        return 'command';
    }

    protected function nameLabel(): string
    {
        return 'Command name';
    }

    protected function nameHint(): string
    {
        return 'Example: RenameDocument, PublishInvoice';
    }

    protected function subcontextPlaceholder(): string
    {
        return 'Writer';
    }

    protected function buildPlan(Scaffolder $scaffolder, string $name, array $placement): object
    {
        return $scaffolder->planCommand($name, $placement);
    }

    protected function registerPlan(RegistryEditor $editor, object $registry, object $plan): void
    {
        $editor->registerCommand($registry, $plan);
    }
}
// @codeCoverageIgnoreEnd