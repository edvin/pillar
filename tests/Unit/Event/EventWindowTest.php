<?php

use Pillar\Event\EventWindow;

it('builds afterAggSeq', function () {
    $w = EventWindow::afterAggSeq(10);
    expect($w->afterAggregateSequence)->toBe(10)
        ->and($w->toAggregateSequence)->toBeNull()
        ->and($w->toGlobalSequence)->toBeNull()
        ->and($w->toDateUtc)->toBeNull();
});

it('builds toAggSeq', function () {
    $w = EventWindow::toAggSeq(42);
    expect($w->toAggregateSequence)->toBe(42)
        ->and($w->afterAggregateSequence)->toBeNull();
});

it('builds afterGlobalSeq', function () {
    $w = EventWindow::afterGlobalSeq(123);
    expect($w->afterGlobalSequence)->toBe(123);
});

it('builds toGlobalSeq', function () {
    $w = EventWindow::toGlobalSeq(999);
    expect($w->toGlobalSequence)->toBe(999);
});

it('builds afterDateUtc', function () {
    $d = new DateTimeImmutable('2025-01-01T00:00:00Z');
    $w = EventWindow::afterDateUtc($d);
    expect($w->afterDateUtc)->toBe($d);
});

it('builds toDateUtc', function () {
    $d = new DateTimeImmutable('2025-01-31T23:59:59Z');
    $w = EventWindow::toDateUtc($d);
    expect($w->toDateUtc)->toBe($d);
});

it('builds betweenAggSeq', function () {
    $w = EventWindow::betweenAggSeq(5, 15);
    expect($w->afterAggregateSequence)->toBe(5)
        ->and($w->toAggregateSequence)->toBe(15);
});

it('builds unbounded', function () {
    $w = EventWindow::unbounded();
    expect($w->afterAggregateSequence)->toBeNull()
        ->and($w->afterGlobalSequence)->toBeNull()
        ->and($w->afterDateUtc)->toBeNull()
        ->and($w->toAggregateSequence)->toBeNull()
        ->and($w->toGlobalSequence)->toBeNull()
        ->and($w->toDateUtc)->toBeNull();
});