<?php

namespace Pillar\Event;

/**
 * The UpcasterRegistry stores and retrieves registered upcasters
 * that transform historical event payloads into newer representations.
 *
 * It acts as the central lookup point during event deserialization.
 */
class UpcasterRegistry
{
    /**
     * @var array<class-string, list<Upcaster>>
     */
    private array $upcasters = [];

    /**
     * Registers one or more upcasters for the given event class.
     * They will be applied in the order they were registered.
     *
     * @param class-string $eventClass
     * @param Upcaster $upcaster
     */
    public function register(string $eventClass, Upcaster $upcaster): void
    {
        $this->upcasters[$eventClass][] = $upcaster;
    }

    /**
     * Returns all upcasters for the given event class.
     *
     * @param class-string $eventClass
     * @return list<Upcaster>
     */
    public function get(string $eventClass): array
    {
        return $this->upcasters[$eventClass] ?? [];
    }

    /**
     * Determines if any upcasters exist for the given event class.
     */
    public function has(string $eventClass): bool
    {
        return !empty($this->upcasters[$eventClass]);
    }

    /**
     * Applies all registered upcasters in sequence to the given payload.
     *
     * @param class-string $eventClass
     * @param int $fromVersion The original event version
     * @param array $payload The original event data
     * @return array The transformed (upcasted) data
     */
    public function upcast(string $eventClass, int $fromVersion, array $payload): UpcastResult
    {
        $current = $fromVersion;
        $applied = [];

        while ($uc = $this->find($eventClass, $current)) {
            $payload = $uc->upcast($payload);
            $applied[] = $uc::class;
            $current++;
        }

        return new UpcastResult($payload, $fromVersion, $current, $applied);
    }

    private function find(string $eventClass, int $version): ?Upcaster
    {
        foreach ($this->get($eventClass) as $uc) {
            if ($eventClass === $uc::eventClass() && $uc::fromVersion() === $version) {
                return $uc;
            }
        }
        return null;
    }
}