<?php
declare(strict_types=1);

// @codeCoverageIgnoreStart

namespace Pillar\Console\Scaffold;

use RuntimeException;

final class Scaffolder
{
    public function __construct(private StubWriter $stubs)
    {
    }

    /**
     * Validate and normalize a placement array.
     * Ensures required keys are present and normalizes namespace separators.
     *
     * @param array $placement
     * @return array
     */
    private function normalizePlacement(array $placement): array
    {
        if (!isset($placement['namespaces']['messageNs'], $placement['namespaces']['handlerNs'])
            || !isset($placement['paths']['messagePath'], $placement['paths']['handlerPath'])) {
            throw new RuntimeException('Invalid placement: missing required namespace/path keys.');
        }

        // Normalize namespace slashes just in case
        $placement['namespaces']['messageNs'] = str_replace('/', '\\', $placement['namespaces']['messageNs']);
        $placement['namespaces']['handlerNs'] = str_replace('/', '\\', $placement['namespaces']['handlerNs']);

        return $placement;
    }

    /**
     * Build a plan for a message+handler pair (command or query).
     *
     * @param 'command'|'query' $kind
     * @param string            $name       Base name in PascalCase (e.g., RenameDocument)
     * @param array             $placement  Result of PlacementResolver::resolve(...)
     */
    private function planMessage(string $kind, string $name, array $placement): object
    {
        $isCommand = $kind === 'command';
        $msgSuffix = $isCommand ? 'Command' : 'Query';
        $cmdOrQry  = $name . $msgSuffix;
        $handler   = $name . 'Handler';

        $placement = $this->normalizePlacement($placement);

        $messageNs = $placement['namespaces']['messageNs']; // e.g. App\X\Application\Command
        $handlerNs = $placement['namespaces']['handlerNs']; // may be overridden below

        $messagePath = $placement['paths']['messagePath'] . '/' . $cmdOrQry . '.php';
        $handlerPath = $placement['paths']['handlerPath'] . '/' . $handler . '.php';

        // We emit array entries (Message => Handler) for the registry
        $registrationLines = [
            $messageNs . '\\' . $cmdOrQry . '::class => ' . $handlerNs . '\\' . $handler . '::class,',
        ];

        // Conditional import for handler: only needed when namespaces differ
        $needsImport = ($handlerNs !== $messageNs);
        if ($isCommand) {
            $commandImport = $needsImport ? ('use ' . $messageNs . '\\' . $cmdOrQry . ';') : '';
            $queryImport   = '';
        } else {
            $queryImport   = $needsImport ? ('use ' . $messageNs . '\\' . $cmdOrQry . ';') : '';
            $commandImport = '';
        }

        // Unified file list with explicit vars per file
        $vars = [
            '{{namespace}}'         => $messageNs,
            '{{handlerNamespace}}'  => $handlerNs,
            '{{Name}}'              => $name,
            '{{commandImport}}'     => $commandImport,
            '{{queryImport}}'       => $queryImport,
        ];

        return (object)[
            'type'             => $kind,
            'name'             => $name,
            'messageClass'     => $messageNs . '\\' . $cmdOrQry,
            'handlerClass'     => $handlerNs . '\\' . $handler,
            'messagePath'      => $messagePath,
            'handlerPath'      => $handlerPath,
            'registrationLines'=> $registrationLines,

            // New unified representation used by execute()
            'files'            => [
                [
                    'path' => $messagePath,
                    'stub' => $isCommand ? 'command' : 'query',
                    'vars' => $vars,
                ],
                [
                    'path' => $handlerPath,
                    'stub' => $isCommand ? 'command_handler' : 'query_handler',
                    'vars' => $vars,
                ],
            ],
        ];
    }

    public function planCommand(string $name, array $placement): object
    {
        return $this->planMessage('command', $name, $placement);
    }

    public function planQuery(string $name, array $placement): object
    {
        return $this->planMessage('query', $name, $placement);
    }

