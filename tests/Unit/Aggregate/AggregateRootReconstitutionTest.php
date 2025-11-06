<?php

use Tests\Fixtures\Document\Document;
use Tests\Fixtures\Document\DocumentId;
use Tests\Fixtures\Document\DocumentRenamed;

it('skips recording while reconstituting but still applies state, and resumes recording after', function () {
    $id = DocumentId::new();
    $doc = Document::create($id, 'v0');

    // Drop the initial DocumentCreated event so we isolate the test
    $doc->releaseEvents();

    // While reconstituting: record() is invoked via rename(), but it must NOT buffer events.
    $doc->markAsReconstituting();
    $doc->rename('v1');
    $doc->rename('v2');
    $doc->markAsNotReconstituting();

    // No recorded events captured during reconstitution phase but the state was applied
    expect($doc->recordedEvents())->toBeArray()->toBeEmpty()
        ->and($doc->title())->toBe('v2');

    // After reconstitution, recording should resume
    $doc->rename('v3');

    $recorded = $doc->recordedEvents();
    expect($recorded)->toHaveCount(1)
        ->and($recorded[0])->toBeInstanceOf(DocumentRenamed::class)
        ->and($doc->title())->toBe('v3');
});