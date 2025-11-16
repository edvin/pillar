<?php

namespace Pillar\Testing;

use PHPUnit\Framework\Assert;

trait TracksAssertions
{
    /**
     * Increment PHPUnit's assertion counter when available.
     *
     * Safe in Pest and PHPUnit; no-op outside test environments.
     */
    protected static function bumpAssertionCount(): void
    {
        if (class_exists(Assert::class)) {
            // No-op assertion that still increments the assertion counter
            Assert::assertTrue(true);
        }
    }

}