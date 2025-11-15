<?php
/** @noinspection PhpClassNamingConventionInspection */

/** @noinspection PhpIllegalPsrClassPathInspection */

namespace Tests\Feature\Context;

use Pillar\Bus\CommandBusInterface;
use Pillar\Bus\QueryBusInterface;
use Pillar\Context\ContextLoader;
use Pillar\Context\ContextRegistry;
use Pillar\Context\EventMapBuilder;
use Pillar\Event\EventAliasRegistry;
use Pillar\Event\Upcaster;
use Pillar\Event\UpcasterRegistry;

// ─────────────────────────────────────────────────────────────────────────────
// Fakes for the bus coverage
// ─────────────────────────────────────────────────────────────────────────────

final class _PingCommand
{
    public function __construct(public string $x = '')
    {
    }
}

final class _PingHandler
{
    public function __invoke(_PingCommand $c): void
    {
    }
}

final class _SumQuery
{
    public function __construct(public int $a, public int $b)
    {
    }
}

final class _SumHandler
{
    public function __invoke(_SumQuery $q): int
    {
        return $q->a + $q->b;
    }
}

final class _BusRegistry implements ContextRegistry
{
    public function name(): string
    {
        return 'BusRegistry';
    }

    public function commands(): array
    {
        return [
            _PingCommand::class => _PingHandler::class,
        ];
    }

    public function queries(): array
    {
        return [
            _SumQuery::class => _SumHandler::class,
        ];
    }

    public function events(): EventMapBuilder
    {
        return EventMapBuilder::create();
    }

    public function aggregateRootIds(): array
    {
        return [];
    }
}

it('registers command and query maps through ContextLoader', function () {
    // Point the loader at only this registry
    config()->set('pillar.context_registries', [_BusRegistry::class]);

    // Recreate the loader so it re-reads config and calls into the buses
    app()->forgetInstance(ContextLoader::class);
    app(ContextLoader::class)->load();

    // Now verify the mappings by actually using the buses.
    /** @var CommandBusInterface $cmd */
    $cmd = app(CommandBusInterface::class);
    /** @var QueryBusInterface $qry */
    $qry = app(QueryBusInterface::class);

    // Should not throw:
    $cmd->dispatch(new _PingCommand('ok'));

    // Should return the sum via the registered handler:
    expect($qry->ask(new _SumQuery(2, 3)))->toBe(5);
});

// ─────────────────────────────────────────────────────────────────────────────
// Alias + Upcaster coverage
// ─────────────────────────────────────────────────────────────────────────────

final class _DummyEvent
{
    public function __construct(public int $n = 1)
    {
    }
}

final class _DummyUpcaster implements Upcaster
{
    public function supports(string $eventType, int $version): bool
    {
        return $eventType === _DummyEvent::class && $version < 2;
    }

    public function upcast(array $payload): array
    {
        return $payload + ['__upcasted' => true];
    }

    public static function eventClass(): string
    {
        return _DummyEvent::class;
    }

    public static function fromVersion(): int
    {
        return 1;
    }
}

final class _EventsRegistry implements ContextRegistry
{
    public function name(): string
    {
        return 'EventsRegistry';
    }

    public function commands(): array
    {
        return [];
    }

    public function queries(): array
    {
        return [];
    }

    public function events(): EventMapBuilder
    {
        return EventMapBuilder::create()
            ->event(_DummyEvent::class)
            ->alias('dummy.event')
            ->upcasters([_DummyUpcaster::class]);
    }

    public function aggregateRootIds(): array
    {
        return [];
    }
}

it('registers event aliases and upcasters through ContextLoader', function () {
    // Fresh singletons to avoid cross-test state:
    app()->forgetInstance(EventAliasRegistry::class);
    app()->forgetInstance(UpcasterRegistry::class);

    app()->instance(EventAliasRegistry::class, new EventAliasRegistry());
    app()->instance(UpcasterRegistry::class, new UpcasterRegistry());

    config()->set('pillar.context_registries', [_EventsRegistry::class]);

    app()->forgetInstance(ContextLoader::class);
    app(ContextLoader::class)->load();

    $aliases = app(EventAliasRegistry::class);

    expect($aliases->resolveClass('dummy.event'))
        ->toBe(_DummyEvent::class)
        ->and($aliases->resolveAlias(_DummyEvent::class))
        ->toBe('dummy.event');

    // Upcaster registered & applied (version 1 → 2)
    $upcasters = app(UpcasterRegistry::class);
    $result = $upcasters->upcast(_DummyEvent::class, 1, ['k' => 'v']);

    expect($result->payload)->toBe(['k' => 'v', '__upcasted' => true])
        ->and($result->upcasters)->toBe([_DummyUpcaster::class]);
});