<?php


use Illuminate\Log\LogManager;
use Pillar\Logging\PillarLogger;
use Psr\Log\LoggerInterface;
use Psr\Log\LoggerTrait;

class ArrayLogger implements LoggerInterface
{
    use LoggerTrait;

    /** @var array<int, array{level:string,message:string,context:array}> */
    public array $records = [];

    public function log($level, Stringable|string $message, array $context = []): void
    {
        $this->records[] = [
            'level' => $level,
            'message' => (string)$message,
            'context' => $context,
        ];
    }
}

it('exposes the enabled flag via isEnabled()', function () {
    /** @var LogManager $logManager */
    $logManager = app(LogManager::class);

    $logger = new PillarLogger($logManager, false, null, 'info');

    expect($logger->isEnabled())->toBeFalse();
});

it('does not delegate when disabled', function () {
    /** @var LogManager $logManager */
    $logManager = app(LogManager::class);

    $inner = new ArrayLogger();

    // Register a custom channel that returns our fake logger
    $logManager->extend('pillar-test', function ($app, array $config) use ($inner) {
        return $inner;
    });

    // Use the named channel + disabled flag
    $logger = new PillarLogger(
        logManager: $logManager,
        enabled: false,
        channel: 'pillar-test',
        level: 'info'
    );

    // This calls PillarLogger::log() via LoggerTrait::info()
    $logger->info('hello', ['foo' => 'bar']);

    // Because enabled=false, nothing should reach the inner logger
    expect($inner->records)->toBe([]);
});

it('delegates to the underlying channel when enabled', function () {
    /** @var LogManager $logManager */
    $logManager = app(LogManager::class);

    $inner = new ArrayLogger();

    config()->set('logging.channels.pillar-test', [
        'driver' => 'pillar-test',
    ]);

    $logManager->extend('pillar-test', function ($app, array $config) use ($inner) {
        return $inner;
    });

    $logger = new PillarLogger(
        logManager: $logManager,
        enabled: true,
        channel: 'pillar-test',
        level: 'info'
    );

    $logger->info('hello', ['foo' => 'bar']);

    expect($inner->records)->toHaveCount(1)
        ->and($inner->records[0]['level'])->toBe('info')
        ->and($inner->records[0]['message'])->toBe('hello')
        ->and($inner->records[0]['context'])->toBe(['foo' => 'bar']);
});