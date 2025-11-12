<?php

namespace Pillar\Outbox\Lease;

interface PartitionLeaseStore
{
    public function tryLease(array $partitions, string $owner, int $ttlSeconds): bool;

    public function renew(array $partitions, string $owner, int $ttlSeconds): bool;

    public function release(array $partitions, string $owner): void;
}