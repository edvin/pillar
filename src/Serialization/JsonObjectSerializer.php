<?php

namespace Pillar\Serialization;

use ReflectionException;
use RuntimeException;
use Throwable;

final class JsonObjectSerializer implements ObjectSerializer
{
    private static array $reflectionCache = [];

    public function serialize(object $object): string
    {
        try {
            return json_encode($object, JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            throw SerializationException::failed('serialize', get_class($object), $e);
        }
    }

    /**
     * @throws ReflectionException
     */
    public function deserialize(string $class, string $payload): object
    {
        try {
            $data = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            throw SerializationException::failed('deserialize', $class, $e);
        }

        if (!class_exists($class)) {
            throw SerializationException::failed('deserialize', $class, new RuntimeException("Unknown class: $class"));
        }

        try {
            if (!isset(self::$reflectionCache[$class])) {
                self::$reflectionCache[$class] = ConstructorMetadata::fromClass($class);
            }

            $meta = self::$reflectionCache[$class];
            $args = [];

            foreach ($meta->parameters as $param) {
                $args[] = $param->resolveValue($data);
            }

            return $meta->newInstance($args, $class);
        } catch (Throwable $e) {
            throw SerializationException::failed('deserialize', $class, $e);
        }
    }

    public function toArray(string $payload): array
    {
        try {
            return json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            throw SerializationException::failed('toArray', 'JSON', $e);
        }
    }

    public function fromArray(array $data): string
    {
        try {
            return json_encode($data, JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            throw SerializationException::failed('fromArray', 'JSON', $e);
        }
    }
}
