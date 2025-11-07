<?php

use Pillar\Snapshot\CadenceSnapshotPolicy;
use Tests\Fixtures\Document\Document;
use Tests\Fixtures\Document\DocumentId;

it('returns false when delta <= 0', function () {
    $policy = new CadenceSnapshotPolicy(threshold: 3, offset: 0);
    $agg = Document::create(DocumentId::new(), 't0');

    expect($policy->shouldSnapshot($agg, newSeq: 10, prevSeq: 10, delta: 0))
        ->toBeFalse();
});

it('returns false when threshold <= 0', function () {
    $policy = new CadenceSnapshotPolicy(threshold: 0, offset: 0);
    $agg = Document::create(DocumentId::new(), 't0');

    expect($policy->shouldSnapshot($agg, newSeq: 1, prevSeq: 0, delta: 1))
        ->toBeFalse();
});

it('snapshots at multiples of threshold with no offset', function () {
    $policy = new CadenceSnapshotPolicy(threshold: 3, offset: 0);
    $agg = Document::create(DocumentId::new(), 't0');

    // (3 - 0) % 3 === 0 → snapshot
    expect($policy->shouldSnapshot($agg, newSeq: 3, prevSeq: 2, delta: 1))
        ->toBeTrue();

    // (4 - 0) % 3 !== 0 → no snapshot
    expect($policy->shouldSnapshot($agg, newSeq: 4, prevSeq: 3, delta: 1))
        ->toBeFalse();

    // (6 - 0) % 3 === 0, even with delta=2 → snapshot
    expect($policy->shouldSnapshot($agg, newSeq: 6, prevSeq: 4, delta: 2))
        ->toBeTrue();
});

it('respects offset', function () {
    $policy = new CadenceSnapshotPolicy(threshold: 3, offset: 1);
    $agg = Document::create(DocumentId::new(), 't0');

    // (4 - 1) % 3 === 0 → snapshot
    expect($policy->shouldSnapshot($agg, newSeq: 4, prevSeq: 3, delta: 1))
        ->toBeTrue();

    // (5 - 1) % 3 !== 0 → no snapshot
    expect($policy->shouldSnapshot($agg, newSeq: 5, prevSeq: 4, delta: 1))
        ->toBeFalse();

    // (7 - 1) % 3 === 0 → snapshot
    expect($policy->shouldSnapshot($agg, newSeq: 7, prevSeq: 6, delta: 1))
        ->toBeTrue();
});