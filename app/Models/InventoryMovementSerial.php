<?php

namespace App\Models;

use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryMovementSerial extends Model
{
    protected $fillable = [
        'inventory_movement_id',
        'product_serial_id',
    ];

    /** @return BelongsTo<InventoryMovement, $this> */
    public function movement(): BelongsTo
    {
        return $this->belongsTo(InventoryMovement::class, 'inventory_movement_id');
    }

    /** @return BelongsTo<ProductSerial, $this> */
    public function serial(): BelongsTo
    {
        return $this->belongsTo(ProductSerial::class, 'product_serial_id');
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new DomainException('تخصیص سریال append-only است.'));
        static::deleting(fn () => throw new DomainException('تخصیص سریال append-only است.'));
    }
}
