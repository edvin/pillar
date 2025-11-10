<?php

use Pillar\Serialization\JsonObjectSerializer;
use Pillar\Serialization\SerializationException;
use Tests\Fixtures\Document\DocumentCreated;
use Tests\Fixtures\Serialization\PingEvent;
use Tests\Fixtures\Serialization\BadEvent;
use Tests\Fixtures\Serialization\RecursiveEvent;

it('round-trips an event through JsonObjectSerializer', function () {
    $ser = new JsonObjectSerializer();

    $evt = new PingEvent(
        id: 'abc-123',
        title: 'hello',
        meta: ['x' => 1, 'y' => 2],
    );

    // Serialize (may return array or JSON string; normalize to array)
    $payload = $ser->serialize($evt);
    $arr = $ser->toArray($payload);

    expect($arr)->toMatchArray([
        'id' => 'abc-123',
        'title' => 'hello',
        'meta' => ['x' => 1, 'y' => 2],
    ]);

    // Deserialize (accepts either array or string)
    $rehydrated = $ser->deserialize(PingEvent::class, $payload);

    expect($rehydrated)->toBeInstanceOf(PingEvent::class)
        ->and($rehydrated->id)->toBe('abc-123')
        ->and($rehydrated->title)->toBe('hello')
        ->and($rehydrated->meta)->toBe(['x' => 1, 'y' => 2]);
});

it('wraps deserialization type errors in SerializationException', function () {
    $ser = new JsonObjectSerializer();

    $badPayloadJson = '{"n":"not-an-int"}';
    expect(fn() => $ser->deserialize(BadEvent::class, $badPayloadJson))
        ->toThrow(SerializationException::class);
});

it('wraps JSON encoding failures in SerializationException', function () {
    $ser = new JsonObjectSerializer();

    // Build a recursive payload which json_encode cannot handle (JSON_THROW_ON_ERROR triggers)
    $meta = [];
    $meta['self'] =& $meta; // recursive reference

    $evt = new RecursiveEvent($meta);

    expect(fn() => $ser->serialize($evt))
        ->toThrow(SerializationException::class);
});

it('wraps invalid JSON payload errors in SerializationException (deserialize)', function () {
    $ser = new JsonObjectSerializer();

    // Bad JSON → json_decode throws → caught and rethrown as SerializationException
    expect(fn() => $ser->deserialize(DocumentCreated::class, '{not: "json"'))
        ->toThrow(SerializationException::class);
});