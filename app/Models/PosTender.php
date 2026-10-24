<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $amount
 * @property CarbonImmutable|null $due_date
 * @property CarbonImmutable|null $resolved_at
 */
class PosTender extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'check_received' => 'boolean',
            'due_date' => 'immutable_date',
            'resolved_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<PosSale, $this> */
    public function sale(): BelongsTo
    {
        return $this->belongsTo(PosSale::class, 'pos_sale_id');
    }
}
