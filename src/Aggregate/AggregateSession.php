<?php

namespace Pillar\Aggregate;

use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Pillar\Event\Publication\PublicationPolicy;
use Pillar\Repository\RepositoryResolver;
use RuntimeException;
use Throwable;

/**
 * A high-level Unit of Work that loads, tracks, and commits aggregates.
 *
 * A single session may track multiple aggregate root types.
 */
final class AggregateSession
{
    /** @var array<int, AggregateRoot> */
    private array $tracked = [];
    /** @var array<int,int> object-id keyed expected versions */
    private array $expectedVersions = [];

    public function __construct(
        private readonly RepositoryResolver $repositoryResolver
    )
    {
    }

    /**
     * Loads an aggregate by ID and begins tracking it in the session.
     *
     * @param AggregateRootId $id
     * @return AggregateRoot|null
     */
    public function find(AggregateRootId $id): ?AggregateRoot
    {
        try {
            $repo = $this->repositoryResolver->forId($id);
        } catch (BindingResolutionException $e) {
            throw new RuntimeException($e->getMessage(), $e->getCode(), $e);
        }

        $found = $repo->find($id);

        if ($found) {
            $this->track($found->aggregate);
            // Remember the persisted version seen at load time for optimistic concurrency
            $this->expectedVersions[spl_object_id($found->aggregate)] = $found->version;
            return $found->aggregate;
        }

        return null;
    }

    /**
     * Attach a new aggregate instance to the session for tracking (fluent).
     *
     * Returns $this for chaining, e.g. `$session->attach($a)->attach($b)->commit();`.
     */
    public function attach(AggregateRoot $aggregate): self
    {
        $this->track($aggregate);
        // New aggregates start at version 0 (no persisted events)
        $this->expectedVersions[spl_object_id($aggregate)] = 0;
        return $this;
    }

    /**
     * Commits all tracked aggregates atomically, saving and dispatching events.
     * @throws Throwable
     */
    public function commit(): void
    {
        DB::transaction(function () {
            foreach ($this->tracked as $aggregate) {
                $repo = $this->repositoryResolver->forAggregateClass($aggregate::class);
                $oid = spl_object_id($aggregate);
                $expected = $this->expectedVersions[$oid] ?? null;
                $repo->save($aggregate, $expected);
            }
        });

        $this->tracked = [];
        $this->expectedVersions = [];
    }

    private function track(AggregateRoot $aggregate): void
    {
        $this->tracked[spl_object_id($aggregate)] = $aggregate;
    }

}