<?php

declare(strict_types=1);

namespace Pillar\Security;

use Illuminate\Container\Attributes\Config;

/**
 * Simple policy: default (encrypt all or none), with per-event overrides.
 * If an event class is present in overrides, that boolean wins.
 * Otherwise, default determines behavior.
 */
final class EventEncryptionPolicy
{
    public function __construct(
        #[Config('pillar.serializer.encryption.default')]
        private readonly ?bool $default = null,
        #[Config('pillar.serializer.encryption.event_overrides')]
        readonly private array $overrides = [],
    )
    {
    }

    /**
     * Decide if we should encrypt payloads for a given event class.
     *
     * @param class-string $eventClass
     */
    public function shouldEncrypt(string $eventClass): bool
    {
        // Per-event override wins; otherwise fall back to default (coerced to false when null)
        if (array_key_exists($eventClass, $this->overrides)) {
            return (bool)$this->overrides[$eventClass];
        }

        return $this->default ?? false;
    }
}
