<?php

namespace App\Events;

use App\Models\OutboxEvent;

class DomainEventDispatched
{
    public function __construct(
        public readonly OutboxEvent $event,
    ) {}
}
