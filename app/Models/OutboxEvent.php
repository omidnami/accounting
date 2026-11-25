<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $event_name
 * @property string $aggregate_type
 * @property int $aggregate_id
 * @property array<string, mixed> $payload
 * @property CarbonImmutable $occurred_at
 * @property CarbonImmutable $available_at
 * @property string $status
 * @property int $attempts
 * @property CarbonImmutable|null $processed_at
 * @property string|null $last_error
 */
class OutboxEvent extends Model
{
    protected $table = 'domain_events_outbox';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'id',
        'event_name',
        'aggregate_type',
        'aggregate_id',
        'payload',
        'occurred_at',
        'available_at',
        'status',
        'attempts',
        'processed_at',
        'last_error',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'occurred_at' => 'immutable_datetime',
            'available_at' => 'immutable_datetime',
            'processed_at' => 'immutable_datetime',
            'attempts' => 'integer',
        ];
    }
}
