<?php

namespace Pillar\Snapshot;

namespace Pillar\Snapshot;

interface Snapshottable
{
    /** Arbitrary, serializer-agnostic payload that represents this aggregate’s state. */
    public function toSnapshot(): array;

    /** Rebuild the aggregate from the snapshot payload. */
    public static function fromSnapshot(array $data): static;
}