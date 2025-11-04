<?php

namespace Pillar\Serialization;

interface ObjectSerializer
{
    /**
     * @throws SerializationException
     * @param object $object
     * @return string
     */
    public function serialize(object $object): string;

    /**
     * @throws SerializationException
     * @param string $class
     * @param string $payload
     * @return object
     */
    public function deserialize(string $class, string $payload): object;

    /**
     * Convert a serialized payload into a normalized associative array that
     * upcasters can work with, regardless of the underlying codec.
     *
     * @return array<string,mixed>
     * @throws SerializationException
     */
    public function toArray(string $payload): array;

    /**
     * Re-encode a normalized associative array back into the serializer’s
     * native wire format.
     *
     * @param array<string,mixed> $data
     * @throws SerializationException
     */
    public function fromArray(array $data): string;
}
