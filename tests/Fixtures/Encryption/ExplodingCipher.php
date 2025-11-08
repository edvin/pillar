<?php

declare(strict_types=1);

namespace Tests\Fixtures\Encryption;

use Pillar\Security\PayloadCipher;
use RuntimeException;

/**
 * A cipher that raises on construction — useful to assert that the serializer
 * does not instantiate it when encryption is disabled.
 */
final class ExplodingCipher implements PayloadCipher
{
    public function __construct()
    {
        throw new RuntimeException('ExplodingCipher should never be constructed when encryption is disabled');
    }

    public function encryptString(string $wire): string { return $wire; }
    public function tryDecryptString(string $payload): ?string { return $payload; }
}
