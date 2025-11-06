<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Pillar\Aggregate\GenericAggregateId;

it('validates UUID (string) in constructor', function (string $value) {
    expect(fn() => new GenericAggregateId($value))
        ->toThrow(InvalidArgumentException::class, 'Invalid UUID');
})->with([
    [''],                // empty string
    ['not-a-uuid'],      // bad format
]);

it('constructor type-hints string: non-strings throw TypeError', function ($value) {
    expect(fn() => new GenericAggregateId($value))
        ->toThrow(TypeError::class);
})->with([
    [null],
    [123],
    [12.3],
    [new stdClass()],
    [[]],
]);

it('new() creates a valid id', function () {
    $id = GenericAggregateId::new();
    expect(Str::isUuid((string)$id))->toBeTrue();
});

it('from() returns same instance', function () {
    $id = GenericAggregateId::new();
    expect(GenericAggregateId::from($id))->toBe($id);
});

it('from() extracts id from eloquent-like getAttribute()', function () {
    $uuid = (string)Str::uuid();

    $model = new class($uuid) {
        public function __construct(private string $id)
        {
        }

        public function getAttribute(string $key)
        {
            return $key === 'id' ? $this->id : null;
        }
    };

    $id = GenericAggregateId::from($model);
    expect($id->value())->toBe($uuid);
});

it('from() extracts id from stdClass->id', function () {
    $uuid = (string)Str::uuid();
    $obj = (object)['id' => $uuid];

    $id = GenericAggregateId::from($obj);
    expect($id->value())->toBe($uuid);
});

it('from() extracts id from ["id" => ...] array', function () {
    $uuid = (string)Str::uuid();
    $id = GenericAggregateId::from(['id' => $uuid]);

    expect($id->value())->toBe($uuid);
});

it('from() throws on invalid inputs', function ($value) {
    expect(fn() => GenericAggregateId::from($value))
        ->toThrow(InvalidArgumentException::class, 'Invalid UUID');
})->with([
    // non-string (these are handled by from(), then fail the final validity check)
    [null],
    [123],
    [12.3],
    [new stdClass()],          // object without id
    [[]],                      // array without id
    // invalid string
    [''],
    ['not-a-uuid'],
    // found id but invalid value
    [['id' => 'not-a-uuid']],            // array with bad id
    [(object)['id' => 'not-a-uuid']],   // object with bad id
    [['not_id' => 'anything']],          // array missing 'id'
]);