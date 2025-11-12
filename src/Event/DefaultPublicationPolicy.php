<?php

namespace Pillar\Event;

final class DefaultPublicationPolicy implements PublicationPolicy
{
    public function shouldPublish(object $event): bool
    {
        if (EventContext::isReplaying()) {
            return false;
        }

        return $event instanceof ShouldPublish;
    }
}