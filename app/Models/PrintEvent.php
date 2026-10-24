<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PrintEvent extends Model
{
    protected $fillable = [
        'print_job_id',
        'print_station_id',
        'event',
        'message',
        'meta',
    ];

    protected function casts(): array
    {
        return ['meta' => 'array'];
    }

    /** @return BelongsTo<PrintJob, $this> */
    public function job(): BelongsTo
    {
        return $this->belongsTo(PrintJob::class, 'print_job_id');
    }
}
