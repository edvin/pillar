<?php

use Pillar\Serialization\ObjectSerializer;
use Pillar\Serialization\SerializationException;
use Tests\Fixtures\Serialization\PingEvent;
use Tests\Fixtures\Serialization\StrictEvent;
use Tests\Fixtures\Serialization\OptionalEvent;

it('throws on unknown class during deserialize', function () {
    /** @var ObjectSerializer $ser */
    $ser = app(ObjectSerializer::class);

    expect(fn() => $ser->deserialize('Nope\\Such\\Class', '{"a":1}'))
        ->toThrow(SerializationException::class);
});

it('toArray throws on invalid JSON input', function () {
    /** @var ObjectSerializer $ser */
    $ser = app(ObjectSerializer::class);

    expect(fn() => $ser->toArray('{"oops"'))
        ->toThrow(SerializationException::class);
});

it('fromArray throws on invalid UTF-8', function () {
    /** @var ObjectSerializer $ser */
    $ser = app(ObjectSerializer::class);

    // Build a string with invalid UTF-8 bytes
    $bad = ["s" => "\xB1\x31"];

    expect(fn() => $ser->fromArray($bad))
        ->toThrow(SerializationException::class);
});

it('ignores extra payload fields during deserialize (tolerant)', function () {
    /** @var ObjectSerializer $ser */
    $ser = app(ObjectSerializer::class);

    $payload = json_encode([
        'id' => 'abc-123',
        'title' => 'hello',
        'meta' => ['x' => 1],
        'extra' => 'ignored',
    ], JSON_THROW_ON_ERROR);

    $obj = $ser->deserialize(PingEvent::class, $payload);

    expect($obj)->toBeInstanceOf(PingEvent::class)
        ->and($obj->id)->toBe('abc-123')
        ->and($obj->title)->toBe('hello')
        ->and($obj->meta)->toBe(['x' => 1]);
});

it('throws when a required ctor param is missing', function () {
    /** @var ObjectSerializer $ser */
    $ser = app(ObjectSerializer::class);

    // StrictEvent requires "n"
    expect(fn() => $ser->deserialize(StrictEvent::class, '{}'))
        ->toThrow(SerializationException::class);
});

it('uses defaults/nullable when fields are omitted', function () {
    /** @var ObjectSerializer $ser */
    $ser = app(ObjectSerializer::class);

    // OptionalEvent: n defaults to null, t to 'x'
    $obj = $ser->deserialize(OptionalEvent::class, '{}');

    expect($obj)->toBeInstanceOf(OptionalEvent::class)
        ->and($obj->n)->toBeNull()
        ->and($obj->t)->toBe('x');
});