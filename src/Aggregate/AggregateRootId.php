<?php

namespace Pillar\Aggregate;

use Illuminate\Support\Str;
use InvalidArgumentException;
use JsonSerializable;
use Stringable;

abstract readonly class AggregateRootId implements Stringable, JsonSerializable
{
    public abstract static function aggregateClass();

    public function __construct(
        protected string $value
    )
    {
        if (!Str::isUuid($value)) {
            throw new InvalidArgumentException("Invalid UUID: $value");
        }
    }

    public static function new(): static
    {
        return new static(Str::uuid()->toString());
    }

    public static function from(mixed $value): static
    {
        if ($value instanceof self) {
            return $value;
        }

        if (is_object($value)) {
            if (method_exists($value, 'getAttribute')) {
                $value = $value->getAttribute('id');
            } elseif (isset($value->id)) {
                $value = $value->id;
            }
        }

        if (is_array($value) && isset($value['id'])) {
            $value = $value['id'];
        }

        if (!is_string($value) || !Str::isUuid($value)) {
            throw new InvalidArgumentException("Invalid UUID: " . json_encode($value));
        }

        return new static($value);
    }

    public function equals(self $other): bool
    {
        return $other::class === static::class && $this->value === $other->value;
    }

    public function value(): string
    {
        return $this->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }

    public function jsonSerialize(): string
    {
        return $this->value;
    }
}