<?php

declare(strict_types=1);

namespace Pillar\Security;

trait HasPillarAccess
{
    public function canAccessPillar(): bool
    {
        return true;
    }
}