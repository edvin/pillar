<?php

use Pillar\Event\UpcasterRegistry;
use Tests\Fixtures\Document\DocumentRenamed;
use Tests\Fixtures\Upcasters\TitlePrefixUpcaster;

it('registers, detects, and applies an upcaster', function () {
    $reg = new UpcasterRegistry();

    // pretend we’re upcasting DocumentRenamed v1 payloads
    $eventClass = DocumentRenamed::class;

    $reg->register($eventClass, new TitlePrefixUpcaster());

    expect($reg->has($eventClass))->toBeTrue();

    $input = ['title' => 'v1'];
    $result   = $reg->upcast($eventClass, 1, $input);

    expect($result->payload)->toMatchArray([
        'title' => 'v1',
        'title_prefixed' => 'prefix:v1',
    ]);
});