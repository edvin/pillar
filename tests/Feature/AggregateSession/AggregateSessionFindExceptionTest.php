<?php

use Illuminate\Contracts\Container\BindingResolutionException;
use Pillar\Aggregate\AggregateSession;
use Pillar\Facade\Pillar;
use Pillar\Repository\EventStoreRepository;
use Pillar\Repository\RepositoryResolver;
use Tests\Fixtures\Document\Document;
use Tests\Fixtures\Document\DocumentId;

it('wraps BindingResolutionException from RepositoryResolver in a RuntimeException', function () {
    // Point the repository mapping for our Document aggregate to a bogus class
    config()->set('pillar.repositories', [
        'default' => EventStoreRepository::class, // keep default sane
        Document::class => 'Tests\\Doubles\\MissingRepositoryClass', // does not exist
    ]);

    // Rebuild singletons so resolver sees the new config
    app()->forgetInstance(RepositoryResolver::class);
    app()->forgetInstance(AggregateSession::class);

    $session = Pillar::session();
    $id = DocumentId::new();

    $caught = null;
    try {
        $session->find($id);
    } catch (Throwable $e) {
        $caught = $e;
    }

    expect($caught)->toBeInstanceOf(RuntimeException::class)
        ->and($caught->getPrevious())->toBeInstanceOf(BindingResolutionException::class)
        ->and($caught->getMessage())->toContain('MissingRepositoryClass');
});