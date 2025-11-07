<?php

use Pillar\Serialization\ConstructorMetadata;
use Pillar\Serialization\ObjectSerializer;

final class _NoCtorEvent
{
    public int $n = 42; // just to have a property
}

it('fromClass handles classes without constructor and newInstance builds them', function () {
    $meta = ConstructorMetadata::fromClass(_NoCtorEvent::class);

    // Hits: if (!$constructor) in fromClass
    expect($meta->constructor)->toBeNull()
        ->and($meta->parameters)->toBe([]);

    // Hits: if (!$this->constructor) return new $class() in newInstance
    $obj = $meta->newInstance([], _NoCtorEvent::class);
    expect($obj)->toBeInstanceOf(_NoCtorEvent::class);
});

it('JsonObjectSerializer can deserialize classes without constructor', function () {
    /** @var ObjectSerializer $ser */
    $ser = app(ObjectSerializer::class);

    // Goes through deserialize() → fromClass() (constructor=null) → newInstance()
    $obj = $ser->deserialize(_NoCtorEvent::class, '{}');

    expect($obj)->toBeInstanceOf(_NoCtorEvent::class);
});