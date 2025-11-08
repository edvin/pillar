<?php
/** @noinspection DuplicatedCode */
// @codeCoverageIgnoreStart

namespace Pillar\Console\Scaffold;

use RuntimeException;

final class Scaffolder
{

    public function planCommand(string $name, array $placement): object
    {
        $cmdClass = $name . 'Command';
        $handlerClass = $name . 'Handler';

        $messageNs = $placement['namespaces']['messageNs']; // e.g. App\X\Application\Command
        $handlerNs = $placement['namespaces']['handlerNs']; // may be overridden below

        $messagePath = $placement['paths']['messagePath'] . '/' . $cmdClass . '.php';
        $handlerPath = $placement['paths']['handlerPath'] . '/' . $handlerClass . '.php';

        if ($placement['style'] === PathStyle::Mirrored) {
            // Filesystem
            $handlerPath = dirname($messagePath, 2) . '/Handler/Command/' . $handlerClass . '.php';

            // Namespace: derive base from messageNs by removing trailing "\Command" and appending "\Handler\Command"
            $baseNs = preg_replace('/\\\\Command$/', '', $messageNs);
            $handlerNs = $baseNs . '\\Handler\\Command';
        } elseif ($placement['style'] === PathStyle::Colocate) {
            // handler sits right next to the command
            $handlerPath = $placement['paths']['messagePath'] . '/' . $handlerClass . '.php';
            $handlerNs = $messageNs;
        }

        // normalize namespaces just in case
        $messageNs = str_replace('/', '\\', $messageNs);
        $handlerNs = str_replace('/', '\\', $handlerNs);

        // We emit array entries (Command => Handler) for the registry
        $registrationLines = [
            $messageNs . '\\' . $cmdClass . '::class => ' . $handlerNs . '\\' . $handlerClass . '::class,',
        ];

        return (object)[
            'type' => 'command',
            'name' => $name,
            'messageClass' => $messageNs . '\\' . $cmdClass,
            'handlerClass' => $handlerNs . '\\' . $handlerClass,
            'messagePath' => $messagePath,
            'handlerPath' => $handlerPath,
            'registrationLines' => $registrationLines,
            'stubs' => [
                'message' => __DIR__ . '/../../../stubs/command.stub',
                'handler' => __DIR__ . '/../../../stubs/command_handler.stub',
            ],
        ];
    }

    public function planQuery(string $name, array $placement): object
    {
        $qryClass = $name . 'Query';
        $handlerClass = $name . 'Handler';

        $messageNs = $placement['namespaces']['messageNs']; // e.g. App\X\Application\Query
        $handlerNs = $placement['namespaces']['handlerNs']; // may be overridden below

        $messagePath = $placement['paths']['messagePath'] . '/' . $qryClass . '.php';
        $handlerPath = $placement['paths']['handlerPath'] . '/' . $handlerClass . '.php';

        if ($placement['style'] === PathStyle::Mirrored) {
            // Filesystem
            $handlerPath = dirname($messagePath, 2) . '/Handler/Query/' . $handlerClass . '.php';

            // Namespace: derive base from messageNs by removing trailing "\Query" and appending "\Handler\Query"
            $baseNs = preg_replace('/\\\\Query$/', '', $messageNs);
            $handlerNs = $baseNs . '\\Handler\\Query';
        } elseif ($placement['style'] === PathStyle::Colocate) {
            // handler sits right next to the query
            $handlerPath = $placement['paths']['messagePath'] . '/' . $handlerClass . '.php';
            $handlerNs = $messageNs;
        }

        // normalize namespaces just in case
        $messageNs = str_replace('/', '\\', $messageNs);
        $handlerNs = str_replace('/', '\\', $handlerNs);

        // We emit array entries (Query => Handler) for the registry
        $registrationLines = [
            $messageNs . '\\' . $qryClass . '::class => ' . $handlerNs . '\\' . $handlerClass . '::class,',
        ];

        return (object)[
            'type' => 'query',
            'name' => $name,
            'messageClass' => $messageNs . '\\' . $qryClass,
            'handlerClass' => $handlerNs . '\\' . $handlerClass,
            'messagePath' => $messagePath,
            'handlerPath' => $handlerPath,
            'registrationLines' => $registrationLines,
            'stubs' => [
                'message' => __DIR__ . '/../../../stubs/query.stub',
                'handler' => __DIR__ . '/../../../stubs/query_handler.stub',
            ],
        ];
    }

    public function execute(object $plan, bool $force = false): void
    {
        $this->ensureDir(dirname($plan->messagePath));
        $this->ensureDir(dirname($plan->handlerPath));

        if (!$force) {
            if (file_exists($plan->messagePath)) throw new RuntimeException('File exists: ' . $plan->messagePath);
            if (file_exists($plan->handlerPath)) throw new RuntimeException('File exists: ' . $plan->handlerPath);
        }

        $msg = $this->render(file_get_contents($plan->stubs['message']), $plan);
        $hdl = $this->render(file_get_contents($plan->stubs['handler']), $plan);

        file_put_contents($plan->messagePath, $msg);
        file_put_contents($plan->handlerPath, $hdl);
    }

    private function ensureDir(string $dir): void
    {
        if (!is_dir($dir)) mkdir($dir, 0777, true);
    }

    private function render(string $stub, object $plan): string
    {
        $messageNs = $this->namespaceOf($plan->messageClass);
        $handlerNs = $this->namespaceOf($plan->handlerClass);

        return str_replace(
            ['{{namespace}}', '{{handlerNamespace}}', '{{Name}}'],
            [$messageNs, $handlerNs, $plan->name],
            $stub
        );
    }

    /**
     * Extracts the namespace part of a fully-qualified class name.
     * Example: "App\Foo\Bar\Baz" -> "App\Foo\Bar"
     */
    private function namespaceOf(string $fqcn): string
    {
        $fqcn = ltrim($fqcn, '\\');
        $pos = strrpos($fqcn, '\\');
        return $pos !== false ? substr($fqcn, 0, $pos) : '';
    }
}
// @codeCoverageIgnoreEnd