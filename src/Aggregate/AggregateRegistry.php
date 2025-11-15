<?php

namespace Pillar\Aggregate;

use InvalidArgumentException;
use RuntimeException;

final class AggregateRegistry
{
    /** @var array<string, class-string<AggregateRootId>> */
    private array $prefixToIdClass = [];

    public function register(string $idClass): void
    {
        if (!is_subclass_of($idClass, AggregateRootId::class)) {
            throw new InvalidArgumentException("$idClass is not an AggregateRootId");
        }

        /** @var class-string<AggregateRootId> $idClass */
        $prefix = $idClass::streamPrefix();

        $this->prefixToIdClass[$prefix] = $idClass;
    }

    public function toStreamName(AggregateRootId $id): string
    {
        $prefix = $id::streamPrefix();

        return sprintf('%s-%s', $prefix, $id->value());
    }

    public function idFromStreamName(string $streamName): AggregateRootId
    {
        [$prefix, $rawId] = explode('-', $streamName, 2);

        $idClass = $this->prefixToIdClass[$prefix]
            ?? throw new RuntimeException("Unknown aggregate prefix: $prefix");

        return $idClass::from($rawId);
    }
}