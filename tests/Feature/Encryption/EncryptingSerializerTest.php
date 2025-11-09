<?php

use Pillar\Serialization\ObjectSerializer;
use Pillar\Serialization\JsonObjectSerializer;
use Pillar\Security\LaravelPayloadCipher;
use Tests\Fixtures\Encryption\DummyEvent;
use Tests\Fixtures\Encryption\OtherEvent;
use Tests\Fixtures\Encryption\ExplodingCipher;

beforeEach(function () {
    // Base serializer stays JSON unless project overrides it
    config()->set('pillar.serializer.class', JsonObjectSerializer::class);

    // Default encryption knobs for each test to tweak
    config()->set('pillar.serializer.encryption.enabled', false);
    config()->set('pillar.serializer.encryption.default', false);
    config()->set('pillar.serializer.encryption.event_overrides', []);
    config()->set('pillar.serializer.encryption.cipher.class', LaravelPayloadCipher::class);

    // Ensure ObjectSerializer resolves to the encrypting wrapper in tests
    app()->bind(\Pillar\Serialization\ObjectSerializer::class, fn($app) => $app->make(\Pillar\Security\EncryptingSerializer::class));
});

it('serializes plain when encryption is disabled', function () {
    /** @var ObjectSerializer $serializer */
    $serializer = app(ObjectSerializer::class);

    $event = new DummyEvent('1', 'Title');

    $wire = $serializer->serialize($event);
    $base = new JsonObjectSerializer()->serialize($event);

    expect($wire)->toBe($base);

    $round = $serializer->deserialize(DummyEvent::class, $wire);
    expect($round)->toEqual($event);
});

it('encrypts and round-trips when enabled by default', function () {
    config()->set('pillar.serializer.encryption.enabled', true);
    config()->set('pillar.serializer.encryption.default', true);

    /** @var ObjectSerializer $serializer */
    $serializer = app(ObjectSerializer::class);

    $event = new DummyEvent('abc', 'Hello');

    $encrypted = $serializer->serialize($event);
    $base = new JsonObjectSerializer()->serialize($event);

    // ciphertext should differ from base
    expect($encrypted)->not()->toBe($base);

    // toArray should expose normalized plaintext structure
    $arr = $serializer->toArray($encrypted);
    $baseArr = new JsonObjectSerializer()->toArray($base);
    expect($arr)->toEqual($baseArr);

    // and full round-trip
    $round = $serializer->deserialize(DummyEvent::class, $encrypted);
    expect($round)->toEqual($event);
});

it('respects per-event overrides (only selected events encrypted)', function () {
    config()->set('pillar.serializer.encryption.enabled', true);
    config()->set('pillar.serializer.encryption.default', false); // encrypt none by default
    config()->set('pillar.serializer.encryption.event_overrides', [
        DummyEvent::class => true, // but encrypt DummyEvent
    ]);

    /** @var ObjectSerializer $serializer */
    $serializer = app(ObjectSerializer::class);

    $e1 = new DummyEvent('x', 'y');
    $e2 = new OtherEvent('p', 'q');

    $w1 = $serializer->serialize($e1);
    $w2 = $serializer->serialize($e2);

    $base1 = new JsonObjectSerializer()->serialize($e1);
    $base2 = new JsonObjectSerializer()->serialize($e2);

    // Only DummyEvent should be encrypted by override
    expect($w1)->not()->toBe($base1)
        ->and($w2)->toBe($base2)
        ->and($serializer->deserialize(DummyEvent::class, $w1))->toEqual($e1)
        ->and($serializer->deserialize(OtherEvent::class, $w2))->toEqual($e2);

});

it('does not instantiate cipher when encryption is disabled (lazy)', function () {
    // Point to a cipher whose constructor throws if constructed
    config()->set('pillar.serializer.encryption.cipher.class', ExplodingCipher::class);
    config()->set('pillar.serializer.encryption.enabled', false);
    config()->set('pillar.serializer.encryption.default', false);

    /** @var ObjectSerializer $serializer */
    $serializer = app(ObjectSerializer::class);

    $e = new DummyEvent('lazy', 'cipher');

    $wire = $serializer->serialize($e); // should not throw
    expect($wire)->toBe(new JsonObjectSerializer()->serialize($e));

    $arr = $serializer->toArray($wire);
    expect($arr)->toBeArray();

    $obj = $serializer->deserialize(DummyEvent::class, $wire);
    expect($obj)->toEqual($e);
});

it('returns null for non-envelope payloads', function () {
    $cipher = app(LaravelPayloadCipher::class);
    $plain = new JsonObjectSerializer()->serialize(new DummyEvent('id', 'title'));

    expect($cipher->tryDecryptString($plain))->toBeNull();
});

it('returns null for malformed envelope content', function () {
    $cipher = app(LaravelPayloadCipher::class);

    // Prefixed but not a valid envelope
    expect($cipher->tryDecryptString('PILLAR_ENC:garbage'))->toBeNull();

    // Prefixed with base64 but missing required fields inside
    $bad = 'PILLAR_ENC:' . base64_encode('{"oops":1}');
    expect($cipher->tryDecryptString($bad))->toBeNull();
});

it('returns null when payload was encrypted with a different key', function () {
    // Key A: encrypt
    config()->set('app.key', 'base64:' . base64_encode(random_bytes(32)));
    app()->forgetInstance('encrypter'); // ensure a fresh encrypter uses Key A

    $cipherA = app(LaravelPayloadCipher::class);
    $wire = new JsonObjectSerializer()->serialize(new DummyEvent('x', 'y'));
    $enveloped = $cipherA->encryptString($wire);

    // Rotate to Key B: attempt to decrypt with a different key should fail -> null
    config()->set('app.key', 'base64:' . base64_encode(random_bytes(32)));
    app()->forgetInstance('encrypter'); // pick up Key B

    $cipherB = app(LaravelPayloadCipher::class);
    expect($cipherB->tryDecryptString($enveloped))->toBeNull();
});