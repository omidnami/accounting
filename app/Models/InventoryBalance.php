<?php

namespace App\Models;

use DomainException;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryBalance extends Model
{
    use HasFactory;

    protected $fillable = [
        'store_id',
        'branch_id',
        'warehouse_id',
        'product_id',
        'quantity',
        'average_cost',
        'inventory_value',
        'last_movement_at',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'average_cost' => 'decimal:4',
            'inventory_value' => 'decimal:2',
            'last_movement_at' => 'datetime',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function isAvailable(string|int|float $quantity): bool
    {
        return bccomp(
            (string) $this->quantity,
            (string) $quantity,
            3,
        ) >= 0;
    }

    protected static function booted(): void
    {
        static::saving(function (InventoryBalance $balance): void {
            if (bccomp((string) $balance->quantity, '0', 3) < 0) {
                throw new DomainException('موجودی منفی مجاز نیست.');
            }

            if (bccomp((string) $balance->inventory_value, '0', 2) < 0) {
                throw new DomainException('ارزش موجودی نمی‌تواند منفی باشد.');
            }
        });
    }
}
