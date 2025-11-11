<?php
declare(strict_types=1);

// @codeCoverageIgnoreStart

namespace Pillar\Console;

use Pillar\Console\Scaffold\Scaffolder;
use Pillar\Console\Scaffold\RegistryEditor;

/**
 * Artisan command: `pillar:make:query`
 *
 * Generates a Query DTO and its Handler, and registers the pair into the selected
 * ContextRegistry. Placement (folders & namespaces) is derived from your
 * `config/pillar.php` → `make` section and the chosen PathStyle.
 *
 * Files created (defaults vary with style):
 *  - Application/Query/{Name}Query.php
 *  - Handler (colocate | mirrored | split | subcontext):
 *      • colocate:           Application/Query/{Name}Handler.php
 *      • mirrored:           Application/Handler/Query/{Name}Handler.php
 *      • split:              Application/Handler/{Name}Handler.php
 *      • subcontext:         <Subcontext>/Application/Handler/{Name}Handler.php
 *
 * Options:
 *  --context=      Context name (ContextRegistry::name()) to target. If omitted, you’ll be prompted.
 *  --subcontext=   Optional extra folder level under the context (used with `style=subcontext`).
 *  --style=        infer|mirrored|split|subcontext|colocate (defaults from config).
 *  --force         Overwrite existing files without prompting.
 *
 * Behavior:
 *  • Prompts for a PascalCase name if not provided (e.g., FindDocument, ListInvoices).
 *  • Delegates path/namespace computation to {@see \Pillar\Console\Scaffold\PlacementResolver}.
 *  • Delegates file planning & stub selection to {@see \Pillar\Console\Scaffold\Scaffolder::planQuery()}.
 *  • Supports conditional handler import via the stub placeholder {{queryImport}} (resolved in Scaffolder).
 *  • Writes files via {@see \Pillar\Console\Scaffold\Scaffolder::execute()} (with overwrite confirmation unless --force).
 *  • Registers the new mapping in your ContextRegistry via {@see \Pillar\Console\Scaffold\RegistryEditor::registerQuery()}.
 *
 * See also:
 *  - {@see MakeCommandCommand} for commands
 *  - {@see MakeAggregateCommand} for aggregate + id scaffolding
 */
final class MakeQueryCommand extends AbstractMakeArtifactCommand
{
    protected $signature = 'pillar:make:query {name? : action, e.g. FindDocument}
        {--context= : Context name as returned by ContextRegistry::name()}
        {--subcontext= : Optional subfolder within the bounded context}
        {--style= : infer|mirrored|split|subcontext|colocate}
        {--force : Overwrite existing files if they exist}';

    protected $description = 'Create a Query + Handler and register them in the selected ContextRegistry.';

    protected function artifactKey(): string
    {
        return 'query';
    }

    protected function nameLabel(): string
    {
        return 'Query name';
    }

    protected function nameHint(): string
    {
        return 'Example: FindDocument, ListInvoices';
    }

    protected function subcontextPlaceholder(): string
    {
        return 'Reader';
    }

    protected function buildPlan(Scaffolder $scaffolder, string $name, array $placement): object
    {
        return $scaffolder->planQuery($name, $placement);
    }

    protected function registerPlan(RegistryEditor $editor, object $registry, object $plan): void
    {
        $editor->registerQuery($registry, $plan);
    }
}
// @codeCoverageIgnoreEnd