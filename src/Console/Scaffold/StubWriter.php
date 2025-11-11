<?php
declare(strict_types=1);

// @codeCoverageIgnoreStart

namespace Pillar\Console\Scaffold;

use Illuminate\Filesystem\Filesystem;
use RuntimeException;
use function dirname;
use function Laravel\Prompts\confirm;

final class StubWriter
{
    public function __construct(private Filesystem $fs)
    {
    }

    /**
     * Resolve a stub by name (e.g. "aggregate.es") to an absolute file path.
     * Looks in config('pillar.make.stubs_path', base_path('stubs')) first,
     * then falls back to the package stubs directory.
     */
    public function resolve(string $name): string
    {
        $stubName = str_ends_with($name, '.stub') ? $name : "{$name}.stub";
        $userDir = (string)(config('pillar.make.stubs_path') ?? base_path('stubs'));
        $paths = [
            rtrim($userDir, '/') . "/{$stubName}",
            // package fallback (adjust if your package path differs)
            dirname(__DIR__, 3) . '/stubs/' . $stubName,
        ];

        foreach ($paths as $p) {
            if ($this->fs->exists($p)) {
                return $p;
            }
        }

        throw new RuntimeException("Stub not found: {$stubName}");
    }

    /**
     * @param array<string,string> $vars Placeholder map, e.g. ['{{Name}}' => 'Invoice'].
     */
    /** Load stub and replace placeholders. */
    public function render(string $absStubPath, array $vars): string
    {
        $raw = $this->fs->get($absStubPath);
        return strtr($raw, $vars);
    }

    /** Ensure dir exists and write, prompting if needed unless $force = true. */
    public function write(string $absTargetPath, string $contents, bool $force = false): void
    {
        $dir = dirname($absTargetPath);
        if (!$this->fs->isDirectory($dir)) {
            $this->fs->makeDirectory($dir, 0777, true);
        }

        if ($this->fs->exists($absTargetPath) && !$force) {
            $ok = confirm(
                label: 'Overwrite?',
                default: false,
                hint: "File exists: $absTargetPath"
            );
            if (!$ok) {
                throw new RuntimeException("Aborted — file exists: {$absTargetPath}");
            }
        }

        $this->fs->put($absTargetPath, $contents);
    }

    /**
     * @param array<string,string> $vars Placeholder map passed to render().
     */
    /** Convenience: resolve + render + write. */
    public function putFromStub(string $absTargetPath, string $stubName, array $vars, bool $force = false): void
    {
        $stub = $this->resolve($stubName);
        $this->write($absTargetPath, $this->render($stub, $vars), $force);
    }
}
// @codeCoverageIgnoreEnd
