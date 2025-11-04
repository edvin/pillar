<?php

namespace Pillar\Event;

/**
 * Manages mappings between event class names and their shorter aliases for serialization and deserialization.
 *
 * This registry allows registering event aliases to their fully qualified class names,
 * resolving aliases to class names and vice versa, facilitating easier event identification.
 */
final class EventAliasRegistry
{
    private array $map = [];
    private array $reverseMap = [];

    /**
     * Registers an alias for a given event class name.
     *
     * @param string $alias The short alias representing the event class.
     * @param string $class The fully qualified class name of the event.
     */
    public function register(string $alias, string $class): void
    {
        $this->map[$alias] = $class;
        $this->reverseMap[$class] = $alias;
    }

    /**
     * Resolves an identifier to its corresponding event class name.
     *
     * If the input is a registered alias, returns the associated class name.
     * Otherwise, assumes the input is already a fully qualified class name and returns it as is.
     *
     * @param string $aliasOrClass The alias or fully qualified class name to resolve.
     * @return string The resolved fully qualified class name.
     */
    public function resolveClass(string $aliasOrClass): string
    {
        return $this->map[$aliasOrClass] ?? $aliasOrClass;
    }

    /**
     * Returns all registered aliases mapped to their event class names.
     *
     * @return array<string, string> An associative array of alias => class name mappings.
     */
    public function all(): array
    {
        return $this->map;
    }

    /**
     * Resolves an event instance or class name to its registered alias.
     *
     * If the event's class is not registered, returns the class name itself.
     *
     * @param object|string $event An event object or fully qualified class name.
     * @return string The alias associated with the event class or the class name if no alias is registered.
     */
    public function resolveAlias(object|string $event): string
    {
        $class = is_object($event) ? get_class($event) : $event;
        return $this->reverseMap[$class] ?? $class;
    }

}