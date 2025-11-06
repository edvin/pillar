<?php

use Illuminate\Support\Str;
use Pillar\Aggregate\AggregateRootId;
use Pillar\Aggregate\GenericAggregateId;

it('equals() is true for same class and same UUID', function () {
    $uuid = Str::uuid()->toString();
    $a = new GenericAggregateId($uuid);
    $b = new GenericAggregateId($uuid);

    expect($a->equals($b))->toBeTrue();
});

it('equals() is false for same class but different UUID', function () {
    $a = new GenericAggregateId(Str::uuid()->toString());
    $b = new GenericAggregateId(Str::uuid()->toString());

    expect($a->equals($b))->toBeFalse();
});

it('equals() is false for different ID classes even with same UUID', function () {
    $uuid = Str::uuid()->toString();
    $a = new GenericAggregateId($uuid);

    // A different concrete ID type for the same UUID
    final readonly class OtherAggregateId extends AggregateRootId
    {
        public static function aggregateClass()
        {
            return \stdClass::class;
        }
    }

    $b = new OtherAggregateId($uuid);

    expect($a->equals($b))->toBeFalse();
});