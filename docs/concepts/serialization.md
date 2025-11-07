# 🪶 Serialization

Pillar uses an **ObjectSerializer** to convert events/commands to and from storage.

## Default: JSON

`JsonObjectSerializer` serializes objects to JSON and reconstructs them using constructor
parameter metadata that is cached per class (fast, reflection cost amortized).

## Swap the serializer

Implement `Pillar\Serialization\ObjectSerializer` and register it in `config/pillar.php`:

```php
'serializer' => [
    'class' => \App\Infrastructure\MySerializer::class,
    'options' => [/* … */],
],
```

Common reasons to swap:
- Binary/compact formats
- Encrypted payloads
- Strict schema enforcement

## Tips

- Keep events as **simple value objects** (scalars/arrays) for long‑term compatibility.
- Version payloads with `VersionedEvent` and use **Upcasters** to evolve shapes safely.
