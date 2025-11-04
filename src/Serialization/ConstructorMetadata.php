<?php

namespace Pillar\Serialization;

use ReflectionException;

final class ConstructorMetadata
{
    /** @param ParameterMetadata[] $parameters */
    public function __construct(
        public readonly ?\ReflectionMethod $constructor,
        public readonly array              $parameters
    )
    {
    }

    /**
     * @throws ReflectionException
     */
    public function newInstance(array $args, string $class): object
    {
        if (!$this->constructor) {
            return new $class();
        }

        return $this->constructor->getDeclaringClass()->newInstanceArgs($args);
    }

    /**
     * @throws ReflectionException
     */
    public static function fromClass(string $class): self
    {
        $reflection = new \ReflectionClass($class);
        $constructor = $reflection->getConstructor();

        if (!$constructor) {
            return new self(null, []);
        }

        $params = [];
        foreach ($constructor->getParameters() as $param) {
            $type = $param->getType();
            $typeName = $type?->getName();
            $hasFrom = $typeName && method_exists($typeName, 'from');

            $params[] = new ParameterMetadata(
                $param->getName(),
                $type !== null,
                $type?->isBuiltin() ?? false,
                $typeName,
                $param->isDefaultValueAvailable(),
                $param->isDefaultValueAvailable() ? $param->getDefaultValue() : null,
                $hasFrom,
            );
        }

        return new self($constructor, $params);
    }
}