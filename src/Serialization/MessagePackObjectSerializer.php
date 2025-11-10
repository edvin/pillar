<?php
declare(strict_types=1);

namespace Pillar\Serialization;

use RuntimeException;
use Throwable;

/**
 * MessagePack serializer (pecl ext-msgpack ONLY).
 *
 * - Uses msgpack_pack/msgpack_unpack from the pecl extension.
 * - serialize(): normalizes the object to an associative array (via JSON encode/decode) and then packs it.
 * - deserialize(): builds a domain object using ConstructorMetadata (like JsonObjectSerializer).
 * - toArray()/fromArray(): operate on normalized associative arrays for upcasters.
 *
 * Objects are normalized to associative arrays before packing to guarantee string keys.
 *
 * You must have the pecl extension installed and enabled:
 *   pecl install msgpack
 *   # and ensure extension=msgpack is loaded in php.ini
 */
final class MessagePackObjectSerializer implements ObjectSerializer
{
    /** @var array<class-string, ConstructorMetadata> */
    private static array $reflectionCache = [];

    public function __construct()
    {
        // @codeCoverageIgnoreStart
        if (!extension_loaded('msgpack')) {
            throw new RuntimeException('ext-msgpack is required for MessagePackObjectSerializer.');
        }
        // @codeCoverageIgnoreEnd
    }

    /**
     * @throws SerializationException
     */
    public function serialize(object $object): string
    {
        try {
            $normalized = json_decode(json_encode($object, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
            return msgpack_pack($normalized);
        } catch (Throwable $e) {
            throw SerializationException::failed('serialize', $object::class, $e);
        }
    }

    /**
     * @param class-string $class
     * @throws SerializationException
     */
    public function deserialize(string $class, string $payload): object
    {
        if (!class_exists($class)) {
            throw SerializationException::failed('deserialize', $class, new RuntimeException("Unknown class: $class"));
        }

        // Unpack and normalize to assoc array so we can use ConstructorMetadata consistently.
        $data = $this->toArray($payload);

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

    /**
     * @return array<string,mixed>
     * @throws SerializationException
     */
    public function toArray(string $payload): array
    {
        try {
            // Use default unpack signature for widest ext-msgpack compatibility.
            // Older versions may ignore the options array and return stdClass for maps.
            $value = msgpack_unpack($payload);

            // If we received an object map, normalize it to an associative array.
            if ($value instanceof \stdClass) {
                $value = json_decode(json_encode($value, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
            }

            // Ensure we return an associative array for upcasters, even if payload was scalar.
            if (!is_array($value)) {
                return ['_value' => $value];
            }

            return $value;
        } catch (Throwable $e) {
            throw SerializationException::failed('toArray', 'MessagePack', $e);
        }
    }

    /**
     * @param array<string,mixed> $data
     * @throws SerializationException
     */
    public function fromArray(array $data): string
    {
        try {
            return msgpack_pack($data);
        } catch (Throwable $e) {
            throw SerializationException::failed('fromArray', 'MessagePack', $e);
        }
    }
}