<?php

namespace Pillar\Logging;

use Illuminate\Log\LogManager;
use Illuminate\Container\Attributes\Config;
use Psr\Log\LoggerInterface;
use Psr\Log\LoggerTrait;
use Stringable;

/**
 * PillarLogger
 *
 * Small wrapper around Laravel's logger that:
 * - Picks the correct channel based on pillar.logging config
 * - Can be globally enabled/disabled
 * - Implements PSR-3 LoggerInterface so it can be type-hinted anywhere
 *
 * We deliberately do NOT implement our own log-level filtering here; we rely on
 * Laravel's logging configuration (LOG_LEVEL etc.) to handle that.
 */
final class PillarLogger implements LoggerInterface
{
    use LoggerTrait;

    private LoggerInterface $logger;

    public function __construct(
        LogManager               $logManager,
        #[Config('pillar.logging.enabled', 'true')]
        private readonly bool    $enabled,
        #[Config('pillar.logging.channel')]
        private readonly ?string $channel,
        #[Config('pillar.logging.level', 'info')]
        private readonly string  $level,
    )
    {
        // If a specific Pillar channel is configured, use that.
        // Otherwise, fall back to Laravel's default logging channel.
        $this->logger = $this->channel !== null
            ? $logManager->channel($this->channel)
            : $logManager->channel();
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * PSR-3 core method. All convenience methods (info/debug/error/etc.)
     * provided by LoggerTrait delegate here.
     *
     * @param string $level
     * @param Stringable|string $message
     * @param array<string,mixed> $context
     */
    public function log($level, Stringable|string $message, array $context = []): void
    {
        if (!$this->enabled) {
            return;
        }

        $this->logger->log($level, $message, $context);
    }
}