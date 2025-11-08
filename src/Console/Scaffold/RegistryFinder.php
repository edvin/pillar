<?php
// @codeCoverageIgnoreStart

namespace Pillar\Console\Scaffold;

use ReflectionClass;

final class RegistryFinder
{
    public function selectByContextName(?string $wanted, callable $ask): ?object
    {
        $registries = $this->discoverRegistries();
        if (empty($registries)) {
            return null;
        }

        if ($wanted) {
            foreach ($registries as $name => $instance) {
                if (strcasecmp($name, $wanted) === 0) {
                    return $instance;
                }
            }
        }

        if (count($registries) === 1) {
            return array_values($registries)[0];
        }

        return $ask($registries);
    }

    private function discoverRegistries(): array
    {
        $out = [];
        foreach (get_declared_classes() as $class) {
            try {
                $ref = new ReflectionClass($class);
                if ($ref->isAbstract()) continue;
                if (!$ref->hasMethod('name')) continue;
                $m = $ref->getMethod('name');
                if (!$m->isPublic() || $m->isStatic()) continue;
                $instance = $ref->newInstanceWithoutConstructor();
                $name = $instance->name();
                if (!is_string($name) || $name === '') continue;
                $out[$name] = $instance;
            } catch (\Throwable $e) { continue; }
        }
        return $out;
    }
}
// @codeCoverageIgnoreEnd
