<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PrintJob extends Model
{
    use HasUuids;

    protected $fillable = [
        'store_id',
        'branch_id',
        'print_station_id',
        'document_type',
        'document_id',
        'template',
        'template_version',
        'payload',
        'paper_width_mm',
        'copies',
        'status',
        'attempts',
        'expires_at',
        'claimed_at',
        'printed_at',
        'last_error',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'expires_at' => 'datetime',
            'claimed_at' => 'datetime',
            'printed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<PrintStation, $this> */
    public function station(): BelongsTo
    {
        return $this->belongsTo(PrintStation::class, 'print_station_id');
    }

    /** @return HasMany<PrintEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(PrintEvent::class);
    }
}
