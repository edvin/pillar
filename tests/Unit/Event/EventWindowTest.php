<?php

use Pillar\Event\EventWindow;

it('builds afterAggSeq', function () {
    $w = EventWindow::afterStreamSeq(10);
    expect($w->afterStreamSequence)->toBe(10)
        ->and($w->toStreamSequence)->toBeNull()
        ->and($w->toGlobalSequence)->toBeNull()
        ->and($w->toDateUtc)->toBeNull();
});

it('builds toAggSeq', function () {
    $w = EventWindow::toStreamSeq(42);
    expect($w->toStreamSequence)->toBe(42)
        ->and($w->afterStreamSequence)->toBeNull();
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
    $w = EventWindow::betweenStreamSeq(5, 15);
    expect($w->afterStreamSequence)->toBe(5)
        ->and($w->toStreamSequence)->toBe(15);
});

it('builds unbounded', function () {
    $w = EventWindow::unbounded();
    expect($w->afterStreamSequence)->toBeNull()
        ->and($w->afterGlobalSequence)->toBeNull()
        ->and($w->afterDateUtc)->toBeNull()
        ->and($w->toStreamSequence)->toBeNull()
        ->and($w->toGlobalSequence)->toBeNull()
        ->and($w->toDateUtc)->toBeNull();
});