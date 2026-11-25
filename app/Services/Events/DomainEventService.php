<?php

namespace App\Services\Events;

use App\Models\OutboxEvent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class DomainEventService
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function record(string $eventName, Model $aggregate, array $payload = []): OutboxEvent
    {
        $now = now();

        return OutboxEvent::query()->create([
            'id' => (string) Str::uuid(),
            'event_name' => $eventName,
            'aggregate_type' => $aggregate->getMorphClass(),
            'aggregate_id' => $aggregate->getKey(),
            'payload' => $payload,
            'occurred_at' => $now,
            'available_at' => $now,
            'status' => 'pending',
            'attempts' => 0,
        ]);
    }
}
