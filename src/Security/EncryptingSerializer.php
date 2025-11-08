<?php

declare(strict_types=1);

namespace Pillar\Security;

use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Contracts\Container\Container;
use Illuminate\Container\Attributes\Config;
use Pillar\Serialization\ObjectSerializer;

final class EncryptingSerializer implements ObjectSerializer
{
    /**
     * We resolve the configured base serializer at runtime and keep
     * the encryption policy/cipher decisions internal to this class.
     *
     */
    private ObjectSerializer $inner;
    private ?PayloadCipher $cipher = null;
    private Container $app;
    private string $cipherClass;
    private EventEncryptionPolicy $policy;
    private bool $enabled;

    /**
     * @throws BindingResolutionException
     */
    public function __construct(
        Container             $app,
        EventEncryptionPolicy $policy,
        #[Config('pillar.serializer.class')]
        string                $baseClass,
        #[Config('pillar.serializer.encryption.enabled')]
        bool                  $enabled = false,
        #[Config('pillar.serializer.encryption.cipher.class')]
        string                $cipherClass = LaravelPayloadCipher::class,
    )
    {
        $this->inner = $app->make($baseClass);
        $this->enabled = $enabled;
        $this->app = $app;
        $this->cipherClass = $cipherClass;
        $this->policy = $policy;
    }

    /**
     * Serialize the domain object to the inner wire format (string),
     * then optionally encrypt the payload (keeps metadata outside).
     */
    public function serialize(object $object): string
    {
        $wire = $this->inner->serialize($object);

        if ($this->enabled && $this->policy->shouldEncrypt($object::class)) {
            // Let the cipher produce the wrapped wire string (format-agnostic)
            return $this->cipher()->encryptString($wire);
        }

        return $wire;
    }

    /**
     * Deserialize by auto-detecting the encryption envelope, decrypting
     * back to the inner wire format, then delegating to the base serializer.
     */
    public function deserialize(string $class, string $payload): object
    {
        if ($this->enabled && $this->policy->shouldEncrypt($class)) {
            $unwrapped = $this->cipher()->tryDecryptString($payload);
            return $this->inner->deserialize($class, $unwrapped ?? $payload);
        }

        return $this->inner->deserialize($class, $payload);
    }

    /**
     * Produce a normalized array for upcasters. If the payload is encrypted,
     * decrypt first so upcasters always see plaintext structures.
     */
    public function toArray(string $payload): array
    {
        if ($this->enabled) {
            $unwrapped = $this->cipher()->tryDecryptString($payload);
            return $this->inner->toArray($unwrapped ?? $payload);
        }
        return $this->inner->toArray($payload);
    }

    /**
     * Re-encode a normalized array back to the inner serializer's wire format.
     *
     * Note: We do NOT re-encrypt here, because there is no event context to
     * decide policy. The encrypt-or-not decision is applied during serialize().
     */
    public function fromArray(array $data): string
    {
        return $this->inner->fromArray($data);
    }

    private function cipher(): PayloadCipher
    {
        return $this->cipher ??= $this->app->make($this->cipherClass);
    }
}
