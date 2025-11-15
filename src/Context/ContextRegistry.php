<?php

namespace Pillar\Context;

use Pillar\Aggregate\AggregateRootId;

interface ContextRegistry
{
    /**
     * Returns the map of commands to their handlers for this bounded context.
     *
     * Keys and values are fully qualified class names (FQCNs):
     * - key: Command class
     * - value: Handler class responsible for handling the command
     *
     * Example:
     * [
     *     CreateDocumentCommand::class => CreateDocumentHandler::class,
     * ]
     *
     * @return array<class-string, class-string> Command => Handler
     */
    public function commands(): array;

    /**
     * Returns the map of queries to their handlers for this bounded context.
     *
     * Keys and values are fully qualified class names (FQCNs):
     * - key: Query class
     * - value: Handler class responsible for handling the query
     *
     * Example:
     * [
     *     GetDocumentQuery::class => GetDocumentHandler::class,
     * ]
     *
     * @return array<class-string, class-string> Query => Handler
     */
    public function queries(): array;

    /**
     * Returns the map of domain events to their listeners, aliases, and upcasters.
     *
     * Events are defined using the EventMapBuilder fluent API:
     *
     * Example:
     *   return EventMapBuilder::create()
     *       ->event(DocumentCreated::class)
     *           ->alias('document.created')
     *           ->listeners(SendNotificationListener::class) // single listener syntax
     *           ->upcasters([ DocumentCreatedUpcaster::class ]) // multiple upcasters syntax
     *       ->event(DocumentRevised::class)
     *           ->listeners([ UpdateSearchIndexListener::class ]); // multiple listeners syntax
     *
     * Both listeners and upcasters can be single class or array of classes.
     *
     * @return EventMapBuilder
     */
    public function events(): EventMapBuilder;

    /**
     * Returns the list of AggregateRootId classes that belong to this bounded context.
     *
     * These are used by Pillar to:
     * - resolve stream prefixes and stream names for aggregates
     * - reconstitute aggregates for UI, projections, and diagnostics
     *
     * Example:
     * [
     *     \App\Document\Domain\DocumentId::class,
     *     \App\Inventory\Domain\ProductId::class,
     * ]
     *
     * @return array<class-string<AggregateRootId>>
     */
    public function aggregateRootIds(): array;

    /**
     * Returns a human-readable name or identifier for this bounded context.
     * This name is used primarily for logging and debugging.
     *
     * Example: "Document", "Inventory", "Billing"
     */
    public function name(): string;
}