<?php

namespace Pillar\Serialization;

use RuntimeException;
use Throwable;

final class SerializationException extends RuntimeException
{
    public static function failed(string $action, string $class, Throwable $previous): self
    {
        return new self("Failed to $action object of type $class: {$previous->getMessage()}", 0, $previous);
    }
}