    /**
     * Plan files for an Aggregate + Id pair.
     *
     * Options:
     *  - aggregate_dir (string, default 'Domain/Aggregate')
     *  - id_dir        (string, default same as aggregate_dir)
     *  - event_sourced (bool,  default true) → selects stub: aggregate.es vs aggregate.plain
     */
    public function planAggregate(string $name, array $placement, array $options = []): object
    {
        if (!isset($placement['basePath'], $placement['baseNamespace'])) {
            throw new RuntimeException('Invalid placement: missing basePath/baseNamespace.');
        }

        $aggDir = trim((string)($options['aggregate_dir'] ?? 'Domain/Aggregate'), '/');
        $idDir  = trim((string)($options['id_dir'] ?? $aggDir), '/');
        $isEs   = (bool)($options['event_sourced'] ?? true);

        $basePath = rtrim($placement['basePath'], '/');        // already includes the context directory
        $baseNs   = trim($placement['baseNamespace'], '\\');   // already includes the context namespace

        $aggNs = $baseNs . '\\' . str_replace('/', '\\', $aggDir);
        $idNs  = $baseNs . '\\' . str_replace('/', '\\', $idDir);

        $needsIdImport = ($aggNs !== $idNs);
        $idImport = $needsIdImport ? ('use ' . $idNs . '\\' . $name . 'Id;') : '';

        $aggPath = $basePath . '/' . $aggDir . '/' . $name . '.php';
        $idPath  = $basePath . '/' . $idDir  . '/' . $name . 'Id.php';

        $vars = [
            '{{Name}}'              => $name,
            '{{aggregateNamespace}}'=> $aggNs,
            '{{idNamespace}}'       => $idNs,
            '{{idImport}}'           => $idImport,
        ];

        return (object)[
            'type'            => 'aggregate',
            'name'            => $name,
            'aggregateClass'  => $aggNs . '\\' . $name,
            'idClass'         => $idNs . '\\' . $name . 'Id',
            'aggregatePath'   => $aggPath,
            'idPath'          => $idPath,
            'namespaces'      => [
                'aggregateNs' => $aggNs,
                'idNs'        => $idNs,
            ],

            // New unified representation used by execute()
            'files'           => [
                [
                    'path' => $idPath,
                    'stub' => 'aggregate.id',
                    'vars' => $vars,
                ],
                [
                    'path' => $aggPath,
                    'stub' => $isEs ? 'aggregate.es' : 'aggregate.plain',
                    'vars' => $vars,
                ],
            ],
        ];
    }

    /**
     * Write a ContextRegistry class file from the bundled stub.
     * Returns the absolute path of the written file.
     */
    public function writeContextRegistry(string $namespace, string $name, string $dir, bool $force = false): string
    {
        $dir = rtrim($dir, '/');
        $filePath = $dir . '/' . $name . 'ContextRegistry.php';

        $vars = [
            '{{namespace}}' => $namespace,
            '{{Name}}'      => $name,
        ];

        $this->stubs->putFromStub($filePath, 'context_registry', $vars, $force);

        return $filePath;
    }

    /**
     * Write a Domain Event from the bundled stub using placement.
     * @param array $placement Must include basePath and baseNamespace
     * @param array $options ['event_dir' => 'Domain/Event']
     * @return string Absolute path written
     */
    public function writeDomainEvent(string $name, array $placement, array $options = [], bool $force = false): string
    {
        if (!isset($placement['basePath'], $placement['baseNamespace'])) {
            throw new RuntimeException('Invalid placement: missing basePath/baseNamespace.');
        }

        $basePath = rtrim($placement['basePath'], '/');
        $baseNs   = trim((string)$placement['baseNamespace'], '\\');
        $eventRel = trim((string)($options['event_dir'] ?? 'Domain/Event'), '/');

        $eventPath = $basePath . '/' . $eventRel . '/' . $name . '.php';
        $eventNs   = $baseNs . '\\' . str_replace('/', '\\', $eventRel);

        $vars = [
            '{{namespace}}' => $eventNs,
            '{{Name}}'      => $name,
        ];

        $this->stubs->putFromStub($eventPath, 'domain_event', $vars, $force);
        return $eventPath;
    }

    public function execute(object $plan, bool $force = false): void
    {
        if (!isset($plan->files) || !is_array($plan->files)) {
            throw new RuntimeException('Invalid plan: missing files array.');
        }

        foreach ($plan->files as $file) {
            $this->stubs->putFromStub(
                $file['path'],
                $file['stub'],
                $file['vars'],
                $force
            );
        }
    }
}
// @codeCoverageIgnoreEnd