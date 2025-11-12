<?php

namespace Pillar\Event;

interface PublicationPolicy
{
    public function shouldPublish(object $event): bool;
}