<?php

use Pillar\Facade\Pillar;
use Tests\Fixtures\Document\DocumentId;

it('returns null when the aggregate does not exist', function () {
    // No events/snapshots exist for this brand-new ID
    $id = DocumentId::new();

    $session = Pillar::session();
    $found = $session->find($id);

    expect($found)->toBeNull();
});