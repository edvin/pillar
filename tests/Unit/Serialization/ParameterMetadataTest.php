<?php

use Pillar\Serialization\ParameterMetadata;

it('instantiates object via constructor when non-builtin typed param without from()', function () {
    $param = new ParameterMetadata(
        name: 'created_at',
        hasType: true,
        isBuiltin: false,
        typeName: DateTimeImmutable::class,
        hasDefault: false,
        default: null,
        hasFromMethod: false,
    );

    $result = $param->resolveValue(['created_at' => '2025-01-01 12:34:56']);

    expect($result)->toBeInstanceOf(DateTimeImmutable::class)
        ->and($result->format('Y-m-d H:i:s'))->toBe('2025-01-01 12:34:56');
});

it('uses default and still constructs object when value missing', function () {
    $param = new ParameterMetadata(
        name: 'created_at',
        hasType: true,
        isBuiltin: false,
        typeName: DateTimeImmutable::class,
        hasDefault: true,
        default: '2025-02-03 04:05:06',
        hasFromMethod: false,
    );

    $result = $param->resolveValue([]); // no key provided

    expect($result)->toBeInstanceOf(DateTimeImmutable::class)
        ->and($result->format('Y-m-d H:i:s'))->toBe('2025-02-03 04:05:06');
});

it('returns null when missing and no default', function () {
    $param = new ParameterMetadata(
        name: 'created_at',
        hasType: true,
        isBuiltin: false,
        typeName: DateTimeImmutable::class,
        hasDefault: false,
        default: null,
        hasFromMethod: false,
    );

    $result = $param->resolveValue([]);

    expect($result)->toBeNull();
});