<?php

namespace Pillar\Snapshot;

use Illuminate\Container\Attributes\Config;
use Illuminate\Contracts\Container\Container;
use Pillar\Aggregate\AggregateRoot;

final class DelegatingSnapshotPolicy implements SnapshotPolicy
{
    /** @var array<class-string, SnapshotPolicy> */
    private array $overrides = [];

    private SnapshotPolicy $default;

    public function __construct(
        #[Config('pillar.snapshot')]
        array     $cfg,
        Container $container
    )
    {
        // Default policy
        $def = $cfg['policy'] ?? ['class' => CadenceSnapshotPolicy::class, 'options' => [
            'threshold' => 0,
            'offset' => 0
        ]];

        $params = $def['options'] ?? [];

        $this->default = $container->make(
            $def['class'],
            $params
        );

        // Per-aggregate overrides
        foreach (($cfg['overrides'] ?? []) as $aggregateClass => $def) {
            $params = $def['options'] ?? [];

            $this->overrides[$aggregateClass] = $container->make(
                $def['class'],
                $params
            );
        }
    }

    public function shouldSnapshot(AggregateRoot $aggregate, int $newSeq, int $prevSeq, int $delta): bool
    {
        $aggregateClass = $aggregate::class;
        $policy = $this->overrides[$aggregateClass] ?? $this->default;
        return $policy->shouldSnapshot($aggregate, $newSeq, $prevSeq, $delta);
    }
}