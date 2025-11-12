<?php

use Pillar\Event\DefaultPublicationPolicy;
use Pillar\Event\EventContext;
use Pillar\Event\PublicationPolicy;
use Tests\Fixtures\Event\LocalEvent;
use Tests\Fixtures\Event\PublishMe;

it('returns false during replay for any event', function () {
    EventContext::initialize(replaying: true);

    $policy = new DefaultPublicationPolicy();

    expect($policy->shouldPublish(new PublishMe()))->toBeFalse()
        ->and($policy->shouldPublish(new LocalEvent()))->toBeFalse();
});

it('publishes events implementing ShouldPublish when not replaying', function () {
    EventContext::initialize();

    $policy = new DefaultPublicationPolicy();

    expect($policy->shouldPublish(new PublishMe()))->toBeTrue();
});

it('does not publish local events by default', function () {
    EventContext::initialize();

    $policy = new DefaultPublicationPolicy();

    expect($policy->shouldPublish(new LocalEvent()))->toBeFalse();
});

it('can be resolved from the container based on config binding', function () {
    // Ensure config points at the default class (or your custom one)
    config()->set('pillar.publication_policy.class', DefaultPublicationPolicy::class);

    /** @var PublicationPolicy $resolved */
    $resolved = app(PublicationPolicy::class);

    expect($resolved)->toBeInstanceOf(DefaultPublicationPolicy::class);
});