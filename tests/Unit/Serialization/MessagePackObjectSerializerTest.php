<?php

use Pillar\Serialization\MessagePackObjectSerializer;
use Pillar\Serialization\SerializationException;
use Tests\Fixtures\Document\DocumentCreated;
use Tests\Fixtures\Serialization\PingEvent;
use Tests\Fixtures\Serialization\BadEvent;
use Tests\Fixtures\Serialization\RecursiveEvent;

it('round-trips an event through MessagePackObjectSerializer', function () {
    $ser = new MessagePackObjectSerializer();

    $evt = new PingEvent(
        id: 'abc-123',
        title: 'hello',
        meta: ['x' => 1, 'y' => 2],
    );

    // Serialize (MessagePack binary) and normalize to array for assertions
    $payload = $ser->serialize($evt);
    $arr = $ser->toArray($payload);

    expect($arr)->toMatchArray([
        'id' => 'abc-123',
        'title' => 'hello',
        'meta' => ['x' => 1, 'y' => 2],
    ]);

    // Deserialize back into a strongly-typed event
    $rehydrated = $ser->deserialize(PingEvent::class, $payload);

    expect($rehydrated)->toBeInstanceOf(PingEvent::class)
        ->and($rehydrated->id)->toBe('abc-123')
        ->and($rehydrated->title)->toBe('hello')
        ->and($rehydrated->meta)->toBe(['x' => 1, 'y' => 2]);
});

it('wraps deserialization type errors in SerializationException (msgpack payload)', function () {
    $ser = new MessagePackObjectSerializer();

    // Build a MessagePack payload with the wrong type for "n"
    $badPayload = msgpack_pack(['n' => 'not-an-int']);

    expect(fn() => $ser->deserialize(BadEvent::class, $badPayload))
        ->toThrow(SerializationException::class);
});

it('wraps encoding failures in SerializationException (serialize)', function () {
    $ser = new MessagePackObjectSerializer();

    // This object holds a resource; JSON with JSON_THROW_ON_ERROR cannot encode resources.
    $evt = new class {
        public string $id = 'bad';
        public $stream;
        public function __construct() { $this->stream = fopen('php://temp', 'r'); }
        public function __destruct() { if (is_resource($this->stream)) fclose($this->stream); }
    };

    expect(fn () => $ser->serialize($evt))
        ->toThrow(SerializationException::class);
});

it('wraps invalid MessagePack payload errors in SerializationException (deserialize)', function () {
    $ser = new MessagePackObjectSerializer();

    // Not a valid MessagePack blob; unpack/constructor mapping should fail and be wrapped
    $notMsgpack = "this-is-not-msgpack";

    expect(fn() => $ser->deserialize(DocumentCreated::class, $notMsgpack))
        ->toThrow(SerializationException::class);
});

it('throws SerializationException when deserializing into an unknown class', function () {
    $ser = new MessagePackObjectSerializer();

    $payload = msgpack_pack(['any' => 'thing']); // content is irrelevant; guard is first
    expect(fn () => $ser->deserialize('\\This\\Class\\Does\\Not\\Exist', $payload))
        ->toThrow(SerializationException::class);
});

it('toArray() normalizes stdClass (object map) to associative array', function () {
    $ser = new MessagePackObjectSerializer();

    // Build a nested stdClass structure
    $obj = (object)[
        'id'   => 'obj-1',
        'meta' => (object)['a' => 1, 'b' => 2],
    ];

    // Pack the stdClass; on unpack, some builds return stdClass for maps.
    $payload = msgpack_pack($obj);
    $arr = $ser->toArray($payload);

    // Should be a plain PHP array after normalization
    expect($arr)->toBeArray()
        ->and($arr)->toMatchArray([
            'id' => 'obj-1',
            'meta' => ['a' => 1, 'b' => 2],
        ]);
});

it('toArray() wraps non-array scalars under _value', function () {
    $ser = new MessagePackObjectSerializer();

    // Pack a scalar (string or int)
    $payload = msgpack_pack(42);
    $arr = $ser->toArray($payload);

    expect($arr)->toMatchArray(['_value' => 42]);
});

it('fromArray() round-trips arrays via MessagePack', function () {
    $ser = new MessagePackObjectSerializer();

    $data = ['foo' => 'bar', 'n' => 123, 'nested' => ['x' => true]];
    $payload = $ser->fromArray($data);   // msgpack binary
    $decoded = $ser->toArray($payload);  // back to array

    expect($decoded)->toMatchArray($data);
});

it('fromArray() wraps encoding failures in SerializationException', function () {
    $ser = new MessagePackObjectSerializer();

    // msgpack_pack cannot encode resources; force an encoding error
    $res = fopen('php://temp', 'r');
    try {
        $data = ['bad' => $res];
        expect(fn () => $ser->fromArray($data))
            ->toThrow(SerializationException::class);
    } finally {
        if (is_resource($res)) { fclose($res); }
    }
});