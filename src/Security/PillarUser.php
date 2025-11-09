<?php

declare(strict_types=1);

namespace Pillar\Security;

interface PillarUser
{
    /**
     * Return true if this user is allowed to access the Pillar UI.
     */
    public function canAccessPillar(): bool;
}