<?php

declare(strict_types=1);

namespace Pillar\Security;

use Illuminate\Container\Attributes\Config;
use Illuminate\Contracts\Encryption\Encrypter;
use Throwable;
use function count;
use function explode;
use function strlen;
use function strncmp;
use function substr;

final class LaravelPayloadCipher implements PayloadCipher
{
    private const PREFIX = 'PILLAR_ENC:';

    public function __construct(
        private readonly Encrypter $encrypter,
        #[Config('pillar.serializer.encryption.cipher.options.kid')]
        private readonly string    $kid = 'v1',
        #[Config('pillar.serializer.encryption.cipher.options.alg')]
        private readonly string    $alg = 'laravel-crypt',
    )
    {
    }

    /**
     * Encrypt a plaintext wire string and return an opaque encrypted wire string.
     * Format: "PILLAR_ENC:{alg}:{kid}:{ciphertext}"
     */
    public function encryptString(string $wire): string
    {
        $ct = $this->encrypter->encryptString($wire);
        return self::PREFIX . $this->alg . ':' . $this->kid . ':' . $ct;
    }

    /**
     * Attempt to unwrap/decrypt an encrypted wire string. Returns plaintext wire on success,
     * or null if the payload was not produced by this cipher.
     */
    public function tryDecryptString(string $payload): ?string
    {
        // prefix probe
        $prefixLen = strlen(self::PREFIX);
        if (strncmp($payload, self::PREFIX, $prefixLen) !== 0) {
            return null;
        }

        $rest = substr($payload, $prefixLen);
        // Expect exactly 3 segments: alg, kid, ciphertext
        $parts = explode(':', $rest, 3);
        if (count($parts) !== 3) {
            return null;
        }

        [$alg, $kid, $ct] = $parts;

        // We currently ignore $alg/$kid mismatch on read to allow key rotation
        // with multiple readers
        try {
            return $this->encrypter->decryptString($ct);
        } catch (Throwable) {
            return null; // not our envelope or tampered
        }
    }
}
