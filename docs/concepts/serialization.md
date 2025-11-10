# 🪶 Serialization

Pillar uses an **ObjectSerializer** to convert events/commands to and from storage.

## Default: JSON

`JsonObjectSerializer` serializes objects to JSON and reconstructs them using constructor
parameter metadata that is cached per class (fast, reflection cost amortized).

## Optional: MessagePack

Pillar ships with `MessagePackObjectSerializer` as a compact binary serialization alternative. It implements the same
`ObjectSerializer` contract (`serialize` / `deserialize` and `toArray` / `fromArray`), so your upcasters and payload
encryption work unchanged. Expect higher performance and smaller payloads.

> **Requires** the PECL extension **ext-msgpack** to be installed and loaded.

**Enable it:**

```php
'serializer' => [
    'class' => \Pillar\Serialization\MessagePackObjectSerializer::class,
],
```

**Install the extension (examples):**

- PECL: `pecl install msgpack`
- php.ini: `extension=msgpack`

## Swap the serializer

Implement `Pillar\Serialization\ObjectSerializer` and register it in `config/pillar.php`:

```php
'serializer' => [
    'class' => \App\Infrastructure\MySerializer::class,
    'options' => [/* … */],
],
```

Or use the built‑in MessagePack serializer:

```php
'serializer' => [
    'class' => \Pillar\Serialization\MessagePackObjectSerializer::class,
],
```

Common reasons to swap:

- Binary/compact formats
- Strict schema enforcement
- Custom codecs for interop

## 🔒 Payload encryption {#payload-encryption}

Pillar can **wrap the base serializer** with a pluggable cipher. The base serializer still decides the wire format (JSON
by default). Encryption happens **after** serialization and **before** storage, producing an opaque string; on read, the
wrapper unwraps and feeds plaintext back to the base serializer. This works equally with the MessagePack serializer.

### Configure

```php
'serializer' => [
    // Base serializer (used even when encryption is enabled)
    'class' => \Pillar\Serialization\JsonObjectSerializer::class,

    'encryption' => [
        'enabled' => env('PILLAR_PAYLOAD_ENCRYPTION', false),
        'default' => false, // encrypt none by default; override per event below
        'event_overrides' => [
            // \Context\Billing\Domain\Event\PaymentFailed::class => true,
        ],

        // Pluggable cipher
        'cipher' => [
            'class' => \Pillar\Security\LaravelPayloadCipher::class,
            'options' => [
                'kid' => env('PILLAR_PAYLOAD_KID', 'v1'),
                'alg' => 'laravel-crypt',
            ],
        ],
    ],
],
```

### How it works

- **Write**: `serialize(object)` → base serializer produces a wire string → if policy says encrypt for the event class,
  the cipher returns an **encrypted wire string**.
- **Read (objects)**: `deserialize($class, $payload)` only attempts to unwrap when **encryption is enabled** *and*
  policy says the **class** should be encrypted.
- **Read (arrays)**: `toArray($payload)` unwraps **only when encryption is enabled**, then normalizes via the base
  serializer. (This keeps the hot path cheap when disabled.)
- **Metadata** (ids, alias/type, version, timestamps) remains **plaintext**; only the payload is encrypted.
- You can mix encrypted and plaintext events over time; reads are seamless when enabled for those classes.

### Swap the cipher

Implement `Pillar\Security\PayloadCipher`:

```php
interface PayloadCipher {
    public function encryptString(string $wire): string;
    public function tryDecryptString(string $payload): ?string;
}
```

Then set `serializer.encryption.cipher.class` to your implementation. The cipher can choose any envelope/wire format;
the serializer is agnostic.

## Tips

- Keep events as **simple value objects** (scalars/arrays) for long‑term compatibility.
- Version payloads with `VersionedEvent` and use **Upcasters** to evolve shapes safely.
