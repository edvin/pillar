<?php

namespace Pillar\Aggregate;

use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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

    public function __construct(
        private readonly RepositoryResolver $repositoryResolver,
        private readonly Dispatcher         $dispatcher
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
        $aggregate = $repo->find($id);

        if ($aggregate) {
            $this->track($aggregate);
        }

        return $aggregate;
    }

    /**
     * Adds a new aggregate instance to the session for tracking.
     */
    public function add(AggregateRoot $aggregate): void
    {
        $this->track($aggregate);
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
                $repo->save($aggregate);
            }
        });

        $this->dispatchEvents();
        $this->tracked = [];
    }

    private function track(AggregateRoot $aggregate): void
    {
        $this->tracked[spl_object_id($aggregate)] = $aggregate;
    }

    private function dispatchEvents(): void
    {
        foreach ($this->tracked as $aggregate) {
            foreach ($aggregate->releaseEvents() as $event) {
                Log::debug('Dispatching domain event', ['event' => get_class($event)]);
                $this->dispatcher->dispatch($event);
            }
        }
    }
}