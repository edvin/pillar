<?php

declare(strict_types=1);

namespace Pillar\Security;

/**
 * Driver contract for encrypting/decrypting the serializer wire string.
 * Implementations may choose any envelope/wire format; the serializer is agnostic.
 */
interface PayloadCipher
{
    /** Encrypt a plaintext wire string and return an opaque encrypted wire string. */
    public function encryptString(string $wire): string;

    /** Return plaintext wire string if encrypted; null if payload wasn’t encrypted. */
    public function tryDecryptString(string $payload): ?string;
}