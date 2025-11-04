<?php

namespace Pillar\Serialization;

final class ParameterMetadata
{
    public function __construct(
        public readonly string  $name,
        public readonly bool    $hasType,
        public readonly bool    $isBuiltin,
        public readonly ?string $typeName,
        public readonly bool    $hasDefault,
        public readonly mixed   $default,
        public readonly bool    $hasFromMethod,
    )
    {
    }

    public function resolveValue(array $data): mixed
    {
        $value = $data[$this->name] ?? ($this->hasDefault ? $this->default : null);

        if ($this->hasType && !$this->isBuiltin && $value !== null) {
            $type = $this->typeName;
            if ($this->hasFromMethod) {
                return $type::from($value);
            }
            return new $type($value);
        }

        return $value;
    }
}